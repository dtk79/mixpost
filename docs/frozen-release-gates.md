# Frozen Mixpost release gates

The July upload safeguard was retired on an upstream-adoption assumption. The
October 10 incident reproduced its unsafe response handling in Pro 7.0.4. Patch
retirement must now be demonstrated against each exact candidate; the upload
contract remains required even when upstream supplies the implementation.

The [customization documentation](customizations/README.md) and machine-checked
[modification register](customizations/modifications.json) record every retained
behavior. The local-upload candidate advances the contract to
`video-upload-local-storage-v2`; the current production recovery release remains
v1 until a reviewed cutover installs the matching wrapper and evidence together.
Never replace the live wrapper with v2 while its app only has v1 evidence.

## Enforcement

`ops/scripts/verify-frozen-upload-release.py` resolves an existing image to its
immutable ID, snapshots current read-only overrides, and exports the exact Pro
source and published assets without copying the app environment or storage.
It installs locked frontend dependencies, runs all 21 frontend/queue and 12
backend recovery checks, and all 18 Bluesky publishing checks, then rebuilds the entire frontend, and compares every active
manifest asset byte for byte with the candidate's published build. Compiler
runs can assign different chunk hashes; the validator allows up to three builds
from unchanged inputs and accepts only an exact match. It records each attempt
and never normalizes filenames or ignores mismatched bytes. Retained old
chunks for open tabs are allowed. Missing assets, manifest imports, tests, or
failed builds block issuance. A failed rerun removes its output gate.

The validator also reproduces root-owned temporary media parents in an isolated
container, requires a write attempt as `www-data` to fail, invokes the actual
startup repair function, and requires create/write/read/cleanup to pass as
`www-data`. The startup guard requires this permissions suite. Only shared parents
are repaired; existing upload sessions and media files remain intact.

The sixth suite has 17 local-storage checks: deterministic transfer, exact
size/digest verification, lost-acknowledgment reconciliation, local retention,
readiness, cleanup recovery, and capacity refusal. It uses no real storage calls.
The separate `LocalMediaStagingIntegrationTest.php` must run in the isolated Pro
rehearsal; it checks actual database/file/request/video behavior. Container
replacement on a private persistent volume and the controlled single-PUT
readback probe are separate runtime evidence. No real publication is authorized.

The backend checks run in a disposable, read-only, network-disabled container
without production volumes or environment. Validation starts no app processes,
publishing workers, migrations, database connections, or real storage uploads.
It cannot prove that a future database migration or real browser upload works;
those remain separate required rehearsal and post-cutover checks.

The asset-build gate also opens every published manifest asset inside the exact
image as `www-data`. Root hash checks cannot detect unreadable private build
permissions. Bake directories as 0755 and files as 0644, then require this check;
do not depend on a manual permission repair after cutover.

The existing mounted `peachy-start.sh` validates passing evidence and the actual
source, compiled assets, Composer/npm locks, and override fingerprints **before**
Artisan, migrations, cron, Horizon, or PHP-FPM start. It requires all eight suites;
there is no “superseded upstream” exemption. The evidence is in the existing
storage volume at `/var/www/html/storage/app/.release-gates/`. Multiple tested
release records can coexist, allowing a staged candidate and the current or
previous tested image to start. Each must match its own tested files.

The `--check-plan` command additionally binds promotion to the immutable image,
current validator/test hashes, unchanged running baseline, mount inventory, and
effective Compose configuration. Only the app image may differ. Both Compose
files, environment, protected services, storage, and networks must be preserved.
Changing other configuration needs a separate reviewed plan and fresh baseline.

This is an operations guard, not a security boundary against root changing or
removing the wrapper/evidence. Preserve the wrapper's read-only mount and the
existing frozen startup entrypoint. The Infra generic updater remains disabled.

## Candidate procedure

Copy the reviewed repository's `ops/scripts/verify-frozen-upload-release.py`,
`ops/production-overrides/peachy-start.sh`, the six upload test files, and `BlueskyPublishingResilienceTest.php` to the
same relative paths under `/root/mixpost/release-tools/`. Use a frozen candidate
built from exact licensed source and complete assets; never use `latest` or the
archived 6.2.0 defaults. The current validator requires Python 3.11+, Node/npm,
Docker, and the package's locked npm dependencies. A changed upstream interface
requires adapting/reviewing the harness while retaining the failure cases;
failed tests must not be waived.

On the host, choose a new private audit directory and an exact existing image:

```bash
cd /root/mixpost
candidate='peachy/mixpost-pro:EXACT-REVIEWED-TAG'
audit="/root/mixpost/backups/release-$(date -u +%Y%m%dT%H%M%SZ)"
python3 release-tools/ops/scripts/verify-frozen-upload-release.py \
  --image "$candidate" --output "$audit"
```

Preserve `upload-gate.json` and its private logs. Run `python3 ops/scripts/check-customization-register.py --release-gate AUDIT/upload-gate.json` from the reviewed checkout; a live-only safeguard missing from Git or mismatched runtime source must block promotion. Rehearse the exact candidate
against an isolated restored database with inert external services and run the
provider/content/thumbnail regressions in the update playbook. Checkpoint the
database and current release after publishing work drains. Save both Compose
files, effective configuration, every mounted file, current gate records, and
protected service IDs. Database-changing migrations require a database-aware
rollback plan; do not restore an old database over later user writes blindly.

Drain long-running work while the web application remains available, before the
short maintenance window. Recheck active uploads and imminent publishing before
replacement; postpone if work cannot safely drain. Give every rehearsal unique
audit/resource names and remove only resources owned by that run. An aborted run
must not reuse an older success record.

After all rehearsal/checkpoint checks pass, stage the candidate evidence without
removing the current gate. The filename is the exact image ID's hexadecimal part:

```bash
image_id=$(docker image inspect "$candidate" --format '{{.Id}}')
gate_name="${image_id#sha256:}.json"
docker exec -i -u 0 mixpost-mixpost-1 sh -c \
  'umask 077; mkdir -p /var/www/html/storage/app/.release-gates; cat > /var/www/html/storage/app/.release-gates/incoming.tmp' \
  < "$audit/upload-gate.json"
docker exec -u 0 mixpost-mixpost-1 mv \
  /var/www/html/storage/app/.release-gates/incoming.tmp \
  "/var/www/html/storage/app/.release-gates/$gate_name"
```

Configure only the reviewed app image, keeping both Compose files effective,
then run the immediate pre-cutover check. It prints no environment secrets:

```bash
python3 release-tools/ops/scripts/verify-frozen-upload-release.py \
  --image "$candidate" --check-plan "$audit/upload-gate.json"
docker compose -f docker-compose.yml -f docker-compose.dashboard-overrides.yml \
  config --quiet
```

Then follow the [parent playbook's database-aware cutover](mixpost-update-playbook.md):
keep the candidate inert for migration, run the guard's `--check-release-only`
inside that container before initialization, compare data fingerprints, and only
then restore the normal two-file guarded entrypoint. Do not start normal workers
directly from the preflight example. If reviewed host overrides changed after
validation, rerun against the final mounted combination during the maintenance
window and stage the resulting proof before replacing the running baseline.

Recheck the actual image/source/assets, all mounts, MySQL/Redis container IDs,
health/login, authenticated upload/interruption/retry, Horizon, scheduler, and
provider state. Run `ops/scripts/verify-upload-recovery-storage.php` as the
controlled live storage probe and require completion, content verification, and
cleanup. Run it with `docker exec -u www-data`, never root: a root probe masks
permission failures and can leave shared temporary parents unwritable. Also
run `MediaTemporaryPermissionsTest.php` as `www-data` in the running app before
resuming traffic; confirm the configured temporary path is writable if it differs
from the default. Record unavailable browser/provider checks explicitly. Update the
frozen release record only with observed evidence and the rollback checkpoint.

## Guard regression checks

```bash
python3 ops/production-overrides/tests/FrozenUploadReleaseGateTest.py
python3 ops/production-overrides/tests/BlueskyPatchApplicationTest.py
python3 ops/production-overrides/tests/TwitterMediaPatchApplicationTest.py
bash -n ops/production-overrides/peachy-start.sh
```

The eighth suite is `twitter-media-reads`: all 17 checks from
`TwitterMediaReadCostTest.php` must pass against the exact candidate with its
effective overrides. Copy that test with the other release tools. The provider
resource trait and import job are mandatory fingerprints. A missing/failed suite,
changed source or omitted fingerprint blocks startup. Rebase/apply the
[X media cost patches](customizations/x-media-read-costs.md) for every update and
run `TwitterMediaReadIntegrationTest.php` against isolated restored data.
For an override-only release on the same image, checkpoint the old same-image
gate before replacing it and retain the matching old wrapper/overrides for rollback.

The CI workflow runs these guard checks without licensed Pro credentials. They
reject missing/failed suites, changed source/bundles, incomplete evidence,
dropped overrides, stale image/test evidence, protected-service/config drift,
and loss of storage. They also verify staging a new gate retains current-image
and tested rollback startup. CI does not replace exact-candidate Pro validation.

## Rollback

Retain passing prior release records and the matching override/config snapshots.
Restore the exact prior tested image and its reviewed files; its gate must match
before startup. Preserve MySQL, Redis, volumes, and networks. Follow the matching
database-aware checkpoint plan if migrations changed data/schema. An older image
with no passing upload contract is deliberately blocked; use its full reviewed
pre-guard checkpoint only for an explicitly chosen emergency rollback.

## October 10 installation

The current 7.0.4 upload-recovery image passed exact source tests and a fresh
byte-identical build (the second of two attempts from unchanged inputs). The
28 upload and 22 guard checks passed. The unpatched image was rejected by both
the validator and startup guard, and wrong-image promotion evidence was rejected.
Installation/negative-test evidence is under
`/root/mixpost/backups/upgrade-guards-20261010/`. This guard installation does not
upgrade/restart the app or change the database; all 52 mounts and the app/MySQL/
Redis container IDs were unchanged. Live health/login and Horizon checks passed.
The affected user's specific
Chrome video remains a separate live acceptance check.

## Reviewing changed mounts

`--candidate-overrides` accepts a private JSON mapping from existing read-only
container destinations to reviewed candidate source files. It snapshots those
files for validation and still verifies that the running baseline did not change.
Unknown/new mount destinations are rejected. New image-baked files are mandatory
source fingerprints. This flag creates evidence only; it installs no live file.
Before app-only cutover, install the reviewed bind files under the existing mount
paths and pass `--check-plan` against those exact bytes. Preserve previous files,
wrapper, evidence, and image for a compatible rollback.

For local uploads, rollback also requires no pending staged records, or a tested
rollback image that retains local readers, delivery jobs, and publication guards.
An old image plus a healthy login cannot safely process a new durable outbox.

## Bluesky contract added October 23

Every new gate must include all 18 checks from
`BlueskyPublishingResilienceTest.php`, covering unresolved mentions, optional
link-card thumbnails, actual record errors, and visible partial-thread failures.
Its publishing/parsing files are mandatory fingerprints, including the registered
image-baked publishing trait. Preserve/reapply the
[versioned Bluesky patches](bluesky-publishing-resilience.md) before validation.

The v2 local-storage contract and all six existing suites remain required. This
adds a seventh suite; it does not waive upload, permission, or storage checks.
An older gate without the Bluesky suite cannot satisfy the updated wrapper.
Stage passing evidence before installing the wrapper; rollback must restore the
previous exact wrapper, gates, mounted files, and image together, or use a newly
tested rollback candidate.
