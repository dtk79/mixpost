<?php

namespace Inovector\Mixpost\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Inovector\Mixpost\Concerns\Mail;
use Inovector\Mixpost\Contracts\QueueWorkspaceAware;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Support\PostFailureExplanation;

class PostPublishingFailedNotification extends Notification implements QueueWorkspaceAware, ShouldQueue
{
    use Mail, Queueable, SerializesModels;

    public $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Post $post,
        public readonly bool $batchHadFailures = false,
    ) {}

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->post->workspace !== null && ($this->batchHadFailures || $this->post->hasErrors());
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $post = $this->post->fresh(['accounts', 'workspace']) ?? $this->post->loadMissing(['accounts', 'workspace']);
        $postUrl = url(route('mixpost.posts.edit', [
            'workspace' => $post->workspace->uuid,
            'post' => $post->uuid,
        ], false));
        $message = $this->mailNotificationMessage()
            ->error()
            ->subject('Social post failed to publish ['.substr($post->uuid, 0, 8).']')
            ->greeting('A social post failed to publish')
            ->line('Mixpost finished the publishing attempt, but one or more social accounts reported a failure.');

        foreach ($this->failureLines($post) as $failureLine) {
            $message->line($failureLine);
        }

        return $message
            ->line('Open the post to review its content and destination details before retrying.')
            ->action('View failed post', $postUrl);
    }

    private function failureLines(Post $post): array
    {
        $lines = $post->accounts
            ->filter(static fn (Account $account): bool => $account->pivot->errors !== null)
            ->map(function (Account $account): string {
                $identity = $account->name ?: $account->username ?: 'Unnamed account';

                if ($account->username && $account->username !== $identity) {
                    $identity .= ' (@'.ltrim($account->username, '@').')';
                }

                return $account->providerName().' — '.$identity.': '.PostFailureExplanation::from($account->pivot->errors);
            })
            ->values()
            ->all();

        if ($lines) {
            return $lines;
        }

        return [PostFailureExplanation::from([])];
    }
}
