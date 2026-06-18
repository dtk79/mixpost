# Production Runbook

This repo is the public Mixpost Lite codebase, but the production instance runs Mixpost Pro Team from Docker on the Hetzner host.

Use this runbook for operational context only. Production-specific bind mounts and image versions may differ from this Lite repository.

## Production Host

- SSH alias: `mixpost-hetzner`
- Host: `root@65.109.229.99`
- App directory: `/root/mixpost`
- Compose file: `/root/mixpost/docker-compose.yml`
- Main app container: `mixpost-mixpost-1`
- Production image: `inovector/mixpost-pro-team:latest`
- Observed package version: `inovector/mixpost-pro-team 5.4.3`
- Observed image digest: `sha256:3adae2742a0b255b766b27edc89e990c4bf42d0deb93ab21a775ca63ae876f02`

## Fast Health Checks

SSH to the production host:

```bash
ssh mixpost-hetzner
```

Check container state:

```bash
docker compose -f /root/mixpost/docker-compose.yml ps
```

Check recent app logs:

```bash
docker compose -f /root/mixpost/docker-compose.yml logs --tail=200 mixpost
```

Check the package version inside the app container:

```bash
docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && composer show inovector/mixpost-pro-team --no-interaction"
```

Check PHP syntax for a host-mounted override before restarting:

```bash
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php
```

## Production Overrides

The compose stack bind-mounts several local files into the Mixpost container as read-only overrides. These files are not part of this local Lite repo and should be treated as production-specific patches.

Track durable override intent, acceptance checks, and upstream-removal criteria in [Mixpost Instance Alterations](mixpost-instance-alterations.md). Review that register before every Mixpost image update.

- `/root/mixpost/Schedule.php`
- `/root/mixpost/TrustProxies.php`
- `/root/mixpost/ManagesTwitterJobs.php`
- `/root/mixpost/ManagesBlueskyJobs.php`
- `/root/mixpost/ManagesInstagramJobs.php`
- `/root/mixpost/InstagramAnalytics.php`
- `/root/mixpost/ManagesInstagramResources.php`
- `/root/mixpost/ImportInstagramMediaJob.php`
- `/root/mixpost/uploads.ini`
- `/root/mixpost/app.blade.php` if Google Analytics needs to be preserved across Docker image updates

Before editing an override, copy a timestamped backup:

```bash
cp /root/mixpost/Schedule.php /root/mixpost/Schedule.php.bak.$(date +%Y%m%d-%H%M%S)
```

After editing an override, lint the mounted file in the container and restart the app:

```bash
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php
docker compose -f /root/mixpost/docker-compose.yml restart mixpost
```

## Google Analytics

Google Analytics is opt-in and controlled by:

```text
MIXPOST_GOOGLE_ANALYTICS_ID=G-XXXXXXXXXX
```

The app template only emits the GA script when this variable is set. The Vue/Inertia app also sends page-view events after client-side navigation so Mixpost route changes are tracked without full page reloads.

Because production runs from the `inovector/mixpost-pro-team` Docker image, keep the tracking ID in the compose environment and preserve the Blade template as a host-mounted override if the image does not include this patch:

```yaml
environment:
  MIXPOST_GOOGLE_ANALYTICS_ID: G-XXXXXXXXXX
volumes:
  - /root/mixpost/app.blade.php:/var/www/html/vendor/inovector/mixpost-pro-team/resources/views/app.blade.php:ro
```

Restart after changing the environment or override:

```bash
ssh mixpost-hetzner 'docker compose -f /root/mixpost/docker-compose.yml restart mixpost'
```

Verify the environment value inside the container:

```bash
ssh mixpost-hetzner 'docker exec mixpost-mixpost-1 printenv MIXPOST_GOOGLE_ANALYTICS_ID'
```

Verify Mixpost routes are registered after the restart:

```bash
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan route:list --path=mixpost"'
```

Then load the Mixpost UI in a browser and confirm the rendered shell contains the GA script only when the measurement ID is configured. Client-side navigation should send `page_view` events. The Lite app tracks Inertia navigation from `resources/js/app.js` when `window.gtag` is available.

## Scheduler And Queues

The Lite scheduler in this repository registers:

- `mixpost:run-scheduled-posts` every minute.
- `mixpost:import-account-data` every 30 minutes for low-cost providers and every 2 hours for cost-controlled providers.
- `mixpost:import-account-audience` every 30 minutes for low-cost providers and every 3 hours for cost-controlled providers.
- `mixpost:process-metrics` every 30 minutes for low-cost providers and every 3 hours for cost-controlled providers.
- `mixpost:delete-old-data` daily.
- `mixpost:prune-temporary-directory` hourly.

Production Pro Team may add providers or overrides. When API costs change, inspect the mounted schedule and provider job traits before changing intervals.

Production also mounts a custom Pro Team scheduler override at `/root/mixpost/Schedule.php`, mirrored locally at `ops/production-overrides/Schedule.php`. As of 2026-06-12, that override adds a dedicated low-cost post analytics callback every 30 minutes:

- Instagram and Instagram standalone accounts dispatch stories, total-value insights, media/post insights, and audience jobs.
- Facebook Page accounts import page posts and then chain post-insight imports.
- YouTube accounts import channel videos and video statistics.
- The generic `medium` provider-job bucket remains every 3 hours so other providers are not accelerated.
- Twitter/X post analytics remain on the separate daily/monthly cost-controlled cadence.

Verify the production schedule after changing the override:

```bash
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan schedule:list | grep low-cost-post-analytics-30min"'
```

Expected schedule row:

```text
*/30 * * * *  <workspace> - mixpost:low-cost-post-analytics-30min
```

Useful manual commands:

```bash
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan mixpost:run-scheduled-posts"'
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan mixpost:import-account-audience --providers=twitter"'
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan mixpost:process-metrics --providers=twitter"'
```

## X API Cost Mitigation

Date: 2026-05-11

Problem: Mixpost was running Twitter/X account analytics jobs every 3 hours. With 14 authorized Twitter accounts, Horizon logs showed each 3-hour burst running about 14 follower jobs and 30 post timeline jobs. The post timeline job calls `GET users/{id}/tweets` with `max_results=100` and metrics fields, then paginates when X returns `next_token`.

Before:

- `ImportTwitterFollowersJob` ran as high priority every 3 hours.
- `ImportTwitterPostsJob` ran as high priority every 3 hours.
- Approximate scheduled X read volume: 44 jobs every 3 hours, about 352 per day.

Change:

- Kept `ImportTwitterFollowersJob` in `highPriorityJobs()` so follower counts continue updating every 3 hours.
- Moved `ImportTwitterPostsJob` to `dailyPriorityJobs()` so timeline/post analytics update daily.

Follow-up changes:

Date: 2026-06-05

The X console still showed roughly $6/day of post-object cost after the daily-only timeline import change. Horizon and database history showed that the cheaper June 1 cost aligned with fewer `ImportTwitterPostsJob` runs and fewer imported Twitter post-insight rows, not fewer published X posts.

- `ImportTwitterFollowersJob` remains in `highPriorityJobs()` so follower counts continue updating.
- `ImportTwitterPostsJob` remains in `initialJobs()` so newly connected accounts still get initial post/content analytics.
- `ImportTwitterPostsJob` is not in `dailyPriorityJobs()`.
- `Schedule.php` adds custom Twitter post analytics callbacks for each workspace.
- Active X accounts run daily at `0 0 * * *`.
- Inactive X accounts run monthly at `0 0 1 * *`.
- Active means at least 3 published X posts in the last 30 days, or at least 1 published X post in the last 14 days.
- Publishing to X is unchanged.

Changed file:

```text
/root/mixpost/ManagesTwitterJobs.php
/root/mixpost/Schedule.php
```

Backup created before the change:

```text
/root/mixpost/ManagesTwitterJobs.php.bak.20260511-033517
```

Verification:

```bash
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php"' <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$class = Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider::class;
echo "high=".implode(',', $class::highPriorityJobs()).PHP_EOL;
echo "daily=".implode(',', $class::dailyPriorityJobs()).PHP_EOL;
PHP
```

Expected output:

```text
high=Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterFollowersJob
daily=
```

Expected schedule row:

```text
0 0 * * *  <workspace> - mixpost:twitter-post-analytics-daily
0 0 1 * *  <workspace> - mixpost:twitter-post-analytics-monthly
```

Manual X post/content analytics updates:

Future reminder: when investigating X analytics costs, stale X post/content metrics, or active/inactive account cadence, suggest these scripts before changing scheduler code. Run with `DRY_RUN=1` first to preview the selected accounts without dispatching jobs.

```bash
DRY_RUN=1 ops/scripts/update-active-x-accounts.sh
DRY_RUN=1 ops/scripts/update-inactive-x-accounts.sh
DRY_RUN=1 ops/scripts/update-all-x-accounts.sh

ops/scripts/update-active-x-accounts.sh
ops/scripts/update-inactive-x-accounts.sh
ops/scripts/update-all-x-accounts.sh
```

Rollback:

```bash
ssh mixpost-hetzner 'cp /root/mixpost/Schedule.php.bak.20260605-active-daily-inactive-monthly /root/mixpost/Schedule.php && docker compose -f /root/mixpost/docker-compose.yml restart mixpost'
```

Rollback to the earlier daily-post-analytics state:

```bash
ssh mixpost-hetzner 'cp /root/mixpost/ManagesTwitterJobs.php.bak.20260511-033517 /root/mixpost/ManagesTwitterJobs.php && docker compose -f /root/mixpost/docker-compose.yml restart mixpost'
```

Restart after future override edits:

```bash
ssh mixpost-hetzner 'docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesTwitterJobs.php && docker compose -f /root/mixpost/docker-compose.yml restart mixpost'
```

## Media Storage Migration

The Lite repository includes `mixpost:migrate-media-storage` for moving Mixpost media files between configured Laravel filesystem disks. In production, run a dry run first and confirm both disks are correctly configured in the container environment.

Dry run:

```bash
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan mixpost:migrate-media-storage --from=public --to=s3 --dry-run"'
```

Migrate files and keep the source copy:

```bash
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan mixpost:migrate-media-storage --from=public --to=s3"'
```

Only use `--delete-source` after validating the target disk and recent media URLs.
