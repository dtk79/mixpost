# Production Runbook

This repo is the public Mixpost Lite codebase, but the production instance runs Mixpost Pro Team from Docker on the Hetzner host.

## Production Host

- SSH alias: `mixpost-hetzner`
- Host: `root@65.109.229.99`
- App directory: `/root/mixpost`
- Compose file: `/root/mixpost/docker-compose.yml`
- Main app container: `mixpost-mixpost-1`
- Production image: `inovector/mixpost-pro-team:latest`
- Observed package version: `inovector/mixpost-pro-team 5.3.0`
- Observed image digest: `sha256:3adae2742a0b255b766b27edc89e990c4bf42d0deb93ab21a775ca63ae876f02`

## Production Overrides

The compose stack bind-mounts several local files into the Mixpost container as read-only overrides. These files are not part of this local Lite repo and should be treated as production-specific patches.

- `/root/mixpost/Schedule.php`
- `/root/mixpost/TrustProxies.php`
- `/root/mixpost/ManagesTwitterJobs.php`
- `/root/mixpost/ManagesBlueskyJobs.php`
- `/root/mixpost/ManagesInstagramJobs.php`
- `/root/mixpost/uploads.ini`
- `/root/mixpost/app.blade.php` if Google Analytics needs to be preserved across Docker image updates

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

Changed file:

```text
/root/mixpost/ManagesTwitterJobs.php
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
daily=Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob
```

Rollback:

```bash
ssh mixpost-hetzner 'cp /root/mixpost/ManagesTwitterJobs.php.bak.20260511-033517 /root/mixpost/ManagesTwitterJobs.php && docker compose -f /root/mixpost/docker-compose.yml restart mixpost'
```

Restart after future override edits:

```bash
ssh mixpost-hetzner 'docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesTwitterJobs.php && docker compose -f /root/mixpost/docker-compose.yml restart mixpost'
```
