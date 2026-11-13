<?php

namespace App\Console\Commands;

use App\Support\InstagramStoryCapture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Workspace;
use Throwable;

final class CaptureInstagramStories extends Command
{
    protected $signature = 'peachy:capture-instagram-stories';
    protected $description = 'Preserve live Instagram Story images and optional videos for Annex';

    public function handle(InstagramStoryCapture $capture): int
    {
        if (! config('peachy-stories.enabled', true)) {
            return self::SUCCESS;
        }
        // Manual and scheduled invocations share this lock. Each run has a
        // 45-minute budget, leaving room for the final bounded request/storage PUT.
        $lock = Cache::lock('peachy:instagram-story-capture', 3600);
        if (! $lock->get()) {
            $this->info('Instagram Story capture is already running.');
            return self::SUCCESS;
        }
        $deadline = microtime(true) + 2700;
        $failed = false;
        try {
            foreach (Workspace::query()->orderBy('id')->cursor() as $workspace) {
                if (! $workspace->valid()) {
                    continue;
                }
                $stop = $workspace->execute(function () use ($workspace, $capture, $deadline, &$failed): bool {
                    foreach (Account::query()->whereIn('provider', ['instagram', 'instagram_standalone'])
                        ->where('authorized', true)->orderBy('id')->cursor() as $account) {
                        if (microtime(true) >= $deadline) {
                            $failed = true;
                            return true;
                        }
                        try {
                            if (! $account->isServiceActive()) {
                                continue;
                            }
                            $result = $capture->captureAccount($account, $workspace, $deadline);
                            $failed = $failed || $result['errors'] > 0;
                            Log::info('peachy.instagram_story_capture', ['account_id' => $account->id,
                                'workspace_id' => $workspace->id] + $result);
                            $this->line(json_encode(['account_id' => $account->id] + $result));
                            if ($result['status'] === 'permission_missing') {
                                $this->error('Story access requires a permission this connection does not have. Capture stopped.');
                                return true;
                            }
                        } catch (Throwable $error) {
                            // Provider/transport exceptions may contain signed URLs or tokens.
                            $failed = true;
                            Log::warning('peachy.instagram_story_account_failed', [
                                'account_id' => $account->id, 'exception_class' => $error::class,
                            ]);
                            $this->error("Story capture failed for account {$account->id}; see sanitized application log.");
                        }
                    }
                    return false;
                });
                if ($stop) {
                    break;
                }
            }
        } finally {
            $lock->release();
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
