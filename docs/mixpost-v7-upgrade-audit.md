# Mixpost Pro v7 customization audit

Audit date: 2026-09-29. Installed production baseline: Pro Team 6.3.1; candidate: Pro Team **7.0.1**, standalone app 7.0.0, Laravel 13, PHP 8.3.35. The public announcement is v7.0.0; licensed Composer resolution on the audit date returned 7.0.1.

## Scope and findings

- Reconciled seven local-only commits with remote main in an isolated checkout; original dirty checkout preserved.
- Inventoried **41 read-only bind mounts / 40 unique files**, compared all against pristine 6.3.1 and 7.0.1, and inspected unmounted runtime app/bootstrap/package changes. Only the vendor startup migration command existed beyond the mounted changes.
- **20 mounted targets changed upstream**, 9 were unchanged, and 12 mount rows were custom additions/infrastructure. None of the prior upstream targets disappeared.
- Existing manifest covered 16 application files; expanded to 44 application targets, including server-only source, all six already-committed YouTube persistence additions and the new shared failure finalizer. Startup and both PHP INI targets remain explicit infrastructure mounts.
- Vendor Docker startup downloads Composer packages. Frozen release images now retain exact application code and lockfile across restarts.

## Compatibility decisions

| Area | Result |
| --- | --- |
| Publishing and emails | Keep v7 account status, checkpoint, staggered departure, concurrency and retry logic. Move consolidated failure emails to the shared finalizer used by both initial and retry batches. |
| Historical/queued video preparation | Retain provider-safe H.264/AAC full-duration derivatives, one conversion per media, queue waiting and bounded preparation failure. Resume existing provider checkpoints without a fresh upload. |
| Instagram upload recovery | Keep FINISHED-only publication, bounded polling, useful container diagnostics and at most two retries for confirmed terminal 2207082 upload failures. Reset only that account in the already-claimed run. Preserve v7 music, collaborators and asynchronous carousel state. |
| X analytics and publishing | Combine custom age windows, note_tweet/article text, metric fallback, 4 MiB chunk retries and timing with v7 public-only historical metrics. No paid notification import; posting-time scores use stored data. |
| Thumbnail storage | Keep atomic cached-path preservation and deterministic locked writes; incorporate v7 prefetch/skip helpers and accurate publication timestamps. |
| Scheduling | Preserve 30-minute low-cost analytics, bounded X tiers and Bluesky cadence; retain v7 weekly posting schedule tuning and posting-score computation. |
| Upload/media library | Keep automatic derivative dispatch while preserving v7 folders, media processing and stock assets. |
| Empty thread items | Preserve save/publish normalization while retaining v7 mentions, version sync, schedules and validation. |
| Provider/configuration extensions | Preserve Threads video/OAuth safeguards, Bluesky mention retry, Instagram short-range analytics/import resilience, YouTube audience read/collection hooks, AI brand prompt, and S3 database-path migration. |
| Branding/operations | Rebase GA4 injection onto v7 layout; preserve public landing page, proxy trust, log ownership, upload limits, masked exception logging, password-reset route and lightweight health. |

## Complete pre-upgrade mount register

| Host file | Upstream review | Target |
| --- | --- | --- |
| `AppServiceProvider.php` | upstream-unchanged | `/var/www/html/app/Providers/AppServiceProvider.php` |
| `PostFormRequest.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Post/PostFormRequest.php` |
| `TrustProxies.php` | upstream-unchanged | `/var/www/html/app/Http/Middleware/TrustProxies.php` |
| `web.php` | rebase-required | `/var/www/html/routes/web.php` |
| `home.blade.php` | custom-file | `/var/www/html/resources/views/home.blade.php` |
| `ImportThreadsPostsJob.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Threads/Jobs/ImportThreadsPostsJob.php` |
| `MediaSocialVideoConversion.php` | custom-file | `/var/www/html/vendor/inovector/mixpost-pro-team/src/MediaConversions/MediaSocialVideoConversion.php` |
| `MigrateStorage.php` | upstream-unchanged | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Commands/MigrateStorage.php` |
| `ThreadsManagesOAuth.php` | upstream-unchanged | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Threads/Concerns/ManagesOAuth.php` |
| `ChunkedUploadComplete.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Media/ChunkedUploadComplete.php` |
| `peachy-start.sh` | custom-file | `/usr/local/bin/peachy-start.sh` |
| `ImportFacebookPagePostsJob.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Jobs/ImportFacebookPagePostsJob.php` |
| `DownloadImportedPostThumbnailJob.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Jobs/DownloadImportedPostThumbnailJob.php` |
| `ImportInstagramMediaJob.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Jobs/ImportInstagramMediaJob.php` |
| `BlueskyHelpers.php` | upstream-unchanged | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Bluesky/Helpers.php` |
| `PostPublishingFailedNotification.php` | custom-file | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Notifications/PostPublishingFailedNotification.php` |
| `ManagesInstagramJobs.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Concerns/ManagesInstagramJobs.php` |
| `OptimizeSocialVideoMediaJob.php` | custom-file | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Jobs/OptimizeSocialVideoMediaJob.php` |
| `AccountPublishPostJob.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Jobs/AccountPublishPostJob.php` |
| `ManagesBlueskyJobs.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Bluesky/Concerns/ManagesBlueskyJobs.php` |
| `PeachyPostVersionContent.php` | custom-file | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Support/PeachyPostVersionContent.php` |
| `ThreadsManagesContainer.php` | upstream-unchanged | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Threads/Concerns/ManagesContainer.php` |
| `peachy-posting.png` | custom-file | `/var/www/html/public/vendor/mixpost/peachy-posting.png` |
| `InstagramAnalytics.php` | upstream-unchanged | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Analytics/InstagramAnalytics.php` |
| `ImportTwitterPostsJob.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Jobs/ImportTwitterPostsJob.php` |
| `PublishPost.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Actions/Post/PublishPost.php` |
| `AppMigrateStorageCommand.php` | custom-file | `/var/www/html/app/Console/Commands/MigrateStorage.php` |
| `ManagesInstagramResources.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Concerns/ManagesInstagramResources.php` |
| `BlueskyUsesUploads.php` | upstream-unchanged | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Bluesky/Concerns/UsesUploads.php` |
| `ManagesTwitterJobs.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesTwitterJobs.php` |
| `AccountPublishPost.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Actions/Post/AccountPublishPost.php` |
| `ManagesTwitterResources.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesResources.php` |
| `uploads.ini` | custom-file | `/etc/php/8.3/fpm/conf.d/99-uploads.ini` |
| `uploads.ini` | custom-file | `/etc/php/8.3/cli/conf.d/99-uploads.ini` |
| `BuildChatSystemPrompt.php` | upstream-unchanged | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Actions/AI/BuildChatSystemPrompt.php` |
| `YoutubeProvider.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Google/YoutubeProvider.php` |
| `PostFailureExplanation.php` | custom-file | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Support/PostFailureExplanation.php` |
| `YoutubeAudienceReport.php` | custom-file | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Support/YoutubeAudienceReport.php` |
| `Schedule.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php` |
| `app.blade.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/resources/views/layouts/app.blade.php` |
| `MediaUploadFile.php` | rebase-required | `/var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/MediaUploadFile.php` |

Machine-readable before/upstream hashes: `ops/upgrades/v7-override-audit.json`. Candidate deployment source/targets: `ops/production-overrides/deployment-manifest.json`.

## Non-mounted and historical custom work

- The Lite account-request controller, validation, mail template, public homepage and route changes already exist on remote main. They are distinct from the production Pro landing page; retained in Git, not blindly mounted over Pro package routes.
- Local-only `Bootstrap.php`, `Handler.php`, `MixpostExceptionHandler.php`, `MediaChunkedUploadController.php`, `ChunkedUploadInitiate.php`, and `ChunkedUploadChunk.php` are not mounted or present as unmounted runtime changes. Preserve them in the original checkout as historical work; do not activate them during the upgrade.
- `FacebookPageFetchPosts.php` and `ops/production-image/` are retired compatibility patches; upstream replacements remain active. No old client manifest or JavaScript chunks are restored.
- Account reconnections, past provider-ID repairs and completed thumbnail cleanup are data/history, not code to rerun. Existing encrypted credentials, social account records, SMTP relay, queue names, Reverb HTTPS, S3 settings and analytics settings are retained.
- Historical alteration log: [instance alterations](mixpost-instance-alterations.md). Repeatable process and database-aware rollback: [update playbook](mixpost-update-playbook.md).

## Verification and deployment

Isolated rehearsal completed: all 17 upstream upgrade migrations and the custom YouTube reports migration applied to a restored database copy. PHP lint, cached routes/views, provider loading, scheduling, thumbnail regression, Instagram container checks, X diagnostics/windowing, empty-item normalization, all-provider failure email rendering, full-duration FFmpeg output, five-provider video preflight, bounded Instagram retry and Threads request tests passed. YouTube snapshot persistence passed 22 checks with an isolated local SQLite database; the vendor PHP runtime lacks SQLite, so that fixture ran against the same extracted dependency tree locally. No real provider posts or email were sent.

A new database-transaction regression proves blank-item validation compatibility, immediate checkpoint persistence, partial-thread retry without duplicate first publication, account settlement, pending upload persistence and checkpoint resume. Fixture database writes were rolled back.

Production cutover completed 2026-09-29 at approximately 18:13 UTC (11:13 Pacific), after the last running Instagram import drained naturally. No publishing jobs were active. Migrations ran in an inert container before web, cron and workers started.

- Live Pro **7.0.1**, source `e4cfc6333a34f3d9fe08e47f81ab672a6a65a3fd`.
- Customization source commit `8fc353165ef513899ba0c305364de9a61e2e3b63`.
- Composer lock SHA-256 `0b6daf14cf9ea05fbafad2717a2ecad8cc6644f28a56a5f0656104d76b5f5ab8`.
- Frozen image `peachy/mixpost-pro:7.0.1-8fc3531`, ID `sha256:771c3726ae88975d604d78d3e55c86bfa35790adc7d1038d84ff57d9701d97d4`.
- Vendor base `inovector/mixpost-pro-team@sha256:0076027bb9e2a0425568e17965bc8c90758de585efc4769e1457ce91de41df06`.
- All **47 read-only mounts** present (44 application plus startup and two PHP INI mounts); application hashes matched the reviewed manifest and PHP lint passed inside production.
- All 17 upstream and the YouTube report migrations passed. Stable-field fingerprints matched exactly across users (14), workspaces (10), accounts (27), posts (920), destinations (1,541) and media (1,135). This verifies selected stable columns, not a byte-identical database: the intended new schema/data migrations also ran.
- MySQL and Redis container IDs unchanged. Horizon, cron, Nginx, PHP-FPM and Reverb running. Native v7 scheduling and custom analytics cadences registered.
- Public home, `/mixpost/login`, and `/api/health` return HTTP 200. All 14 provider classes load and packaged asset checks report zero missing files.
- Authenticated Chrome verification: new Home dashboard with existing scheduled content and Peachy branding; existing social accounts; new media library with existing content, 31 loaded images and zero broken images. No browser warnings/errors during these checks.
- Exact final checkpoint: `/root/mixpost/backups/v7-cutover-20260929-180652` (database, Compose, overrides, migration log, before/after fingerprints, runtime probe).
- Prior frozen release: `peachy/mixpost-pro:6.3.1-pre-v7-20260929`, ID `sha256:9b3a793eebc35d9fa7291b220b141b8e5ad7ea4ce8ef7e89a1ee1a8e3038c1ef`. This is an export of the actual old runtime, not the v7 overrides. Both frozen images are also archived in `/root/mixpost/backups/v7-audit-20260929/frozen-releases.tar.gz` so an image prune need not destroy rollback capability.
- Host release record: `/root/mixpost/frozen-release.json`. Legacy Infra deployment state is historical and its generic updater is intentionally blocked by the frozen image reference.

Provider publishing and email verification used fakes. No unsolicited live publication or test email was sent. The first naturally scheduled publication after cutover remains a separate live-provider check.
