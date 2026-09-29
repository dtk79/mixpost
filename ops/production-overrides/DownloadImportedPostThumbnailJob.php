<?php

namespace Inovector\Mixpost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Concerns\Job\OnMediaQueue;
use Inovector\Mixpost\Contracts\QueueWorkspaceAware;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\Support\MediaFilesystem;
use Inovector\Mixpost\Support\RemoteFileDownloader;
use Inovector\Mixpost\Util;

class DownloadImportedPostThumbnailJob implements QueueWorkspaceAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use OnMediaQueue;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  array<string, string>  $thumbnails  [provider_post_id => thumbnail_url]
     */
    public function __construct(
        public string $accountUuid,
        public array $thumbnails,
    ) {
        $this->onQueue($this->viaQueue());
    }

    public function handle(): void
    {
        $account = Account::where('uuid', $this->accountUuid)->first();

        if (! $account) {
            return;
        }

        $workspace = WorkspaceManager::current();
        $diskName = Util::config('disk');

        foreach ($this->thumbnails as $providerPostId => $url) {
            if (empty($url)) {
                continue;
            }

            try {
                $identity = hash('sha256', (string) $providerPostId);
                Cache::lock('mixpost:imported-thumbnail:'.$workspace->uuid.':'.$this->accountUuid.':'.$identity, 360)
                    ->get(function () use ($account, $workspace, $providerPostId, $url, $diskName, $identity) {
                        $post = ImportedPost::where('account_id', $account->id)
                            ->where('provider_post_id', $providerPostId)->first();

                        // A stale queued job must not create an orphan for a deleted post.
                        if (! $post || ImportedPost::isDownloadedThumbnail($post->thumbnail)) {
                            return;
                        }

                        $existing = $post->thumbnail;
                        $path = 'imported/'.$workspace->uuid.'/'.$this->accountUuid.'/'.$identity.'.jpg';

                        // Reuse a prior successful write if the database update was interrupted.
                        if (! Storage::disk($diskName)->exists($path)) {
                            $file = RemoteFileDownloader::make($url)->download();
                            try {
                                if (! MediaFilesystem::copyToDisk($diskName, $path, $file->filepath)) {
                                    throw new \RuntimeException('Imported thumbnail write failed');
                                }
                            } finally {
                                $file->temporaryDirectory->delete();
                            }
                        }

                        ImportedPost::where('account_id', $account->id)
                            ->where('provider_post_id', $providerPostId)
                            ->where('thumbnail', $existing)
                            ->update(['thumbnail' => $path]);
                    });
            } catch (\Throwable) {
                continue;
            }
        }
    }

}
