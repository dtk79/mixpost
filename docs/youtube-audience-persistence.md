# YouTube audience persistence and Annex activation

This change prepares persisted YouTube Analytics snapshots for Annex. It does not deploy files, run migrations, change production environment, or write production reports during development.

## Current verification

A read-only live export on September 25, 2026 at 21:59 UTC succeeded for Off Brand account **97**, workspace **25**, September 1–25: gender `male:100%`, country `US:46 views`, age `no_reportable_data`. The original missing-scope probe is historical; channel analytics access now works. Empty age data must remain explicitly unavailable/privacy-limited, never zero or an inferred age profile.

## Persistence contract

Table: `mixpost_youtube_audience_reports`.

| Column | Type / meaning |
| --- | --- |
| id | BIGINT UNSIGNED primary auto increment |
| workspace_id, account_id | BIGINT UNSIGNED, separate foreign keys with delete cascade |
| channel_id | VARCHAR(64); exact connected channel identity |
| start_date, end_date | DATE; inclusive requested YouTube reporting dates |
| subscription | VARCHAR(16), `all` for this collector |
| report_json | JSON, existing schemaVersion1 YouTube report envelope |
| fetched_at | DATETIME(6) UTC, acquisition time of retained envelope |
| last_attempt_at | DATETIME(6) UTC, latest attempted collection |
| last_attempt_status | VARCHAR(32), latest envelope status |
| last_attempt_error | VARCHAR(64) nullable, safe failure reason or null |

Unique key: `(workspace_id, account_id, start_date, end_date, subscription)`. The collector checks account/workspace/channel identity again within the write transaction; a reconnect to another channel cannot reuse an old snapshot. Account deletion removes its reports. Data is not copied into Instagram demographic tables.

A successful acquisition means every report dimension is `available` or `no_reportable_data`. Therefore a mixed result with available gender/geography and empty age has envelope `partial` **and `last_attempt_error=null`**. Successful empty responses replace old buckets. A failed acquisition preserves the previous successful envelope and `fetched_at` for the exact range and channel while advancing `last_attempt_*`. If there is no prior successful snapshot, it stores the failure envelope. An older attempted timestamp cannot replace a newer result. Annex should render retained data with a stale/refresh-failed notice when `last_attempt_error` is non-null, and never equate envelope `partial` with a failed request.

No `dataThrough` completeness watermark is invented. Fetched-at is acquisition time, not proof that Google has finalized the requested ending day. Country figures remain views, age/gender remain percentages of logged-in viewers. Do not aggregate these into Instagram follower counts.

## Collection and scheduling

`YoutubeAudienceCollector` accepts one explicitly opted-in workspace/account and exact period, checks the active Mixpost workspace, resolves the current YouTube account, uses the existing provider's locked `refreshTokenIfNeeded()` convention, verifies current scopes, calls bounded read-only Analytics endpoints, and atomically upserts the result. It never marks a publishing account unauthorized merely because Analytics permission is missing. Unlike the read-only exporter, this collector intentionally persists reports and may rotate/persist the existing OAuth token.

Set container environment `MIXPOST_YOUTUBE_AUDIENCE_WORKSPACE_IDS=25` to enable Off Brand. Empty/malformed configuration disables collection. This is a collector opt-in, not a replacement for Annex's permanent workspace allowlist. It must be a process environment variable visible to both scheduler and queue workers, including when Laravel config is cached.

`YoutubeProvider::lowPriorityJobs()` appends a dispatcher to the existing low-priority jobs without dropping upstream work. The installed global scheduler already runs that hook every six hours. The dispatcher verifies opt-in/account scope and schema presence, then enqueues one Analytics-queue job for each distinct range: current Pacific day, Monday-based week through today, calendar month through today, and each of the previous three closed days to revisit initial lag/empty results. Jobs are staggered, locked per account, and have a 120-second timeout; overlapping jobs release after 30 seconds rather than running concurrently. No global `Schedule.php` override is introduced: repository and live X scheduling differ, and this change deliberately avoids activating those unrelated changes.

The three-day retry window does not guarantee historical report completeness. Current week/month snapshots advance their ending date; complete historical week/month and custom ranges can be collected manually. Annex must use exact start/end dates and channel identity; it must never silently substitute another stored range. YouTube interprets dates in `America/Los_Angeles`, even if Annex labels its shared date selector UTC. Before Pacific midnight, the UTC calendar date can be one day ahead; collection rejects future Pacific end dates rather than fabricating results.

A transport/provider failure is persisted as attempt metadata and retried on the next scheduled cycle. Queue/lock/infrastructure failures retry through the queue. Do not configure unbounded immediate retry storms against Google.

## Activation sequence — operator action required

1. Deploy the new managed classes and migration listed in `deployment-manifest.json`, including the updated YouTube provider. Follow the existing Infra read-only mount rollout; verify mounted hashes and PHP syntax. Do **not** deploy an unrelated `Schedule.php` override.
2. Apply only this migration inside the existing application, before enabling collection:

   ```sh
   docker exec -w /var/www/html mixpost-mixpost-1 php artisan migrate --path=vendor/inovector/mixpost-pro-team/database/migrations/2026_09_25_210000_create_youtube_audience_reports.php --force
   ```

   The migration is versioned and reversible; `down()` deletes the table and its snapshots. Do not roll it back casually after data collection starts. It is not run automatically by page reads or the dispatcher.

3. Verify the analytics queue worker permits the job's 120-second timeout, worker/Horizon supervisor timeout exceeds 120 seconds, and the underlying queue connection `retry_after` exceeds the worker timeout (for example supervisor150 / retry_after180). Inspect existing settings first; do not shorten unrelated queues or change them without the deployment's authorized scope. The account lock expires after180 seconds.
4. Set process environment `MIXPOST_YOUTUBE_AUDIENCE_WORKSPACE_IDS=25` on the scheduler and workers, recreate/restart the scoped Mixpost processes through the managed deployment, and verify they see the value. No new OAuth consent is needed for account97 while its confirmed Analytics permission remains valid; the collector still verifies it each time.
5. Seed current windows using the explicit mutating operator command from this repository:

   ```sh
   python3 ops/scripts/collect-youtube-audience.py 25 97
   ```

   Or seed a bounded exact custom/historical range:

   ```sh
   python3 ops/scripts/collect-youtube-audience.py 25 97 --start 2026-09-01 --end 2026-09-25
   ```

   The Python wrapper streams only the CLI entry point into the existing container; required collector classes must already be deployed. It emits only identity, dates, status, and safe reason. Exit0 means all requested acquisitions succeeded, including privacy-empty dimensions; exit2 means at least one returned a failure; exit64 is invalid input; exit1 is a sanitized infrastructure failure. Credentials never leave Mixpost. Existing read-only export commands remain available for diagnostics and do not write snapshots.
6. Verify rows for workspace25/account97/channel `UCG35kFqmv3Ka8MbXZqTKAMw`, exact dates and `subscription=all`; verify `last_attempt_error=null` for successful partial coverage and expected per-report statuses. Reapply/verify Annex's SELECT-only database identity can read the new table; add only a table-scoped SELECT grant if its existing grants do not cover it. Never give Annex token-table writes or provider credentials.
7. Verify Annex reads stored reports only, reports missing/custom uncollected ranges honestly, shows per-dimension privacy/empty states, and does not call Google when a tab opens. Check one automatic low-priority cycle and fresh `last_attempt_at` before declaring scheduled collection operational.

## Validation performed

- 32 collector assertions: scope gating, query identity, dimension semantics, errors, no-reportable-data.
- 22 SQLite-backed persistence assertions: actual migration/upsert/delete cascade, exact range/channel ownership, retention across transient failure, successful empty replacement, latest-attempt metadata, UTC/Pacific date boundaries, recent-day windows, opt-in parsing.
- 7 scheduling contract checks: upstream low-priority jobs retained, dispatcher and managed files included, no global scheduler override.
- PHP syntax validation for all new/changed PHP files; Python wrappers' local CLI parsing.
- Live read-only exporter confirms working analytics authorization and current available/empty report states. The new persistence collector and schema have **not** been executed against production.

Run portable tests with `MIXPOST_VENDOR_AUTOLOAD` pointing to an installed Composer vendor autoloader if this worktree has no vendor directory:

```sh
php ops/production-overrides/tests/YoutubeAudienceReportTest.php
MIXPOST_VENDOR_AUTOLOAD=/path/to/vendor/autoload.php php ops/production-overrides/tests/YoutubeAudienceSnapshotTest.php
php ops/production-overrides/tests/YoutubeAudienceQueueHookTest.php
```
