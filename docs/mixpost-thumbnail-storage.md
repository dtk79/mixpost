# Imported-thumbnail storage remediation

Production: Mixpost Pro Team, `mixpost-hetzner`, bucket `ducati-mixpost`.

## Cause and fix

The Instagram, Facebook Page, and Threads import jobs overwrote a cached `thumbnail` path with a provider URL on every upsert. `DownloadImportedPostThumbnailJob` therefore downloaded another random-named file on each refresh. The September 6, 2026 inventory found 3,088,220 current files totaling 845.241 GB, with only 1,493 distinct ETags (406.786 MB when counted once per fingerprint).

Four maintained source mirrors under `ops/production-overrides/` remedy this:

- `ImportInstagramMediaJob.php`, `ImportFacebookPagePostsJob.php`, and `ImportThreadsPostsJob.php` use an atomic MySQL upsert expression to preserve an existing `imported/` path while refreshing all other fields. New, empty, null, and remote thumbnail values can still be populated/refreshed.
- `DownloadImportedPostThumbnailJob.php` uses an account/workspace/post-scoped Redis lock, re-reads the post after taking the lock, ignores deleted posts and already-cached thumbnails, and writes a deterministic SHA-256 filename per provider post ID. A successful object write can be reused after an interrupted database update. Failed writes do not replace the reference; temporary local downloads are cleaned in `finally`.

Existing current thumbnail URLs do not change. No reference migration is required. An intentional refresh of already-cached image content requires a separate explicit invalidation design; routine analytics refresh does not replace it.

## Deployment and rollback

All four mirrors are copied to `/root/mixpost/<filename>` and mounted read-only into the matching Pro package classes:

| File | Container target under `/var/www/html/vendor/inovector/mixpost-pro-team/` |
|---|---|
| ImportInstagramMediaJob.php | src/SocialProviders/Meta/Jobs/ImportInstagramMediaJob.php |
| ImportFacebookPagePostsJob.php | src/SocialProviders/Meta/Jobs/ImportFacebookPagePostsJob.php |
| ImportThreadsPostsJob.php | src/SocialProviders/Threads/Jobs/ImportThreadsPostsJob.php |
| DownloadImportedPostThumbnailJob.php | src/Jobs/DownloadImportedPostThumbnailJob.php |

The deployment backup is recorded on the host in `/root/mixpost/thumbnail-fix-backup-path`. Restore that backup's Compose file and existing Instagram override to roll back, then recreate only the app service. Do not restore unrelated older customization files. Removing the three added mounts restores those classes from the packaged image.

On every upstream image update, compare all four mirrors with the new upstream classes. Rebase unrelated vendor changes. Remove these overrides when upstream preserves cached paths and prevents duplicate downloader writes with equivalent regression coverage.

## Regression checks

The standalone test runs against the real Laravel/MySQL runtime with fixture changes inside a rolled-back transaction. It uses fake HTTP, a temporary local disk, and an in-process cache, so it does not publish or write to S3.

```sh
ssh mixpost-hetzner 'docker exec -i mixpost-mixpost-1 php' < ops/production-overrides/tests/ImportedThumbnailReuseTest.php
```

For testing staged source before deployment, place the four PHP files together and set `PEACHY_THUMBNAIL_SOURCE` to that directory in the container. After deployment, omit that variable to test the actual installed classes. Verify all four mounts are read-only and their SHA-256 hashes match the mirrors, Horizon is running, the login returns 200, and the existing log remains `www-data:www-data 664`.

## Conservative cleanup

`ops/scripts/cleanup-imported-thumbnails.php` is an explicitly invoked operator tool, not a scheduled job. It bootstraps the production app and uses its configured S3 client without exposing credentials.

1. `refs <run>` scans text/JSON columns throughout the application database for paths under `imported/`, including full URLs and JSON-escaped slashes. It records only extracted paths and source column counts.
2. `pilot-plan <run>` creates an immutable 1,000-version test manifest in ten batches; `plan <run>` completely enumerates the prefix and writes 1,000-version batches. Both exclude all referenced paths, files newer than one hour, and unfamiliar filename structures. Every deletion uses an explicit S3 version ID.
3. Before deletion, verify every protected current file with S3 HEAD and save its ETag, size, and version ID. `delete <run> <concurrency>` refreshes all references, unions them with the plan's protected paths, and refreshes current thumbnail references before each batch. Each successful batch has an atomic receipt. A failed response stops further scheduling; inspect errors before resuming. Do not run two deletion processes for the same run.
4. Re-run to resume completed-batch-aware deletion. Verify all protected files against the baseline, enumerate the remaining prefix, and compare it with the protected/recent/unrecognized sets before claiming completion. Cleanup is not complete merely because a background process was started.

Run data persists in `/var/www/html/storage/app/peachy-storage-cleanup/<run>/`, backed by the existing storage volume. The manifests and receipts may contain filenames but no access credentials. They must not be placed under the public storage directory. Existing deletion markers are retained. Referenced keys retain all their versions.

The earlier historical-media cleanup removed 887 versions / 32.749 GB and verified all 2,246 current files in the scanned non-imported prefixes unchanged.

## Temporary-upload retention

The bucket lifecycle rule `mixpost-temporary-upload-history-7d` is scoped solely to `chunked-uploads/`. It expires noncurrent versions after seven days and aborts incomplete multipart uploads after seven days. It does not expire current files or affect `imported/` or uploaded media. Preserve any other lifecycle rules when updating it.
