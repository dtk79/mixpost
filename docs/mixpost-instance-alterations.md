# Mixpost Instance Alterations

This document tracks production-specific changes made to the Peachy HQ Mixpost instance that are outside the upstream `inovector/mixpost-pro-team` Docker image.

The production instance runs Mixpost Pro Team from Docker on `mixpost-hetzner`. Host files under `/root/mixpost` are bind-mounted into the container as read-only overrides. These survive image updates, but they can also shadow upstream fixes, so every Mixpost upgrade should compare this register against the new image source.

## Upgrade Review Checklist

Before upgrading Mixpost:

1. Review this file and `/root/mixpost/docker-compose.yml`.
2. Pull the new image without replacing host overrides.
3. Compare every mounted override against the same file in the new image.
4. Remove an override if upstream now includes the behavior we need.
5. Rebase the override if upstream changed method signatures, imports, models, queue behavior, or response shapes.
6. Lint mounted PHP files inside the container.
7. Restart Mixpost and verify the acceptance checks listed below.

Useful commands:

```bash
ssh mixpost-hetzner 'docker compose -f /root/mixpost/docker-compose.yml pull mixpost'
ssh mixpost-hetzner 'docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php'
ssh mixpost-hetzner 'docker compose -f /root/mixpost/docker-compose.yml restart mixpost'
```

## Mounted Override Inventory

| Host file | Container target | Purpose | Review trigger |
| --- | --- | --- | --- |
| `/root/mixpost/Schedule.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php` | Production scheduler customization. | Any upstream scheduler changes. |
| `/root/mixpost/TrustProxies.php` | `/var/www/html/app/Http/Middleware/TrustProxies.php` | Reverse proxy trust behavior for production networking. | Laravel or proxy stack changes. |
| `/root/mixpost/ManagesTwitterJobs.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesTwitterJobs.php` | Twitter/X analytics cost mitigation. | X API pricing/quota changes or upstream job changes. |
| `/root/mixpost/ManagesBlueskyJobs.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Bluesky/Concerns/ManagesBlueskyJobs.php` | Bluesky job cadence customization. | Upstream Bluesky job changes. |
| `/root/mixpost/ManagesInstagramJobs.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Concerns/ManagesInstagramJobs.php` | Instagram job cadence/custom import sequencing. | Upstream Instagram job changes. |
| `/root/mixpost/InstagramAnalytics.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Analytics/InstagramAnalytics.php` | Instagram short-range total-value fallback. | Upstream Instagram analytics changes. |
| `/root/mixpost/ManagesInstagramResources.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Concerns/ManagesInstagramResources.php` | Instagram media import can request basic media without embedded insights and includes same-day media. | Upstream Instagram resource/API changes. |
| `/root/mixpost/ImportInstagramMediaJob.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Jobs/ImportInstagramMediaJob.php` | Instagram media import falls back to basic media and skips unavailable per-media insights. | Upstream Instagram media import changes. |
| `/root/mixpost/app.blade.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/resources/views/layouts/app.blade.php` | Preserve Google Analytics shell injection. | Upstream layout or analytics support changes. |
| `/root/mixpost/uploads.ini` | PHP CLI/FPM config paths | Upload size/runtime config. | PHP image or upload policy changes. |

## Alteration Log

### 2026-06-18 - S3 Storage Migration Without Prefix Listing

Status: active.

Files:

- `/root/mixpost/MigrateStorage.php`
- `/root/mixpost/AppMigrateStorageCommand.php`

Local source mirror:

- `ops/production-overrides/MigrateStorage.php`
- `ops/production-overrides/AppMigrateStorageCommand.php`

Reason:

The Pro `mixpost:migrate-storage` command listed S3 prefixes during connection checks and workspace migration. Infra reported `ducati-mixpost: S3 prefix listing timed out`.

Target behavior:

- Migration copies media files from database paths instead of S3 prefix listings.
- S3 connection checks use a single object existence probe instead of listing directories.
- The app command wrapper registers the Pro migration command through Laravel's existing app command loader.

Acceptance checks:

- `php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Commands/MigrateStorage.php` passes inside the app container.
- `php artisan mixpost:migrate-storage public s3 --dry-run` starts without S3 prefix listing timeouts.

Remove/revisit when:

- Mixpost upstream migrates storage from database media paths without S3 listings.

### 2026-06-12 - Low-Cost Post Analytics Every 30 Minutes

Status: active.

Files:

- `/root/mixpost/Schedule.php`

Local source mirror:

- `ops/production-overrides/Schedule.php`

Reason:

Facebook, Instagram, and YouTube API reads do not have the same direct per-request cost pressure as X/Twitter, and same-day post counters can diverge noticeably from native app views when Mixpost only imports post analytics every several hours or daily.

Target behavior:

- Instagram content/post analytics refresh every 30 minutes using the same jobs that previously ran in the medium-priority bucket: stories, total-value insights, media/post insights, and audience.
- Facebook page post analytics refresh every 30 minutes by importing page posts and then post insights in a chain.
- YouTube post analytics refresh every 30 minutes by importing channel videos and video statistics.
- Existing daily Meta jobs remain in place for broader account, demographic, historical, and competitor data.

Implementation notes:

- `Schedule::scheduleLowCostPostAnalytics()` dispatches authorized and active `instagram`, `instagram_standalone`, `facebook_page`, and `youtube` accounts every 30 minutes.
- The generic `medium` account-job bucket remains every 3 hours so other providers are not accelerated unintentionally.
- X/Twitter post analytics remain on the custom cost-controlled cadence.

Acceptance checks:

- `php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php` passes inside the app container.
- `php artisan schedule:list` shows `<workspace> - mixpost:low-cost-post-analytics-30min` with `*/30 * * * *`.

Remove/revisit when:

- Meta API rate limits become a production issue.
- Mixpost upstream adds provider-specific scheduler controls.
- Product requirements need a different freshness/cost balance.

### 2026-06-05 - Twitter/X Post Analytics Disabled

Status: active.

Files:

- `/root/mixpost/ManagesTwitterJobs.php`

Reason:

X/Twitter timeline/post analytics jobs were producing avoidable API cost. Follower analytics remain enabled, but post/timeline import jobs are removed from initial and daily job lists.

Acceptance checks:

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

Expected result:

```text
high=Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterFollowersJob
daily=
```

Remove/revisit when:

- X API cost is no longer material.
- Mixpost upstream adds lower-cost post analytics import controls.
- Product requirements need X post-level analytics again.

### 2026-06-05 - Instagram Short Range Analytics and Media Import Resilience

Status: active.

Files:

- `/root/mixpost/InstagramAnalytics.php`
- `/root/mixpost/ManagesInstagramResources.php`
- `/root/mixpost/ImportInstagramMediaJob.php`

Local source mirrors:

- `ops/production-overrides/InstagramAnalytics.php`
- `ops/production-overrides/ManagesInstagramResources.php`
- `ops/production-overrides/ImportInstagramMediaJob.php`

Container targets:

- `/var/www/html/vendor/inovector/mixpost-pro-team/src/Analytics/InstagramAnalytics.php`
- `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Concerns/ManagesInstagramResources.php`
- `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Jobs/ImportInstagramMediaJob.php`

Compose backup:

- `/root/mixpost/docker-compose.yml.bak.20260605-instagram-analytics`

Reason:

Two Instagram analytics issues were observed:

- Account-level engagement/reach cards use 30-day total-value windows, but the analytics query only includes windows fully contained by the selected date range. Short ranges such as June 1-5, 2026 return zero even when overlapping 30-day windows and post insights exist.
- `instagram_standalone` media import can fail when Meta rejects embedded media insights for older media published before the account was most recently converted from personal to professional. The basic media list still works, but the combined `media + insights` request prevents content rows from being imported.

Target behavior:

- Short date ranges use the nearest available total-value window ending within or before the selected range when no full 30-day window is contained inside the range.
- Instagram media import should import basic media rows even when per-media insights are unavailable.
- Per-media insights should be fetched defensively and skipped per media item when Meta returns known insight availability errors.

Implementation notes:

- `InstagramAnalytics::getTotalValues()` keeps upstream behavior when it finds fully contained windows and falls back to `getNearestWindowValues()` only when none exist.
- `ManagesInstagramResources::getMedia()` accepts an `$includeInsights` flag. The default remains embedded insights for regular Instagram accounts. The media window uses `until = Carbon::tomorrow('UTC')` because Meta treats `until = today` as excluding same-day media.
- `ImportInstagramMediaJob` disables embedded insights for `instagram_standalone`, falls back to basic media if embedded insights return Meta availability error `100 / 2108006`, and skips per-media insight errors `10` or `100 / 2108006`.

Acceptance checks:

- `Rich Merritt / richmerrittauthor` Instagram engagement for June 1-5, 2026 returned nonzero cards: likes `2644`, comments `106`, shares `131`, saves `150`, replies `8`, reposts `77`; reach `14458`, views `47264`.
- `Dan Tastic / dtastical` Instagram engagement for June 1-5, 2026 returned nonzero cards: likes `50`, comments `1`, saves `2`, replies `8`; reach `484`, views `2326`.
- Running `ImportInstagramMediaJob` once for `Dan Tastic / dtastical` imported `21` media rows: `16` feed posts and `5` reels. Newest imported media was March 17, 2026, so the June 1-5 Content tab correctly remains empty for that account.
- Post-insight rows for `Dan Tastic / dtastical` increased to `42`; unavailable insight metrics were skipped per media item.
- Mounted override paths linted successfully inside `mixpost-mixpost-1`.
- Mixpost routes registered and Horizon reported running after recreating the app container.

Remove/revisit when:

- Mixpost upstream changes Instagram analytics date-window handling.
- Mixpost upstream separates Instagram media import from insight import.
- Meta changes the Instagram API with Instagram Login media insights behavior.

### 2026-06-05 - Same-Day Post Analytics Backfill

Status: one-time backfill completed; Facebook ID mismatch remains a future patch candidate.

Affected post:

- Workspace UUID: `54125e34-d611-47e7-893a-f55719f96b14`
- Post UUID: `0c582786-8e2f-4c43-b3cd-46fab42e9593`
- Published at: `2026-06-05 13:01:28 UTC` / `2026-06-05 06:01:28 America/Los_Angeles`

Reason:

The post analytics panel showed no data after the 6:00am Pacific post because the provider insight rows were not matched/imported yet:

- Facebook Reel publishing saved `122113157474884301`, but Facebook page post imports use the canonical page-post ID `971545159386094_122113157474884301`.
- Instagram media import used `until = today` in UTC, which excluded the June 5 Reel from Meta's `/media` response even though direct media insights were already available.
- YouTube video statistics were available immediately, but YouTube post imports only run in the daily provider job bucket.

Actions taken:

- Updated the Facebook `mixpost_post_accounts.provider_post_id` for account `84` to `971545159386094_122113157474884301`.
- Updated `ManagesInstagramResources::getMedia()` so same-day Instagram media is eligible for import.
- Deployed the updated Instagram resource override to production after backing up `/root/mixpost/ManagesInstagramResources.php` to `/root/mixpost/ManagesInstagramResources.php.bak.20260605-190019`.
- Ran `ImportInstagramMediaJob` for account `85`.
- Ran `ImportYoutubeVideosJob` for account `86`.
- Ran `ImportFacebookPagePostsJob` for account `84`.

Verification:

- `GetPostAnalytics` returned three account result sections for the post.
- Summary totals after backfill: views `239`, likes `10`, comments `0`, shares `0`, engagement rate `4.18%`.
- Facebook account `84`: media views `11`, reactions `2`.
- Instagram account `85`: views `121`, reach `101`, likes `7`, total interactions `7`.
- YouTube account `86`: views `6`, likes `1`.

Review/future patch candidates:

- Facebook Reel publishing should save canonical page-post IDs, or `GetPostAnalytics` should normalize Facebook post IDs before querying insights.
- Facebook and YouTube post import jobs may need a fresher cadence or targeted post-import dispatch after successful publishing if same-day analytics are expected in the UI.
