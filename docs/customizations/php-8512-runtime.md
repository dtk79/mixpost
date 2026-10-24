# Patched PHP Docker runtime — October 22, 2026

Mixpost's patched Pro v7 amd64 image is pinned for review at
`inovector/mixpost-pro-team@sha256:27373cd899620a30cf0b1340973c2f28224486ffb871b76fc071438921546fc5`.
Its CLI reports PHP 8.5.12. The multi-platform v7 index digest is
`sha256:c7016259344512ab5b7187691e7f833d05f6150cea964c527364546d1b1e87cf`.
The production app remains subject to the full frozen-release playbook; the
vendor startup must never resolve dependencies against production.

## Preserving PHP configuration mounts

The old effective Compose files mount `uploads.ini` into both PHP 8.3 SAPIs and
`php-security.ini` into PHP 8.3 FPM. The patched base uses `/etc/php/8.5/`.
Simply reusing the old mount destinations would silently lose upload limits and
the explicit upload-progress mitigation.

For this reviewed transition, build with
`MIXPOST_LEGACY_PHP_CONFIG_SERIES=8.3 ops/upgrades/build-frozen-image.sh ...`.
The builder creates `/etc/php/8.3 -> 8.5` in the image before Docker applies the
existing read-only mounts. It discovers the active series from PHP, requires
both FPM and CLI configuration directories, and refuses to replace an existing
legacy path. Other builds omit the option and keep their existing behavior.
This preserves the exact two-file Compose configuration, all mount destinations,
and the configuration-only promotion guard.

Require the exact-candidate upload gate, register check, restored-database
rehearsal, and container-replacement recovery. In rehearsal and production,
check the actual FPM worker over its private socket: PHP 8.5.12, native upload
progress Off, and upload/post limits 500M/520M. Verify ordinary multipart bytes,
the legacy alias, both loaded mounted INI files, and authenticated chunked video
upload/interruption/retry. CLI output alone is insufficient.

Retain the prior frozen app, its matching gates, both Compose files, mount
snapshots, database checkpoint and settings fingerprints. Recreate only the app;
an old image cannot reverse migrations. Record source, CI, rehearsal, cutover,
storage and browser outcomes separately. The private audit directory for this
run is `/root/mixpost/backups/php8512-upgrade-20261022/`.

## Reproducible frontend build

The licensed registry resolved Pro 7.1.4 (`7459823dee89a6f5018c149687a48dadfacea9de`)
although the public releases page still listed 7.1.2. Its locked Vite 8.2 build
produced varying chunk hashes with the default parallel compiler pool. Two
diagnostic builds with `RAYON_NUM_THREADS=1` produced identical manifests.
The asset builder and release validator now use that setting. Promotion still
requires every active asset and the manifest to match a fresh build byte for
byte; no filenames, bytes, tests or checks are ignored.

## Production readback

Production now runs Pro **7.1.4** on **PHP 8.5.12**, including the actual FPM
worker and the authenticated Admin Console Versions page. The frozen image is
`peachy/mixpost-pro:7.1.4-e4f467b-php8512-20261022`, immutable ID
`sha256:6d20f48879a230badaa023ea0e3994b4d95d46e46e5ec60528cff7836e7262a6`.
The implementation revision is `e4f467bd3070a946986257cb7f88a7232eadcdee`;
later audit/documentation commits do not change its source or image.

| Evidence | Observed result |
| --- | --- |
| Source | 63 custom source files rebased without conflicts; all 61 registered overrides match; 65 mounted/image customization audit rows have no upstream overlap or removal |
| Exact-image gate | All six required suites passed, including a fresh byte-identical full build; 18 network, 3 queue, 12 backend, 17 local-staging, 1 permission, and 1 asset checks |
| CI | Implementation revision passed Frozen release guard run `39891012099` and PHP/Laravel matrix run `39891012111`; local guard/register regressions passed 23/18 checks |
| Rehearsal | Restored production snapshot on an internal network; 17 Pro suites, real video/thumbnail conversion, durable outbox/container replacement, and real nginx/FPM/login checks passed |
| Cutover | Only the app was recreated; both effective Compose files, all 53 mounts, environment values, networks, proxy protocol override, MySQL and Redis preserved |
| Data | Database schema, selected stable core records, and SSO/service settings matched around inert initialization |
| Runtime | Health/login 200, API rejects unauthenticated access with 401, Horizon/cron/FPM/nginx/reverb running, schedule registered |
| Assets | All 313 active served assets matched the gate byte for byte; app entry `assets/app--cptftZL.js`, SHA-256 `96b158d2ce1ac15fbfcd2ec54eb77c4a3cd46005318146d26689c4351f60187f` |
| Storage | Lost-acknowledgment multipart and staged-object probes verified 10/12 MiB, one cloud write each, and cleanup |
| Browser | Authenticated Home, Analytics, Media Library and Versions rendered; interrupted 13,811,436-byte video showed connection interruption and Retry; same upload session completed with HTTP 201; playback reached readyState 4 at 960×540, duration 24 seconds |
| Fixture cleanup | Exact original SHA-256 matched S3; thumbnail/social-video conversions present; no post references; synthetic record 1740 and all three cloud objects removed; library search found no file |

FPM loads the legacy read-only mounts through the image alias into PHP 8.5's
actual configuration directory. Native upload progress remains Off and cannot
be re-enabled with `ini_set()`. Normal multipart bytes matched; upload/post
limits remain 500M/520M. No environment values changed; Docker reordered the
environment list, so preservation was verified as key/value maps.

The final checkpoint is
`/root/mixpost/backups/php8512-upgrade-20261022/cutover/`. It retains a quiesced
database dump, schema/data/settings fingerprints, both Compose files, every
mounted file and previous startup gates. The parent audit directory also has
`rollback-image.tar.gz`, the previous exact frozen image. Restore the matching
image/gates/configuration together and reconcile later writes before any database
restore. Do not replace the database merely because an older image is selected.

## Explicit verification limits

The 501 MiB browser fixture was rejected with HTTP 422 during initiation and
sent **no chunks**. The application message states the 500 MB limit but omits
the selected file size. The upload components/hooks are byte-identical to the
previous 7.1.2 deployment: this is a pre-existing discrepancy in the playbook's
detailed-message expectation, not a new regression. That message-detail check
is recorded as unmet, rather than counted as passing.

The licensed runtime lacks PHPUnit and PDO SQLite; the standalone Pro suites,
real routes, report and queue checks ran instead. An existing authenticated
session survived; Google SSO settings and its login button were verified, but a
fresh Google sign-in was not repeated. No naturally scheduled publication was
witnessed, and no test post or email was sent. The user's mixed checkout was
preserved; source and audit changes are in PR #7, separate from production
cutover proof.
