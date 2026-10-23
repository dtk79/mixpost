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
