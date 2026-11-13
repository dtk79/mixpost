# Durable Instagram Story media for Annex

Status: **deployed and first capture verified** on November 13, 2026, following
Dan's explicit approval. PR #10 merged as
`d34150c1960e704e357fe1375c182bf81fdfe68e`. The live `frozen-release.json`
`instagramStoryMediaRelease` record and private evidence below are authoritative
for cutover and capture.

Candidate `peachy/mixpost-pro:7.5.1-ig-stories-20261113`, immutable ID
`sha256:0ddd17529272f5ac216b533b3b89729a7b44a268acc1eaa6bc71da18ee9dab8f`,
passes all 115 required frozen behavior/build checks, including the 28 Story
checks, and matches all 66 registered sources and the startup wrapper. Its
complete frontend was rebuilt and matched the published assets byte for byte.
The isolated restored-MySQL rehearsal passes 31 suites, including 13 Story
database checks, with all 91 original business-table data fingerprints unchanged
after migration and fixture cleanup. Original table structures also match.
No production volumes, external provider/storage access, cron or Horizon were
used in that rehearsal. Private evidence is retained under
`/root/mixpost/backups/ig-stories-20261113/`; the initial full dump is 313,511,470
bytes. The actual drained cutover checkpoint is 313,511,815 bytes and is retained
at `cutover/drained/database.sql` under that evidence root.

Eight SQLite integration checks, 58 preservation/release/patch checks and the
health-route regression also pass. The health runner reports the repository's
existing deprecated PHPUnit XML schema. Cutover, actual captures, anonymous
public-object readback and authenticated browser proof are recorded separately;
the rehearsal alone does not establish those live outcomes.

## November 13 production result

The approved app-only cutover completed at 10:30 AM PST. All 91 original
business-table data/schema fingerprints matched before reopening. MySQL/Redis
IDs, all 53 mounts, both Compose files, environment, existing volumes and network
IDs were preserved. Only the existing application provider and startup wrapper
bind-file contents changed. The saved previous image/wrapper/gate passed its
isolated rollback guard; the additive Story table/files are retained on rollback.

The first capture immediately after activation saved four images/covers and one
playable video. Public anonymous HTTPS readback matched the authenticated object
bytes for all five files; all four Stories join to their existing imported posts.
Annex's unchanged SELECT-only connection read the new table successfully.

| Account | Story rows | Images/covers | Playable videos | Incomplete videos |
| --- | ---: | ---: | ---: | ---: |
| Trailer Trash Boys (109) | 2 | 2 | 0 | 1 |
| Ducati Collabs (112) | 1 | 1 | 0 | 1 |
| Patrick Bewley / patricgram (95) | 1 | 1 | 1 | 0 |
| Rich Merritt (85) | 0 | 0 | 0 | 0 |
| ducatistudiosmedia (100) | 0 | 0 | 0 | 0 |
| Trenton Ducati (102) | 0 | 0 | 0 | 0 |
| Dan Tastic (111) | 0 | 0 | 0 | 0 |

Meta omitted `media_url` for one TTB video and the Ducati Collabs video; both
covers are saved. Their last errors are `missing_media_url;missing_video_url`,
so the first command exits 1 to report partial capture. No token or permission
failure occurred. The other four accounts returned no live Stories.

Live health/login returned 200, all 315 active assets were available and the
served app bytes matched the tested image. Horizon/cron and the hourly background
capture event are running. The two controlled storage-recovery probes verified
their contents and cleanup without Media insertion. Authenticated video upload,
interruption, retry and playback passed; original uploaded bytes matched SHA-256
and the synthetic Media row, its three objects and upload session were removed.
Browser network conditions and the original Accounts view were restored. Main
CI's release guard, PHP/Laravel matrix and asset build passed.

`cutover/final-verification.json` separates source, CI, rehearsal, activation,
first capture, Annex reader, public object, service, browser and rollback proof.
A process-status probe required Docker's PID column; maintenance stayed active
until the corrected process, startup-guard and original-data checks passed. No
social post/comment was sent and no Annex application code was changed.

Release preparation reconciled eight existing source mirrors with their actual
Pro 7.5.1 production files: the Threads/Facebook/Instagram/X importers, pagination
concern, chunked-upload initiation and two Facebook fetchers. These retain the
already deployed behavior; the Story candidate changes only application files
and the existing application provider/startup guard. No existing production
vendor customization is replaced with an older repository copy.

## Why this exists

Mixpost Pro 7.5.1's `getStories()` requests `media_url` and `thumbnail_url`, but
`ImportInstagramStoriesJob::importStories()` stores neither. The existing custom
low-cost analytics schedule dispatches that importer every 30 minutes, alongside
the regular three-hour medium-priority cycle. Story metadata/insights imports do
not preserve media. Our command fetches the Stories edge independently, without
requiring an imported-post row, dispatching importer jobs, or modifying vendor
code or any Mixpost table.

Read-only checks on November 13, 2026 confirmed two live Stories for TTB (109,
workspace 12) and one for Ducati Collabs (112, workspace 1). All had an image or
video cover available. Both videos had no `media_url`; their covers can still be
preserved. The standalone connections successfully returned empty Stories lists;
no new permission or Meta app was needed. This was API inspection, not capture.

## Application integration and preservation

The command, collector, config and migration are image-baked **application** files,
mapped in `ops/production-overrides/deployment-manifest.json` and registered in
`modifications.json`. The existing application `AppServiceProvider` explicitly
registers the command and hourly schedule. Preserve/rebase that provider's other
customizations against the exact running source. No Pro/Lite source file is
patched for this feature. The frozen overlay recipe copies these files; stage
their basenames in the build context alongside its other registered inputs.
The parent must be the reviewed exact frozen release, never an archived default
or a generic/latest updater. Rebuild Composer's optimized autoloader if required
by the candidate, and verify both new application classes resolve before release.

The release validator now requires all 28 `InstagramStoryCaptureTest.php` checks
and fingerprints the application provider, command, collector, config and
migration. The mounted startup guard requires that suite and all five file
fingerprints. The test uses actual candidate Pro models, encrypted-token cast,
provider endpoint resolution, response classification and shared rate-limit
implementation with fake HTTP, fake storage and a restricted in-memory capture
table. It starts no application processes and accesses no real database or
bucket. The separate SQLite integration suite verifies the real migration,
uniqueness, scheduler and multi-account command with actual Eloquent models.
Run `InstagramStoryCaptureMySqlTest.php` in the isolated restored MySQL rehearsal;
it exercises the real command/provider connection, table uniqueness, tenant
ownership, repeat/expired capture and schema, then rolls back all fixture rows.

Register CI rejects missing mappings, build copies, documentation or unregistered
production PHP files. Release notes or a future upstream thumbnail fix cannot
retire the independent capture requirement or Annex's table/path contract.

## Annex table contract

Table: `peachy_ig_story_media`. Unique key: `(account_id, provider_post_id)`.
Index: `(workspace_id, posted_at)`. No foreign-key cascade: account/workspace
deletion does not silently delete archived capture metadata or public objects.

| Field | Meaning |
| --- | --- |
| `id` | Unsigned bigint primary key |
| `workspace_id`, `account_id` | Mixpost owning IDs, explicitly scoped on capture |
| `provider_post_id` | Instagram media ID, string up to 64 digits |
| `media_type` | `IMAGE` or `VIDEO` |
| `permalink` | Nullable Instagram Story permalink |
| `posted_at` | Provider timestamp normalized to UTC |
| `disk` | Mixpost's configured imported-media disk at initial capture |
| `image_path` | Nullable relative path to image or video's cover |
| `video_path` | Nullable relative path to optional video |
| `mime_type`, `width`, `height` | Captured image/cover metadata, nullable |
| `captured_at` | UTC time of last successfully saved media component, nullable |
| `error` | Last incomplete-capture reason; fixed sanitized codes, nullable |
| `created_at`, `updated_at` | UTC row creation/update times |

Annex LEFT JOINs on `(account_id, provider_post_id)`. Resolve both paths against
the media bucket root. A missing row or `image_path` leaves the platform icon.
Objects are public, use the same configured disk/bucket as imported media, and
have deterministic names:

`peachy/stories/<workspace uuid>/<account uuid>/<media id>.<ext>`

Extensions are detected from actual bytes: JPEG, PNG, WebP, or MP4. Images are
validated before storage; the image and video keys use different extensions.
No expiring CDN URL or credential is retained. No background pruning or historical
backfill is added. Expired uncaptured Story media cannot be recovered by this
command. Separately plan any retention or disk migration with Annex's readers.

## Command, schedule and configuration

`php artisan peachy:capture-instagram-stories` runs once per hour at minute zero,
in the application scheduler. It visits valid workspaces and authorized, active
`instagram` and `instagram_standalone` accounts, obtains the current encrypted
token via Mixpost's provider connector, and uses the same Laravel HTTP client,
provider Graph domain/API version and response builder. Mixpost retains token
refresh ownership. Nothing marks an account unauthorized or rewrites tokens.

The scheduled command runs in the background so its media downloads cannot hold
up the minute scheduler's publishing and maintenance events.

Graph pages request `id,media_type,media_url,thumbnail_url,permalink,timestamp`.
Pagination reuses `paging.cursors.after` on the configured Stories endpoint,
never `paging.next` URLs that may contain tokens. API requests have bounded
timeouts. Streaming downloads enforce advertised and actual byte caps, bounded
read timeouts, validate content, and clean temporary files in `finally`.
Storage errors preserve already written pointers and retry missing components.
Capture-only S3 clients retain the existing disk settings with bounded write
timeouts and SDK retries disabled; the shared Mixpost client is not modified.
Completed rows skip media requests. Errors retry only for Stories still returned
live and younger than 24 hours. Video covers persist before optional videos;
missing video URLs remain visible as errors even when their covers succeed.

A shared cache lock prevents overlapping manual/scheduled runs, in addition to
the scheduler mutex. A 45-minute run budget leaves headroom on the one-hour
command lock; provider cooldowns skip requests using Mixpost's shared cache.
Graph rate limits and usage warnings install its normal cooldown. Account and
Story failures are isolated. Explicit missing permissions stop the run and must
be reported, without adding permissions automatically. Only an explicit absent
`stories` field on standalone login is skipped as unsupported, with a notice
recorded once in persistent cache. Other errors, including ambiguous code 100
errors, remain errors instead of being silently classified as unsupported.

| Environment setting | Default |
| --- | --- |
| `PEACHY_IG_STORIES_ENABLED` | `true` |
| `PEACHY_IG_STORIES_DOWNLOAD_VIDEOS` | `true` |
| `PEACHY_IG_STORIES_MAX_VIDEO_BYTES` | `104857600` (100 MiB) |
| `PEACHY_IG_STORIES_MAX_IMAGE_BYTES` | `20971520` (20 MiB) |

The config file is `config/peachy-stories.php`; rebuild Laravel's config cache
after changing environment values. No separate credentials or disk are required.
The command emits safe per-account counts: `captured` counts Stories with a
newly saved component, `skipped` counts complete/expired Stories, and `errors`
counts incomplete Stories or account/API errors. A partial capture can increment
both `captured` and `errors`. Errors return a failing command exit status;
rate-limited/unsupported accounts are identified by status. The next hourly run
can recover while media remains live.

## Annex read access

Read-only `SHOW GRANTS` using Annex's actual existing connection on November 13
confirmed user `mp_readonly`@`%` has `GRANT SELECT ON mixpost_db.*`. This includes
the new table automatically. **No grant change is needed or made.** Verify the
same connection can SELECT the new table after the approved migration. If a
future installation uses table grants, the only additional statement is:

```sql
GRANT SELECT ON `mixpost_db`.`peachy_ig_story_media` TO 'mp_readonly'@'%';
```

Run that only when the inspected account actually needs it; do not change other
grants or put credential management in the schema migration.

## Candidate acceptance and approved deployment

1. Build the exact reviewed candidate with these application files. Stage the
   changed existing provider and startup wrapper with their matching evidence;
   retain every other mount/customization, both Compose files and all protected
   services, credentials, volumes and networks. Do not install the new wrapper
   against an old gate: its new required Story suite intentionally rejects it.
2. Run the frozen release validator and register/source comparison. All existing
   behavior suites and complete asset verification remain mandatory. In an
   isolated restored MySQL rehearsal, migrate the new table, confirm the original
   tables' row/schema fingerprints remain unchanged, run account-isolation and
   database cases, and inspect `artisan list` and `artisan schedule:list`.
3. After explicit approval, take a fresh database-aware checkpoint and follow
   the normal app-only gated cutover. The additive application migration runs
   through Laravel's existing migration process. Check it completes before
   enabling cron/Horizon. Verify the Annex reader's SELECT access.
4. **Immediately run once after deployment**, as `www-data`, then inspect the
   counts and errors below. The observed November 12 PT Stories expire around
   4:58 PM, 5:49 PM and 8:14 PM PT on November 13. Approval delays may mean those
   particular media can no longer be captured; do not claim historic recovery.
5. Verify image/video object existence, content and anonymous public readback
   from the configured bucket. Confirm the hourly schedule, service health and
   protected service IDs. Report source, CI, exact-candidate tests, restored-data
   rehearsal, cutover, storage and authenticated browser proof separately.

```sh
docker exec -u www-data mixpost-mixpost-1 php artisan peachy:capture-instagram-stories
```

Read-only post-deployment query (through the normal private DB connection):

```sql
SELECT account_id, COUNT(*) AS stories,
       SUM(image_path IS NOT NULL) AS images,
       SUM(video_path IS NOT NULL) AS videos,
       SUM(error IS NOT NULL) AS errors,
       MAX(captured_at) AS latest_capture_utc
FROM peachy_ig_story_media
GROUP BY account_id;
SELECT account_id, provider_post_id, error
FROM peachy_ig_story_media WHERE error IS NOT NULL;
```

For local code verification with an available private Pro export, point
`PEACHY_STORIES_AUTOLOAD` at a compatible installed Composer autoloader,
`PEACHY_STORIES_PRO_ROOT` at the exact Pro export, and `PEACHY_STORIES_SOURCE` at
this checkout's `ops/production-overrides` directory, then run the Story test.
The exact-candidate validator leaves those unset and exercises installed files.

## Rollback

Restore the previous exact image, provider override, startup wrapper and matching
passing gate together, using the approved checkpoint procedure. Preserve MySQL,
Redis, storage, credentials and networks. This additive table can remain in
place, retaining captured rows and files for Annex; stopping capture does not
invalidate saved paths. Do not invoke migration rollback/drop the table or
delete objects as part of routine image rollback. No restore may overwrite later
user writes. An older image needs its own saved compatible wrapper/gate, or a
freshly tested rollback candidate; the new wrapper cannot accept old evidence.
