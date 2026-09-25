# Post Failure Email Notifications

Status: active in production; runtime mounts and checksums verified at repair commit `1854fa1` on September 25, 2026. A real queued failure alert was accepted by the mail relay. Inbox receipt is separately unconfirmed.

When a publishing batch finishes with one or more failed destinations, Mixpost sends one email to `socials@ducatix.com`. The email lists every failed social account, gives a sanitized explanation from the stored provider response, and links directly to the failed post in its workspace.

This applies to every provider, not only Instagram. The shared batch finalizer checks destination errors and failed publishing jobs without a provider filter. Regression verification against the running package covered all 14 registered provider types: X, Facebook Pages, Instagram, standalone Instagram, Threads, Mastodon, Pixelfed, YouTube, Google Business Profile, Pinterest, LinkedIn profiles and pages, TikTok, and Bluesky. It also covered mixed successful/failed destinations, multiple failures in one email, expired connections, disabled services, and exhausted jobs without a normal provider response. These checks use fake notifications and do not send test emails or publish content.

The notification is triggered only after the full publishing batch finishes. This avoids duplicate emails when several destinations fail and covers failures recorded by provider responses, disabled services, expired account connections, video-preparation timeouts, and exhausted publishing jobs. Laravel invokes a batch's final callback before the exhausted job's `failed()` hook stores its fallback explanation, so the notification waits ten seconds before rendering. It also treats the batch's own failed-job count as a failed post instead of incorrectly marking an exception-only batch as published. Dispatching the email is isolated from post status handling, so a mail-queue problem cannot prevent the post from being marked failed.

## Update-safe deployment

`ops/production-overrides/deployment-manifest.json` is the source of truth for the files introduced by this customization. The Infra dashboard's dedicated Mixpost deployer reads that manifest from the committed default branch, backs up the active host overrides and Compose configuration, installs the declared files under `/root/mixpost`, and generates a read-only Compose overlay before recreating only the Mixpost container.

The vendor image may replace any path inside `/var/www/html` during an upgrade, but these host bind mounts are applied afterward and therefore continue to shadow the matching vendor files. Every Mixpost upgrade must still compare each override with the new upstream implementation and rebase or retire it when method signatures or native behavior change.

The deployer preserves all pre-existing manual mounts. It manages only the entries declared in this manifest. A failed verification restores both the previous image and the backed-up managed override files; MySQL and Redis are never recreated.

## Verification

```bash
php ops/production-overrides/tests/PostFailureExplanationTest.php
php -l ops/production-overrides/PostFailureExplanation.php
php -l ops/production-overrides/PostPublishingFailedNotification.php
php -l ops/production-overrides/PublishPost.php
node -e 'const m=require("./ops/production-overrides/deployment-manifest.json"); const names=new Set(m.overrides.map(x=>x.host)); if(m.schemaVersion!==1 || !["PublishPost.php","PostPublishingFailedNotification.php","PostFailureExplanation.php"].every(x=>names.has(x))) process.exit(1)'
```

After an authorized dashboard deployment, confirm exactly one email reaches `socials@ducatix.com` from a controlled failed publishing attempt, the subject identifies the post, every failed destination has a useful sanitized explanation, and the button opens the correct Mixpost workspace and post. Do not retry a real failed destination solely to test the email.

## September 25 incident and activation gate

Post 1892 (Instagram destination 3190, account 85) failed at 13:00 UTC with
2207082. The live container had none of the three notification mounts above and
was running the vendor batch finalizer, which does not send this notification.
A healthy app or a shipped Git commit does not establish notification readiness.

The same media 782 `social_video` derivative was present before scheduling and
verified as full-length 40.8-second 1080x1920 H.264, yuv420p, 30 FPS, AAC 48 kHz
stereo. A diagnostic upload of those unchanged bytes reached Instagram FINISHED;
it was not published. The first upload's container ID was not retained, so its
underlying Meta-side cause cannot be recovered from the saved error alone.

The publishing worker now retries 2207082 at most twice, after 60 and 120 seconds,
only when the response identifies a terminal ERROR from container processing.
It never retries publication errors or uncertain, pending, expired, or published
containers. Successful destinations retain their existing provider-ID guard.
Container polling is bounded and only FINISHED can reach `media_publish`.
Container IDs/statuses and retry counts are logged; access tokens are not logged.

The managed manifest now includes the live video-preparation action, publishing
job, unique conversion job, conversion settings, and Instagram adapter. This
preserves the previously server-only preparation fix across managed deployments.

Before declaring this repair active:

1. Deploy the reviewed manifest and verify every required read-only mount and
   checksum against the exact source revision.
2. Check Horizon, app health, and failed jobs after app recreation.
3. With approval to send, queue the notification for the existing failed post
   without invoking publishing. Verify the queue completes and relay accepts it;
   separately confirm inbox receipt. Relay acceptance alone is not inbox receipt.
4. Keep post 1892 and its successful provider IDs unchanged unless separately
   authorized to recover its failed Instagram destination.

Regression scripts use installed Pro dependencies with fake HTTP/queues/models;
they do not publish or send emails:

- `InstagramContainerTest.php`: terminal diagnostics, unsafe-state guards,
  bounded polling, and publication only after readiness.
- `SocialVideoPreflightTest.php`: video preparation, retry limits, unknown/error
  exclusions, successful recovery, and existing publication guards.
- `PostFailureNotificationTest.php`: full-batch finalization, partial destination
  failures, exhausted jobs, successful silence, destination, delay, queue,
  explanation, and exact post link.
- `SocialVideoDurationTest.php`: full-length 91-second conversion fixture.
