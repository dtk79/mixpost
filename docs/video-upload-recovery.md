# Video upload recovery — Pro Team 7.0.4

Status: deployed on October 10, 2026 at 14:39:47 UTC (07:39:47 PDT).

## Confirmed failure

On October 10, `TIS081_SE_AFFILIATE_Preview.mp4` stopped at 44 percent.
Mixpost recorded four of nine chunks. Hetzner accepted part five, but its
acknowledgment failed with `UploadPart / GatewayTimeout`. Earlier interrupted
requests also triggered `Cannot read properties of undefined (reading 'status')`.

The July 27 upload-resilience patch already addressed that JavaScript error.
It was archived as superseded upstream in 6.2.2, but the running 7.0.4 source
again dereferences a missing response. The exact release that reintroduced it
has not been established. The old patch did not provide session resumption or
reconcile remotely accepted parts.

## Repair

Apply these patches, in order, to the exact installed Pro package:

1. `ops/production-patches/video-upload-network-errors-7.0.4.patch`
2. `ops/production-patches/video-upload-recovery-7.0.4.patch`

The frontend preserves the original connection error, retains the upload session,
file, folder, and acknowledged progress when Retry is clicked, and resumes at the
interrupted chunk. It shows a waiting message after five seconds without a chunk
acknowledgment and a retry message during bounded automatic retries. Validation
and authorization errors are not automatically retried.

The server checkpoints each part's content hash before sending it to storage.
On retry, it checks the remote part listing and skips retransmission only when
the part number, size, and content digest match. Opaque/nonmatching ETags result
in safe replacement of the same part number. A part is recorded once, session
updates are atomic, and a lock serializes overlapping requests. Ownership and
session existence are checked again after obtaining the lock. Completion requires
all expected parts; the local-disk path also validates exact chunk sizes.

A lost completion response is handled separately: Retry is disabled and the
user is told to check the media library. Replaying completion could duplicate
an already-created media record. Expired sessions require selecting the file again.

This preserves retry progress while the panel is open. It does not retain a
browser File object across refresh/navigation, make temporary server sessions
durable across container replacement, or stage videos independently of S3.
Extended storage outages can still exhaust the automatic retries; the user can
continue later from the same open panel. The affected pre-deployment upload is
not claimed to have been recovered into the library.

## Validation and packaging

In an isolated copy of the exact package:

```sh
patch --batch --forward -p1 -d "$candidate_package" < ops/production-patches/video-upload-network-errors-7.0.4.patch
patch --batch --forward -p1 -d "$candidate_package" < ops/production-patches/video-upload-recovery-7.0.4.patch
# Install the package's locked frontend dependencies and rebuild its complete assets.
cd "$candidate_package"
npm ci --ignore-scripts --no-audit --no-fund
npm run build
```

Run from the Mixpost source mirror:

```sh
MIXPOST_FRONTEND_SOURCE_DIR="$candidate_package" node --test \
  ops/production-overrides/tests/VideoUploadNetworkErrorsTest.mjs \
  ops/production-overrides/tests/VideoUploadQueueRecoveryTest.mjs
MIXPOST_ASSET_DIR="$candidate_package/resources/dist/vendor/mixpost" \
  node ops/production-overrides/tests/VideoUploadAssetsTest.mjs
```

The backend suite requires installed Pro Composer dependencies but deliberately
does not boot the application, connect to a database, or contact remote storage:

```sh
docker run --rm --network none --entrypoint php \
  -e CHUNKED_UPLOAD_SOURCE_PATH=/var/www/html/vendor/inovector/mixpost-pro-team/src/Support/ChunkedUpload.php \
  -v "$candidate_audit:/candidate:ro" "$candidate_image" \
  /candidate/tests/ChunkedUploadRecoveryTest.php
```

All 16 frontend/queue checks and 12 backend checks passed. The full frontend build
succeeded and all assets/imports in 285 manifest entries were present, including
the compiled recovery messages. Both patches applied cleanly to a fresh copy of
the installed source. These checks are focused regression coverage, not the full
vendor test suite or proof of a successful user upload.

The image recipe is `ops/production-patches/upload-recovery/Dockerfile`.
It overlays only the seven changed source files and the complete rebuilt asset
set onto the verified frozen 7.0.4 image. The package archive excludes environment
files, dependencies, and uploaded media. Existing asset files remain available
for already-open tabs; users need a refresh to run the new uploader.

The release and rollback evidence is on `mixpost-hetzner` under
`/root/mixpost/backups/upload-recovery-candidate-20261010`.
The base image ID, source hashes, packaged artifact hash, candidate image ID,
database checkpoint, mounted-file snapshots, rehearsal, and cutover evidence
must be recorded there. Preserve both effective Compose files and all existing
mounts; recreate only the app, keeping MySQL, Redis, volumes, networks, and
credentials unchanged.

The controlled live storage probe in `ops/scripts/verify-upload-recovery-storage.php`
creates one 10 MiB temporary multipart object, injects a lost acknowledgment
after a successful write, verifies recovery without a second write, completes
and reads back identical bytes, and attempts cleanup in `finally`. It inserts
no media record. A real Chrome upload is still the final user-flow check.

## Deployment proof

- Image: `peachy/mixpost-pro:7.0.4-upload-recovery-20261010`.
- Immutable image ID: `sha256:345d1dfe21b4f539da7b6ebf058a5307aebe200f22088bbc7f5d56e519bd6b53`.
- Restored a current production dump into isolated MySQL/Redis on an internal
  network. Mounted override snapshots were included; production volumes, cron,
  Horizon, mail, and external storage were excluded. Migration rehearsal reported
  nothing to migrate, routes loaded, and all 12 backend checks passed.
- Recreated only the app. The MySQL/Redis container IDs, all 52 mounts (including
  the storage volume), networks, and mounted override hashes remained unchanged.
  Both effective Compose files were preserved; only the app image changed.
- The running source hashes match the tested candidate. Health, login, and landing
  returned HTTP 200; Horizon and cron were running and the custom schedules loaded.
- The login page rendered in the browser without console errors. Its served
  `app-Bt2eQmKv.js` hash matches the locally built entrypoint exactly. All assets
  and imports in the image's 285 manifest entries passed the asset gate.
- A controlled live S3 check recovered a remotely accepted 10 MiB part after a
  simulated lost acknowledgment with only one remote write, completed the
  multipart upload, and read back identical bytes. Cleanup was verified locally
  and against remote storage. No media record was inserted.
- The fresh rollback checkpoint and `frozen-release.json` record identify the
  new image and preserved configuration. Source/build/cutover/storage evidence is
  summarized in `ops/production-patches/upload-recovery/release.json`.

The affected uploader should refresh Mixpost once to load the new frontend,
then select the video again. For subsequent interruptions, leave the panel open
and click Retry to continue. Success of that specific Chrome upload has not been
observed, and the storage service can still time out.

## Upgrade gate

### Follow-up: real upload and temporary permissions

Dan confirmed `VID009 Logy Drake Beau Butler Part 2 Preview-HD.mp4` completed.
The server recorded media ID 1570, 44,370,362 bytes, at 15:56:22 UTC on October 10.
Its session lock was created at 15:54:27 and its initial checkpoint at 15:55:21:
storage initiation took roughly 54 seconds, with about 1 minute 55 seconds from
session creation to the media record. This proves this attempt completed, not
that storage latency is resolved.

Separately, recent regular uploads failed with `mkdir(): Permission denied`.
The shared `storage/mixpost-media` and `temp` parents were root-owned mode 755;
the chunked parent itself was writable. Their ownership was repaired without
restarting the app or changing any session/file contents. A create/write/read/
cleanup check passed as `www-data`. Startup now repairs these shared parents,
the release gate requires an isolated permission regression check, and the live
S3 probe rejects root execution. Root-run tests had masked this permission gap.

Every future Pro rebuild must preserve the behavior and pass the frontend, Vue
queue, backend, and compiled-asset gates against that exact release. The
[frozen release validator and mounted startup guard](frozen-release-gates.md)
now enforce this: no passing matching gate means no migrations or service start.
The validator also requires a fresh build to match the image's active assets.
Do not mark them superseded merely because upstream advertises upload improvements.
Remove them only after the same failure/recovery checks pass against unpatched
upstream source and its built assets. Never revive the archived 6.2.0 image or
bind-mount an individual hashed JavaScript chunk or manifest.

## Rollback

Restore the saved Compose configuration and recreate only the app with
`peachy/mixpost-pro:7.0.4-8def2a9`. Preserve the mounted overrides, including the
separate Instagram repair. This upload patch adds no database migrations. If
startup unexpectedly changes the database, use the matching database checkpoint
and frozen-release rollback procedure; do not blindly restore an older database
over subsequent user activity.
