<?php

namespace Inovector\Mixpost\Jobs\Inbox;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Concerns\Job\OnInboxQueue;
use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Contracts\ImportsPost;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\Models\Inbox\Conversation;
use Inovector\Mixpost\SocialProviders\Twitter\Enums\TwitterPostInsightType;
use Inovector\Mixpost\SocialProviders\Twitter\Models\TwitterPostInsight;
use Inovector\Mixpost\SocialProviders\Twitter\Models\TwitterPostInsightHistory;

/**
 * Stores the fetched post under the SAME provider_post_id the conversation references, so providers
 * whose engagement and analytics ids differ (e.g. LinkedIn activity vs share URNs) still resolve a
 * preview — for this conversation and later comments on the same post.
 */
class ImportEngagedPostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use OnInboxQueue;
    use Queueable;
    use SerializesModels;
    use UsesSocialProviderManager;

    public function __construct(public int $accountId, public string $providerPostId)
    {
        $this->onQueue($this->viaQueue());
    }

    public function uniqueId(): string
    {
        return "import-engaged-post:{$this->accountId}:{$this->providerPostId}";
    }

    public function handle(): void
    {
        $account = Account::withoutWorkspace()->find($this->accountId);

        if (! $account || $account->isUnauthorized()) {
            return;
        }

        WorkspaceManager::setCurrent($account->workspace);

        if ($importedPostId = $this->existingImportedPostId($account->id)) {
            $this->linkConversations($account->id, $importedPostId);

            return;
        }

        $provider = $this->connectProvider($account);

        if (! $provider instanceof ImportsPost) {
            return;
        }

        $post = $provider->fetchPost($this->providerPostId);

        if ($post === null) {
            return;
        }

        $isTwitter = $account->provider === 'twitter';
        $authorId = $isTwitter ? (string) ($post['author_id'] ?? '') : '';
        $isOwnedTwitterPost = $isTwitter && $authorId !== '' && $authorId === (string) $account->provider_id;

        DB::transaction(function () use ($account, $post, $isTwitter, $authorId, $isOwnedTwitterPost) {
            $importedPost = ImportedPost::create([
                'account_id' => $account->id,
                'provider_post_id' => $this->providerPostId,
                'text' => $post['text'] ?? '',
                'url' => $post['url'] ?? '',
                'thumbnail' => $post['thumbnail'] ?? null,
                'content_type' => $post['content_type'] ?? 'text',
                'data' => $isTwitter ? [
                    'source' => 'inbox_engagement',
                    'author_id' => $authorId ?: null,
                    'analytics_visible' => $isOwnedTwitterPost,
                ] : null,
                'created_at' => $isTwitter && ! empty($post['created_at'])
                    ? Carbon::parse($post['created_at'], 'UTC')
                    : Carbon::now(),
            ]);

            if ($isOwnedTwitterPost && isset($post['public_metrics'])) {
                $this->storeTwitterPublicMetrics($account, $post['public_metrics']);
            }

            $this->linkConversations($account->id, $importedPost->id);
        });
    }

    private function storeTwitterPublicMetrics(Account $account, object $publicMetrics): void
    {
        $types = [
            TwitterPostInsightType::IMPRESSION_COUNT->value => 'impression_count',
            TwitterPostInsightType::LIKE_COUNT->value => 'like_count',
            TwitterPostInsightType::RETWEET_COUNT->value => 'retweet_count',
            TwitterPostInsightType::REPLY_COUNT->value => 'reply_count',
            TwitterPostInsightType::QUOTE_COUNT->value => 'quote_count',
            TwitterPostInsightType::BOOKMARK_COUNT->value => 'bookmark_count',
        ];
        $current = [];
        $history = [];
        $now = Carbon::now('UTC');

        foreach ($types as $type => $field) {
            if (! isset($publicMetrics->{$field})) {
                continue;
            }

            $common = [
                'workspace_id' => $account->workspace_id,
                'account_id' => $account->id,
                'provider_post_id' => $this->providerPostId,
                'type' => $type,
                'value' => (int) $publicMetrics->{$field},
            ];
            $current[] = $common + ['updated_at' => $now];
            $history[] = $common + ['date' => $now->toDateString()];
        }

        if (! $current) {
            return;
        }

        TwitterPostInsight::upsert(
            $current,
            ['workspace_id', 'account_id', 'provider_post_id', 'type'],
            ['value', 'updated_at']
        );
        TwitterPostInsightHistory::upsert(
            $history,
            ['workspace_id', 'account_id', 'provider_post_id', 'type', 'date'],
            ['value']
        );
    }

    protected function existingImportedPostId(int $accountId): ?int
    {
        return ImportedPost::query()
            ->where('account_id', $accountId)
            ->where('provider_post_id', $this->providerPostId)
            ->value('id');
    }

    protected function linkConversations(int $accountId, int $importedPostId): void
    {
        Conversation::withoutWorkspace()
            ->where('account_id', $accountId)
            ->where('provider_post_id', $this->providerPostId)
            ->whereNull('imported_post_id')
            ->update(['imported_post_id' => $importedPostId]);
    }
}
