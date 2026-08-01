# Mixpost Instance Alterations

This document tracks production-specific changes made to the Peachy HQ Mixpost instance that are outside the upstream `inovector/mixpost-pro-team` Docker image.

The production instance runs Mixpost Pro Team from Docker on `mixpost-hetzner`. Host files under `/root/mixpost` are bind-mounted into the container as read-only overrides. These survive image updates, but they can also shadow upstream fixes, so every Mixpost upgrade should compare this register against the new image source.

## Upgrade Review Checklist

Before upgrading Mixpost:

1. Follow [Mixpost Update Playbook](mixpost-update-playbook.md).
2. Review this file and `/root/mixpost/docker-compose.yml`.
3. Pull the new image without replacing host overrides.
4. Compare every mounted override against the same file in the new image.
5. Remove an override if upstream now includes the behavior we need.
6. Rebase the override if upstream changed method signatures, imports, models, queue behavior, or response shapes.
7. Lint mounted PHP files inside the container.
8. Restart Mixpost and verify the acceptance checks listed below.

Useful commands:

```bash
ssh mixpost-hetzner 'docker compose -f /root/mixpost/docker-compose.yml pull mixpost'
ssh mixpost-hetzner 'docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php'
ssh mixpost-hetzner 'docker compose -f /root/mixpost/docker-compose.yml restart mixpost'
```

## Mounted Override Inventory

This is the authoritative inventory from `/root/mixpost/docker-compose.yml`, verified 2026-08-01 for Pro Team 6.3.0. Every row is a read-only bind mount and therefore is automatically reapplied when the app container is recreated; the review requirement is to ensure it still matches the new image.

| Host file | Container target | Purpose | Review trigger |
| --- | --- | --- | --- |
| `/root/mixpost/Schedule.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php` | Production scheduler customization. | Any upstream scheduler changes. |
| `/root/mixpost/TrustProxies.php` | `/var/www/html/app/Http/Middleware/TrustProxies.php` | Reverse proxy trust behavior for production networking. | Laravel or proxy stack changes. |
| `/root/mixpost/ManagesTwitterJobs.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesTwitterJobs.php` | Twitter/X analytics cost mitigation. | X API pricing/quota changes or upstream job changes. |
| `/root/mixpost/ManagesTwitterResources.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesResources.php` | Twitter/X publish timing logs and social-video derivative upload preference. | Upstream Twitter resource changes or when timing logs are no longer needed. |
| `/root/mixpost/ManagesBlueskyJobs.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Bluesky/Concerns/ManagesBlueskyJobs.php` | Bluesky job cadence customization. | Upstream Bluesky job changes. |
| `/root/mixpost/BlueskyUsesUploads.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Bluesky/Concerns/UsesUploads.php` | Prefer an available provider-safe MP4 when it is smaller than the source or the source is not MP4. | Upstream Bluesky upload/video-service changes or provider video limits. |
| `/root/mixpost/ManagesInstagramJobs.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Concerns/ManagesInstagramJobs.php` | Instagram job cadence/custom import sequencing. | Upstream Instagram job changes. |
| `/root/mixpost/InstagramAnalytics.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Analytics/InstagramAnalytics.php` | Instagram short-range total-value fallback. | Upstream Instagram analytics changes. |
| `/root/mixpost/ManagesInstagramResources.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Concerns/ManagesInstagramResources.php` | Instagram media import fallback plus social-video derivative preference for Reel publishing. | Upstream Instagram resource/API changes. |
| `/root/mixpost/ImportInstagramMediaJob.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Jobs/ImportInstagramMediaJob.php` | Instagram media import falls back to basic media and skips unavailable per-media insights. | Upstream Instagram media import changes. |
| `/root/mixpost/BuildChatSystemPrompt.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Actions/AI/BuildChatSystemPrompt.php` | Allows lawful consenting-adult brand copy while preserving core harm refusals. | Upstream AI prompt-builder changes or provider policy changes. |
| `/root/mixpost/ImportTwitterPostsJob.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Jobs/ImportTwitterPostsJob.php` | Store permitted X post-insight metrics while tolerating unavailable historical non-public metrics. | Upstream X import/metric contract changes. |
| `/root/mixpost/MigrateStorage.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Commands/MigrateStorage.php` | Storage migration without S3 prefix listings. | Upstream storage-migration changes. |
| `/root/mixpost/AppMigrateStorageCommand.php` | `/var/www/html/app/Console/Commands/MigrateStorage.php` | Registers the production storage-migration command. | Laravel command-discovery or upstream registration changes. |
| `/root/mixpost/app.blade.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/resources/views/layouts/app.blade.php` | Preserve Google Analytics shell injection. | Upstream layout or analytics support changes. |
| `/root/mixpost/web.php` | `/var/www/html/routes/web.php` | Peachy public-site route customization. | Laravel route changes. |
| `/root/mixpost/home.blade.php` | `/var/www/html/resources/views/home.blade.php` | Peachy public landing page. | App layout/view changes. |
| `/root/mixpost/peachy-posting.png` | `/var/www/html/public/vendor/mixpost/peachy-posting.png` | Landing-page image asset. | Asset path or landing-page changes. |
| `/root/mixpost/uploads.ini` | PHP CLI/FPM config paths | Upload size/runtime config. | PHP image or upload policy changes. |
| `/root/mixpost/peachy-start.sh` | `/usr/local/bin/peachy-start.sh` | Pre-create Laravel's log for `www-data` before root-run startup Artisan commands. | Image entrypoint, startup sequence, or log channel changes. |
| `/root/mixpost/MediaUploadFile.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/MediaUploadFile.php` | Preserve upstream deferred processing and enqueue provider-safe video optimization after normal uploads. | Any upstream upload request or deferred-conversion changes. |
| `/root/mixpost/ChunkedUploadComplete.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Media/ChunkedUploadComplete.php` | Preserve upstream deferred processing and enqueue provider-safe video optimization after chunked uploads. | Any upstream chunk completion or deferred-conversion changes. |
| `/root/mixpost/MediaSocialVideoConversion.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/MediaConversions/MediaSocialVideoConversion.php` | Produce the 1080px/30fps/H.264/AAC derivative used by Instagram, X, and oversized/non-MP4 Bluesky uploads. | Provider video requirements or upstream conversion changes. |
| `/root/mixpost/OptimizeSocialVideoMediaJob.php` | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Jobs/OptimizeSocialVideoMediaJob.php` | Run social-video conversion on the media queue after upstream processing settles. | Queue, media-processing, or conversion lifecycle changes. |

## Alteration Log

### 2026-08-01 - Queue Routing, Reverb, and Bluesky Video Recovery

Status: active production configuration and override.

Two runtime configuration mismatches made completed uploads appear stuck and stranded unnamed Laravel jobs:

- `REVERB_SCHEME=https` was paired with `REVERB_PORT=80`, so server-side broadcast requests reached the plain HTTP router and returned `404 page not found`. Production now uses `REVERB_PORT=443`.
- Laravel's Redis default queue was `default`, while Horizon listened on `mixpost-default`. Production now sets `REDIS_QUEUE=mixpost-default`.

The failed Trailer Trash Boys post also used a 217.9 MB MP4 without a provider-safe derivative. Bluesky rejected the original as `PayloadTooLarge`, and X exceeded its five-minute publish-job timeout. After backfilling a 92.3 MB `social_video` derivative, `BlueskyUsesUploads.php` was mounted so Bluesky selects an available optimized MP4 only when it is smaller than the source or the source is not already MP4. Smaller MP4 originals remain unchanged.

Recovery and verification:

- Post `1502` published only to its failed X and Bluesky accounts; both provider rows contain IDs and no errors.
- Bluesky returned `at://did:plc:uk2ud4bhvue3b4vrktyuoq3x/app.bsky.feed.post/3mrzwbdyeoi2m` with a video embed.
- X returned post ID `2083596559044796890`; timing logs show the optimized 92.3 MB MP4 upload, successful media processing, and tweet creation HTTP `201`.
- Direct Reverb broadcast succeeded, Horizon was running, the active queues were empty, and the public root returned HTTP `200` after the app-only recreation.

Required runtime values:

```text
REVERB_HOST=mixpost.peachyhq.com
REVERB_PORT=443
REVERB_SCHEME=https
REDIS_QUEUE=mixpost-default
```

### 2026-07-31 - Restart-Safe Laravel Log Ownership

Status: active production entrypoint wrapper.

After the Mixpost app container was recreated, a root-run Artisan command created `storage/logs/laravel.log` as `root:root 644`. PHP-FPM runs as `www-data`, so both normal and chunked media uploads returned HTTP 500 when Laravel could not append to the log. The wrapper now pre-creates the log as `www-data:www-data 664` before handing control to the image's root startup sequence.

Operational rule: run later Artisan diagnostics with `docker exec -u www-data` or `docker compose exec --user www-data` unless the command explicitly requires root.

Acceptance checks:

- `docker inspect mixpost-mixpost-1` reports `/usr/local/bin/peachy-start.sh` as the entrypoint and a read-only mount from `/root/mixpost/peachy-start.sh`.
- `storage/logs/laravel.log` is `www-data:www-data 664` after a forced container recreation.
- A write probe run as `www-data` succeeds.
- Normal and chunked upload endpoints no longer return HTTP 500 because logging is unwritable.

### 2026-07-28 - AI Caption Adult Brand Copy

Status: active production override.

Affected file:

- `ops/production-overrides/BuildChatSystemPrompt.php`

Reason:

The upstream global AI system prompt refused all sexually explicit material, which blocked Peachy from drafting promotional captions for lawful consenting-adult creators even when the brand voice and target social copy were acceptable for the business.

Target behavior:

- Permit lawful promotional copy for consenting-adult brands and creators.
- Continue refusing minors, non-consensual sexual content, exploitation, abuse, threats, hate, scams, spam, self-harm, violence, and illegal activity.
- Keep provider-level policy decisions intact; the override only removes Mixpost's broader app-level refusal.

Verification:

- `php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Actions/AI/BuildChatSystemPrompt.php` passes inside the app container.
- `https://mixpost.peachyhq.com/mixpost/login` returns `200` after restart.

### 2026-07-31 - Restore Automatic Social-Video Derivatives

Status: active production override.

The Pro Team 6.2.2 converter only remuxes or transcodes non-MP4 upload formats. It does not resize or reduce already-H.264 MP4 files, so it did not replace the provider-safe `social_video` derivative. Removing the automatic derivative mounts left the retained Instagram/X publisher overrides without a producer. A 2160x3840, approximately 38 Mbps MP4 then failed Instagram processing with error `2207082`; the same optimized derivative used by the earlier recovery published successfully.

The 6.2.2 normal and chunked upload request sources are retained with two additions: preserve `deferVideoConversion()` and dispatch `OptimizeSocialVideoMediaJob` for videos. The optimization job runs on the media queue after upstream processing, waits while a media record is still processing, and creates the 1080px/30fps/H.264/AAC `social_video` derivative used by Instagram and X.

Incident recovery and verification:

- Post `1248` / media `768` published to Instagram as `17901109683333791` (`Dbdy-Tmk_2c`) and to X as `2083244523757424743` after generating a 39.7 MB derivative from the 186.7 MB original.
- The nine remaining scheduled Rich Merritt Instagram videos through 2026-08-18 were backfilled; the future Instagram schedule had no video media left without a `social_video` conversion.
- All four mounted PHP files passed `php -l`, both new classes autoloaded, Horizon reported running, and the public login returned HTTP 200 after the app container restart.

### 2026-07-28 - Pro Team 6.2.2 Upstream Adoption

Status: partially superseded upstream.

Mixpost now owns deferred video processing, upload progress/statuses, format handling, and the Facebook Page request-size/Reels logic. The Facebook Page and upload-resilience overrides remain superseded. The automatic provider-safe derivative was restored on 2026-07-31 because upstream format conversion does not resize or reduce MP4 uploads. `ManagesTwitterResources.php` and `ManagesInstagramResources.php` remain mounted for provider-specific X timing/derivative preference and the Instagram large-Reel guard.

### 2026-07-27 - Social Video Derivatives for X and Instagram

Affected files:

- `ops/production-overrides/ManagesInstagramResources.php`
- `ops/production-overrides/ManagesTwitterResources.php`

Reason:

Large high-bitrate vertical videos caused Instagram Reel upload rejection and X/Twitter chunked upload worker timeouts. The successful manual recovery path was to transcode to a smaller MP4 before provider upload.

Target behavior:

- Instagram Reel publishing and X/Twitter video uploads prefer `social_video` when present.
- Existing manually created `instagram_reel` derivatives remain a fallback.

Production deployment:

- Pro Team 6.2.2 owns the initial deferred media processing; the request overrides preserve that flow and enqueue the provider-safe derivative afterward.
- `MediaSocialVideoConversion.php` and `OptimizeSocialVideoMediaJob.php` provide the conversion and media-queue job.

Verification:

- All mounted PHP files linted successfully inside `mixpost-mixpost-1`.
- `https://mixpost.peachyhq.com/mixpost/login` returned `200`.
- Horizon reported running.
- New job and conversion classes autoloaded in the production app.

### 2026-07-27 - Upload Resilience Client Bundle

Status: superseded upstream in Pro Team 6.2.2.

Files:

- `/root/mixpost/production-image/Dockerfile`
- `/root/mixpost/production-image/upload-resilience.patch`
- `/root/mixpost/docker-compose.yml`

Local source mirror:

- `ops/production-image/`

Reason:

Interrupted browser uploads have no Axios `response` object. The upstream client dereferenced that object in global error handling, replacing the transfer error with a JavaScript exception. Oversized videos are intentionally rejected at 500 MB, but the rejection did not state the selected file size.

Target behavior:

- Preserve the 500 MB total-video policy; the 10 MB chunk transport remains below the 70 MB Nginx and 100 MB Traefik request limits.
- Retry connection and server failures only; do not retry validation failures.
- Show an actionable connection-interrupted error instead of a JavaScript exception.
- Include the selected size in the 500 MB validation response.
- Log successful chunk receipt with upload UUID, chunk index, and byte count, without file names, URLs, or credentials.

Acceptance checks:

- `docker build` from the scrubbed installed-app archive succeeds only when both patches apply cleanly.
- The rendered Mixpost bundle contains `error.response?.status`.
- The patched PHP controller and request pass `php -l` inside the running container.
- A successful test upload produces `mixpost.chunked_upload.chunk_stored` log entries.

Remove/revisit when:

- Mixpost upstream ships null-safe upload error handling, targeted retry behavior, and sufficient chunk telemetry.
- The product raises or otherwise changes the 500 MB video policy; update application, PHP, Nginx, and Traefik request limits together.

### 2026-07-24 - Removed stale custom client asset mounts

Status: removed from production Compose.

Files:

- `/root/mixpost/docker-compose.yml`

Reason:

The post-login blank screen recurred when the host-mounted Mixpost manifest pointed at old hashed client assets that no longer existed in the current Pro Team image. The custom `peachy-login-anchor` client bundles were no longer referenced by the working packaged manifest.

Target behavior:

- Let the Mixpost image publish and serve its own `public/vendor/mixpost/manifest.json`.
- Keep the Peachy landing-page image mount, but do not bind-mount Mixpost's hashed client manifest or old login chunks.

Acceptance checks:

- `docker compose config` passes.
- `docker inspect mixpost-mixpost-1` shows no mounts for `manifest-peachy-login-anchor2.json`, `app-0SGRLsbB`, `app-peachy-login-anchor2.js`, `Login-peachy-anchor2.js`, or `Minimal-peachy-anchor2.js`.
- `/mixpost/login` references the packaged `app-D-o01RHt.js` and `app-jaJ1phLR.css`.
- The current app entrypoint asset scan reports `missing=0`.

Restore/revisit when:

- A future, intentionally maintained custom Mixpost login bundle is rebuilt against the current vendor manifest and has its own automated asset check.

### 2026-07-20 - Facebook Page Follower Refresh

Status: recorded for next scheduler rebase.

Files:

- `/root/mixpost/Schedule.php`

Local source mirror:

- `ops/production-overrides/Schedule.php`

Reason:

The Rich Merritt Facebook Page dashboard showed `594` followers while the public Facebook page and Meta Graph API returned `881` for the same Page ID (`971545159386094`). Mixpost's dashboard reads the latest row from `mixpost_audience`; the manual `ImportFacebookPageFollowersJob` refreshed the current-day row from `594` to `881`.

Target behavior:

- Keep Facebook Page post analytics on the low-cost 30-minute scheduler.
- Also dispatch `ImportFacebookPageFollowersJob` for authorized active `facebook_page` accounts, or otherwise verify the Pro daily follower job is running reliably after updates.
- Do not backfill historical follower rows by rerunning the current-count job for old dates; the endpoint returns the current total and would corrupt history.

Acceptance checks:

- `php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php` passes inside the app container.
- `php artisan schedule:list` still shows `<workspace> - mixpost:low-cost-post-analytics-30min`.
- A synchronous `ImportFacebookPageFollowersJob` for account `84` updates today's `mixpost_audience.total` to the current Meta `followers_count`.

Remove/revisit when:

- Mixpost upstream exposes a reliable frequent Facebook Page follower import.
- Product decides daily follower snapshots are sufficient for Facebook Pages.

### 2026-07-20 - Facebook Page Insights Request Batching

Status: superseded upstream in Pro Team 6.2.2.

Files:

- `/root/mixpost/FacebookPageFetchPosts.php`

Local source mirror:

- `ops/production-overrides/FacebookPageFetchPosts.php`

Reason:

Mixpost requested 100 Facebook Page posts together with nested attachments and lifetime insights. Meta rejected the request with `Please reduce the amount of data you're asking for`, leaving Reporting with stale Page-view totals even though the queue job appeared to finish.

Target behavior:

- Request 25 posts per Graph API page while preserving Mixpost's existing pagination.
- Also request the first page of `/{page_id}/video_reels`, normalize reels into Mixpost's imported-post shape, and merge by provider post ID so reels appear once even if `/posts` later includes them.
- Refresh lifetime `post_media_view` values without changing the metric Reporting consumes.

Acceptance checks:

- The mounted PHP file passes `php -l` inside the Mixpost container.
- A synchronous Facebook Page post import completes without the response-size error.
- The affected reel's `post_media_view` value refreshes in Mixpost, newly discovered Page reels import into `mixpost_imported_posts`, and those rows reach Reporting on the next sync.

Remove/revisit when:

- Mixpost upstream reduces or splits the Facebook Page fields request.
- Meta changes the Page insights response limits or metric contract.

### 2026-07-05 - Twitter/X Publish Timing Logs

Status: active.

Files:

- `/root/mixpost/ManagesTwitterResources.php`

Local source mirror:

- `ops/production-overrides/ManagesTwitterResources.php`

Reason:

A Rich Merritt X video publish timed out in the queue worker before Mixpost saved a provider post ID or API error. A manual retry later succeeded, but the existing logs did not show whether the delay was S3 temp download, X chunked upload, X media processing, or tweet creation.

Target behavior:

- Log `mixpost.twitter_publish_timing` entries for X media download, media upload, media processing, and tweet creation.
- Keep log payloads free of post text, tokens, URLs, and file paths.
- Include only account ID, media ID, media bytes, MIME type, phase, elapsed seconds, X processing state, and tweet-create HTTP code.

Enablement notes:

- Mount `/root/mixpost/ManagesTwitterResources.php` to `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesResources.php`.
- Lint the mounted file inside the container before restart.
- Restart the Mixpost app container for the mount to take effect.

Acceptance checks:

- `php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesResources.php` passes inside the app container.
- The next X media publish writes `mixpost.twitter_publish_timing` log entries.

Remove/revisit when:

- The X media publish bottleneck is understood.
- Mixpost upstream adds provider-publish timing or job timeout observability.

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
