# Post Failure Email Notifications

Status: versioned production overrides, ready for deployment from the Infra dashboard after its managed-override deployer is current.

When a publishing batch finishes with one or more failed destinations, Mixpost sends one email to `socials@ducatix.com`. The email lists every failed social account, gives a sanitized explanation from the stored provider response, and links directly to the failed post in its workspace.

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
node -e 'const m=require("./ops/production-overrides/deployment-manifest.json"); if(m.schemaVersion!==1 || m.overrides.length!==3) process.exit(1)'
```

After an authorized dashboard deployment, confirm exactly one email reaches `socials@ducatix.com` from a controlled failed publishing attempt, the subject identifies the post, every failed destination has a useful sanitized explanation, and the button opens the correct Mixpost workspace and post. Do not retry a real failed destination solely to test the email.
