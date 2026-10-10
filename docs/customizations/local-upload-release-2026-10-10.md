# Local-upload release: October 10, 2026

Status: **activated and verified in production** with explicit approval.
The production evidence was finalized at `2026-10-10T17:58:23Z`. New library and
composer uploads now acknowledge persistent local media before background
delivery to object storage. Only the app was replaced; MySQL, Redis, the storage
volume, networks, credentials, and all 52 mounts were preserved.

The subsequent [progress and permission-gate release](upload-transfer-progress.md)
records the currently serving frontend image and its independent live proof.
This record preserves the original local-storage cutover and backend contract.

## Exact release

- Pro package: `inovector/mixpost-pro-team` 7.0.4,
  source `ed541fd0b1b77b839065edf40508143ab9b27ff1`.
- Frozen parent: `peachy/mixpost-pro:7.0.4-upload-recovery-20261010`,
  `sha256:345d1dfe21b4f539da7b6ebf058a5307aebe200f22088bbc7f5d56e519bd6b53`.
- Candidate: `peachy/mixpost-pro:7.0.4-local-uploads-20261010`,
  `sha256:5bffe8f060b2846b07a8f75450c51cc72f6c2f97f899ecf9c3f2d13b7128a333`.
- Startup contract: `video-upload-local-storage-v2`; six required suites.
- Committed customization source: `51d31694a77176d95f0d06cba6df02db8ad6109d`;
  pre-cutover documentation: `2fc2e051ad8f91d6c53ada946b3536b3e3b5a2fa`.
- Delivery mapping: nine new image-baked files; six changed existing PHP bind
  destinations plus the startup wrapper. Existing volume/mount structure stays
  intact. The manifest/register contains 57 PHP/application override records.

Use the immutable ID and current gate, not the tag alone. If any source, test,
wrapper, mount, image, or active asset changes, rerun the affected checks and
issue fresh evidence. A future release needs its own cutover and live acceptance
evidence.

## Production acceptance

A fresh consistent 254,185,511-byte database checkpoint preceded the cutover.
No active jobs or publications due within five minutes were present; the 113
delayed analytics jobs were retained. The exact promotion check and startup guard
passed. Initialization ran with web, scheduler, and workers inactive; stable
database fingerprints matched before services started. Legacy temporary chunk
sessions were restored before the final app started. All 362 tested source/asset
files, all 57 registered overrides, the wrapper, and mount inventory matched.

Health and login returned HTTP 200, unauthenticated API access returned 401,
Google sign-in returned to Dan's existing workspace, and authenticated Home and
Analytics loaded. Horizon and cron were running. The media queue had its dedicated
`peachy-media` worker with the 1800-second timeout and 1900-second retry lease;
the minute recovery scanner was registered and other supervisors excluded media.
Permissions and persistent public-preview linking passed as the application user.

Two generated, valid 12 MiB MP4s went through the normal authenticated Chrome UI:

| Flow | Transfer through acceptance | Final confirmation request | Cloud readiness after record creation |
| --- | ---: | ---: | ---: |
| Media library | 14,466 ms | 356 ms | 2 seconds |
| Composer | 3,773 ms | 304 ms | 4 seconds |

Both completion responses returned HTTP 201, `storage_state: pending`, and
`is_processing: true` with a local preview URL. The actual background worker
then promoted the original, thumbnail, and social-video derivative to S3. Original
byte readback matched SHA-256
`032c213b323c0cf7af6998da3b26a505926f1803544bd435da86fa165fa09fed` for both
files, with zero delivery failures. Local copies were cleaned after promotion.
Browser playback loaded the three-second video without a media error, and the
second upload appeared in the composer. These are controlled observations;
browser transfer time still varies with the connection and file.

A separate live application-user probe recovered an injected lost PUT
acknowledgment with one write, verified 12 MiB by readback, and verified cleanup
in 3,211.06 ms. It inserted no Media record. Both browser fixtures and the
composer's automatically saved, unscheduled test draft were removed after
verification. Original user/workspace/post/destination/media fingerprints matched
the checkpoint again. Account fingerprints changed after normal workers resumed,
with account count unchanged; the inert initialization comparison was identical.
The staged delivery backlog was zero at final readback. No test social publication
was performed.

## Verified behavior

The exact candidate passed the six-suite frozen gate and its startup guard.
Published frontend assets matched a fresh full build byte for byte. The 17
standalone local-storage checks cover verified size/digest, lost acknowledgment,
no replay, retained files, readiness, cleanup recovery, and capacity refusal.
The 22 release-guard checks passed. Fifteen preservation-register/audit checks
reject missing overrides, changed destinations/delivery, missing image mappings,
and omitted image-source exports. The runtime comparison matches all 57
registered sources and the startup wrapper. It caught a live-only Instagram
media-download retry safeguard; that exact deployed code was reconciled into Git
and passed six extra checks for definite rejection, bounded retries, accepted
container exclusion, other failures, and ambiguous transport response exclusion.

The isolated Pro integration used a restored database, private persistent storage,
and an internal Docker network. It accepted 12 MiB with zero S3 calls, enforced
session ownership, returned one record for repeated completion, retained files
through storage failure and queue dispatch failure, recovered the outbox through
the scanner, blocked direct and queued publication, and checked the one-hour
storage deadline. An actual app-container replacement preserved both accepted
12 MiB and an unfinished chunk upload; that upload resumed and delivery recovered.

Real video fixtures exercised local thumbnail/social-video preparation and joint
cloud-pointer promotion. A non-MP4 fixture retained accepted source bytes across
conversion and a storage outage, then cleaned them after verified promotion.
Ordinary image upload, independent copies, exclusion during active delivery,
and Adobe Express replacement/old-file cleanup also passed. Storage in the
integration was a deterministic substitute with external access disabled.

A separate controlled probe used the actual configured object store. It verified
12,582,912 bytes by readback, injected lost PUT acknowledgment, recovered with
one write, and verified fixture deletion. Total probe time was 2,434.15 ms,
including readback and cleanup. This is a controlled observation, not a promised
upload duration. It inserted no Media record and published nothing.

The full restored-database rehearsal ran the inert frozen startup commands,
including storage linking, migrations, database upgrade, and cached boot. Data
fingerprints remained identical. PHP syntax passed. Eleven existing Pro suites
passed: social-video publishing preflight, v7 publishing/checkpoint compatibility,
X usage controls, imported thumbnail reuse, failure notifications, Threads,
Instagram retries, 91-second video duration, X resources, failure explanations,
and YouTube queue registration.

## Private evidence and future rollout requirements

Code-only candidate/build/gate evidence is retained on `mixpost-hetzner` under
`/root/mixpost/backups/local-upload-20261010-image`; database/checkpoint and
rehearsal evidence is under
`/root/mixpost/backups/local-upload-20261010-rehearsal`. Raw database/environment
and licensed-source exports remain private. Disposable rehearsal containers,
network, and storage can be removed after preserving these results.

The fresh cutover checkpoint, configuration/mount snapshots, inert and runtime
checks, sanitized browser timings, fixture cleanup, and deployment result are at
`/root/mixpost/backups/local-upload-cutover-20261010-174445`. Production's
`/root/mixpost/frozen-release.json` records the active image, gate, source commit,
checkpoint, protected service identities, and runtime evidence. The deploy lock
was released after verification.

Before activation, refresh the checkpoint/baseline, drain active work, install
the exact reviewed existing bind files and matching v2 wrapper/evidence together,
and pass the app-only promotion check. Preserve MySQL, Redis, credentials,
networks, both Compose files, and persistent storage. Then verify the serving
image/hashes, media worker/scheduler, authenticated library and draft uploads,
local preview, and background delivery on the live site.

Rollback requires pending delivery and retained cleanup to drain, including
queued/delayed staging jobs, or a tested compatible rollback retaining the staging
reader, delivery job, and publication guard. Never restore a checkpoint over
later accepted uploads. See [local uploads](local-uploads.md) and the
[frozen playbook](../mixpost-update-playbook.md).

Live browser interruption/retry was not exercised in this cutover; duplicate
completion, interrupted sessions, failed delivery, and container replacement
recovery passed in the isolated rehearsal. A naturally scheduled provider
publication was not witnessed during verification. PHPUnit and PDO-SQLite-only
Pro tests were not available in the installed vendor runtime;
the applicable standalone suites above passed. The broader Lite matrix has a
pre-existing health-route test mismatch documented separately; it is not Pro
runtime acceptance.
