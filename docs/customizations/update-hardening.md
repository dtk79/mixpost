# Update preservation and continuing hardening

## Before changing the candidate

1. Inventory the running immutable image, exact Pro package reference, Composer
   lock, both Compose files, environment fingerprints, queues, media readiness,
   mount destinations/hashes, and persistent storage. Reconcile live-only fixes
   into the source register; preserve unrelated working-tree changes.
2. Snapshot the effective configuration and each override; take the matching
   database checkpoint and preserve the exact tested rollback image/gate. Never
   remove volumes or replace MySQL/Redis as part of an app upgrade.
3. Compare every registered override and image patch with the new upstream
   implementation. Include image-baked files using the audit tool's `--manifest`
   and `--image-source` flags; a bind-only comparison omits them.
   Record retain/rebase/retire for every item, with evidence.
   An apparent upstream equivalent remains required until it passes the same
   failure cases. Removing a mount also requires testing the resulting baked file.
4. Check retained local uploads and delivery backlog. Persistent accepted files
   and incomplete sessions must survive replacement. A rollback without the
   delivery/readiness implementation is unsafe while staged media remains.
   Legacy sessions may still live in the old app's ephemeral
   `storage/mixpost-media/temp/chunked`: snapshot and restore them before each
   replacement starts. Creating the shared parents alone does not preserve them.
5. Reconcile active `networkExperiments` with the live frozen-release record.
   Preserve their host proxy file hash and both live/effective router labels.
   These files live outside the app's 52 mounts and need their own readback.
   Keep the active HTTP/1.1 experiment through app upgrades unless an explicit
   reviewed outcome authorizes retirement; verify new connections and an
   unchanged control-host protocol. App/image success cannot retire this test.

## Required candidate checks

- Run `python3 ops/scripts/check-customization-register.py` and the ordinary CI
  checks. A register pass establishes documentation/mapping completeness only.
- Run the frozen validator against the exact final image and final overrides.
  Run `check-customization-register.py --release-gate AUDIT/upload-gate.json`
  to require every committed override and wrapper to match the validated runtime.
  Require recovery, frontend queue ownership, compiled assets, temporary-parent
  permissions, local acceptance, verified delivery/retry, and publication gating.
- Exercise storage unavailable before initiation, a lost write acknowledgment,
  queue dispatch failure, worker/container restart, duplicate completion,
  concurrent completion/abort, cross-user session access, disk admission failure,
  failed verification, and cleanup interrupted after database promotion.
- Run `python3 ops/scripts/apply-bluesky-publishing-fixes.py --source "$staged_pro"`
  against the staged effective Pro source on every update. Preserve the registered
  image-baked Bluesky publishing trait and the existing Helpers/job bind files.
  Require all 18 Bluesky publishing checks and their source fingerprints in
  addition to the existing upload/storage suites.
- Retain provider-specific safeguards: optimized video readiness, X upload
  checkpoints/media IDs, Instagram retry/limits, Threads/Bluesky media handling,
  notifications, thumbnail reuse, analytics scoping/cadence, and SSO/health.
  Execute their existing focused tests and record any remaining manual checks.
- Restore a production checkpoint into isolated database/cache services. Disable
  mail, broadcasting, social publishing, cron, and external storage. Rehearse
  migrations, boot/routes, data fingerprints, queue/outbox recovery, and mounted
  compatibility. No real external request belongs in this rehearsal.

## Cutover and proof

Use the parent playbook's app-only cutover. Drain active publishing/conversion
work before replacing the app. Stage image-bound passing evidence, check the
promotion plan, keep the candidate inert during migrations, and compare data
fingerprints before normal services start. Keep persistent storage, both effective
Compose files, credentials, networks, MySQL, Redis, and override mounts intact.

After startup, read back image/source/build/mount hashes and protected service
IDs. Verify app-user permissions, public health/login, authenticated library and
draft upload, interrupted upload recovery, local preview, staged queue recovery,
verified remote delivery, Horizon, scheduler, and provider preflight. Record what
was actually observed and which checks are unavailable. Never label Git or CI as
deployment proof. Do not publish unsolicited content to obtain a provider check.

## Continuing hardening

After each incident, add a failing regression before declaring the repair done,
attach the evidence to the incident register, and add the case to future release
acceptance. Review backlog age, disk capacity, retained sessions, worker timeouts,
queue retry settings, and provider/storage behavior after upgrades. Keep probes
explicit and bounded; avoid a health endpoint that writes fixture objects.

Retirement requires equivalent behavior tests on pristine upstream, the exact
compiled result, isolated rehearsal, and live acceptance. Keep the contract after
retiring its implementation patch. No "superseded upstream" waiver is permitted.
