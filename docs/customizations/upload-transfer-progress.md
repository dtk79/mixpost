# Upload transfer and confirmation

On October 10, after the local-storage cutover, the upload panel displayed
`0% — Waiting for upload confirmation…` while the first request body was still
arriving. Chunked uploads advanced progress only after a whole 10 MiB request
returned; a fixed five-second timer incorrectly labeled ongoing transfer as
confirmation waiting.

Preserve `video-upload-progress-7.0.4.patch` after the network-error and recovery
patches. It adds browser upload events, byte-weighted progress across unequal
chunks, and a five-second inactivity timer that resets on transfer events.
`waiting` applies only after the browser reports the entire current body sent;
absence of transfer events reports a slow connection. Neither state proves the
server has accepted those bytes. Only acknowledged chunks are resumable. A
failed attempt rolls estimated progress back to acknowledged bytes; late events
from failed or canceled attempts cannot alter progress. Final acceptance keeps
100% reserved until the server returns success, including ordinary uploads.

The `upload-progress/Dockerfile` overlays the two patched frontend files and the
complete rebuilt asset set on the verified local-storage image. It retains old
published asset chunks for already-open tabs. No backend, database, media route,
or storage configuration changes are part of this overlay. Verify the parent's
immutable ID before building and validate the complete candidate afterward.

The required network suite now has 18 checks. Five additional cases cover
partial first-chunk progress, activity after inactivity, body-versus-acceptance
waiting, rollback and stale events, an unequal final chunk, and cancellation.
The asset check requires the slow-connection and saving labels in compiled
assets. The startup guard requires all 18 network checks. Do not retire these
behaviors based on an upstream version number.

## Incident evidence

The active `VID009 Logy Drake Beau Butler Part 2 Preview.mp4` session advertised
98,344,091 bytes and ten pieces. Initiation returned in 74 ms. Its first three
10 MiB requests returned HTTP 200 after 88,355, 99,341, and 99,408 ms. At
18:15:27 UTC, the app's incoming connection had received 39,537,268 bytes; at
18:16:26 it had received 45,948,895 bytes. The request body was continuing to
arrive, rather than already sitting at the application awaiting cloud storage.

A simultaneous unauthenticated synthetic 12 MiB request to the same media API
returned HTTP 401 in 4.319 seconds, sending 12,583,152 multipart bytes at
2,913,245 bytes/second. Authentication rejected it and no media was inserted.
App and proxy CPU remained near 1%; no proxy rate-limiting middleware was active
on this route. Proxy logs show the user upload and control probe used the same
client address (the address itself is omitted). These observations identify ongoing slow ingress for the user's
session and a separate UI defect; they do not identify the exact client/network
cause. Do not claim the UI repair increases upload bandwidth or that this probe
validates authenticated file acceptance.

The user session completed at 18:27:08 UTC, creating media 1581. The final
request returned HTTP 201 in 998 ms after the final piece arrived. Delivery
reached `ready` on S3 at 18:27:26 UTC with zero failures and no retained local
files. Browser-to-server transfer lasted roughly 18 minutes; final acceptance
and later cloud delivery are separate phases.

Candidate validation, isolated restored-data rehearsal, app-only cutover, and
authenticated browser proof remain separate release requirements. Preserve all
active upload sessions; do not restart an app in the middle of this incident's
transfer to improve the display.

## Verified production release

Production now uses
`peachy/mixpost-pro:7.0.4-local-uploads-progress-20261010-r2`, immutable ID
`sha256:29a8d847ddc61b452a64660a121b2a31664f5bb7908c705b1e2fa60e21e84b02`.
The deployment record was finalized at `2026-10-10T19:11:16Z`; the deploy lock
was released after runtime, browser, delivery, and owned-fixture cleanup checks.
The exact-image validator passed all six suites (52 required checks), a complete
source/build byte match, and application-user reads of every published asset.
All 57 registered overrides and the startup wrapper matched the gate. Live
readback matched all 362 tracked source/asset files and all 52 mount entries;
MySQL and Redis container identities were preserved. The local route, dedicated
media worker, outbox scanner, scheduler, login, and health checks passed.

The corrected image passed inert initialization against a restored
254,190,705-byte checkpoint, unchanged before/after data fingerprints, the local
upload integration, and 11 Pro regression suites. The authoritative current
rehearsal is `rehearsal-r2/result.json`; external networking was disabled, no
production volumes were mounted, and publishing workers were never started.
Do not use earlier candidate/rehearsal directories as current release evidence.

The initial production permission check found that the original progress image
copied its manifest as 0600 and its asset directory as 0700 from the private build
context. Root hash checks and inert data tests passed, but browser pages briefly
returned HTTP 500 because the web process could not read the assets. Temporary
read permissions restored service. The corrected Docker recipe bakes asset
directories as 0755 and files as 0644, and the release validator reads every
manifest asset inside the immutable image as `www-data` before issuing a gate.
The original immutable image fails the new negative permission probe.

The first repackaged candidate also failed exact source/build matching. A fresh
complete build produced the final r2 image, which matched the independent
validator rebuild exactly. No mismatched assets or hash differences were waived.
The original progress image and r1 are rejected intermediates, unsuitable for
rollback or restart. The healthy preceding release is the verified local-storage
image `sha256:5bffe8f060b2846b07a8f75450c51cc72f6c2f97f899ecf9c3f2d13b7128a333`,
with its matching earlier wrapper and gate.

The final fresh production checkpoint is
`/root/mixpost/backups/upload-progress-final-cutover-20261010-190104`, including a
254,193,299-byte database dump, configuration/mount snapshots, preserved legacy
chunk sessions, stable inert fingerprints, runtime proof, and deployment record.
Private candidate/build/gate/browser evidence is under
`/root/mixpost/backups/upload-progress-20261010`. The preceding stable image's
Compose/wrapper checkpoint is
`/root/mixpost/backups/upload-progress-cutover-20261010-184710`. A frontend rollback
must keep current accepted media and the current database; restore the matching
stable image, wrapper, and gate together. Do not restore a database dump over
subsequently accepted uploads.

## Authenticated Chrome proof and remaining incident

Two owned 12 MiB fixtures were uploaded through the real Chrome media library.
A deliberately slowed verification tab (524,288 upload bytes/second) showed 12%
and 39% progress before the first 10 MiB request acknowledgment; it did not label
active transfer as confirmation waiting. Its final request returned HTTP 201 in
400 ms. After removing the test throttle, the ordinary upload was accepted in
4,427 ms from initiation through final confirmation, with its last request taking
302 ms. Both HTTP 201 responses exposed `storage_state=pending` and processing
state while the accepted files were staged locally; both appeared in the library.
The temporary browser network override was reset.

Fixture 1583 was created at 19:05:41 UTC and ready on S3 at 19:05:45 with no
failures. Fixture 1584 was accepted at 19:06:16 but a background S3 operation
raised `Aws\S3\Exception\S3Exception`, logged at 19:07:18. Its local copy remained
safe and available. The ordinary outbox/worker retry delivered it at 19:08:04,
with one failed attempt and no browser resubmission. Five subsequent read-only
HEAD probes on that fixture returned the expected 12,582,912 bytes in 39.01,
6.93, 4.55, 3.71, and 3.60 ms; these do not identify the earlier operation's
specific HTTP status, AWS error, or network cause.

Both delivered originals matched SHA-256
`032c213b323c0cf7af6998da3b26a505926f1803544bd435da86fa165fa09fed`, their thumbnail
and social-video derivatives existed, and their retained local originals were
cleaned after verified cloud promotion. Only owned fixtures 1583/1584 and their
objects/checkpoints were removed after readiness, identity, byte-hash, and
reference checks. User media 1581/1582 remain ready on S3. No draft was created
and nothing was published by the browser tests.

The object-storage problem is intermittent and still requires diagnosis; the
local route prevents it from holding browser acceptance hostage. The original
slow browser-to-server transfer is a separate unresolved issue. The connected
Chrome profile had no user Mixpost tab, so the test above does not prove that the
original slow tab/network is fixed. Future diagnostics should record sanitized
S3 operation, HTTP/AWS error code, connection errno, and duration, without signed
URLs, request bodies, credentials, or account content.

## Upgrade preservation and operational lessons

Progress source was shipped in `20cbc945ed19b24846e0572ff3a5008a65177598`;
asset-permission packaging/gate hardening in
`8feb325704a1e61d5208b5f614a4e4ba468a45f9`. Frozen release guard CI and asset-build CI
passed. The old general CI preservation assertion looked for health registration
in `web.php` and the earlier closure syntax for the home route. It now verifies
health registration in the mounted `AppServiceProvider.php` and preserves the
current conditional home route. All six PHP/Laravel matrix jobs passed on
`8b5837053d19a91392849b9af41b9662e5ca9ff8` (77 tests each).

Before cutover, check scheduled publishing and active uploads/jobs while normal
service remains available. The final cutover waited for an Instagram media
import to finish; its queued work was preserved. Drain long-running work before
entering the short maintenance window. If draining takes too long, restore
normal service and postpone replacement, rather than prolonging user-visible
maintenance or killing an active job. Recheck work immediately before pausing,
then checkpoint and replace only the app.

Every rehearsal needs a unique audit directory and unique owned container/network
names. Cleanup must remove only resources created by that run. Never overlap
rehearsals with reused names or let an aborted run reuse an older success record.
The final r2 rehearsal completed with separate resources and clean teardown.

To reproduce the overlay, export the exact verified local-storage parent into a
private source directory and apply only the progress patch (network/recovery
patches are already present in that parent). Install locked npm dependencies and
rebuild the entire frontend. Populate the registered Docker recipe with both
patched source files and the full asset set. Verify the parent tag's immutable
ID before and after building with `--pull=false` and `--network none`. On future
upstream versions, review/rebase all three frontend patches and local-storage
overrides, require every existing failure case, compare the complete rebuild,
check immutable asset permissions as the application user, then rehearse and
verify the live browser. Editing compiled chunks or replacing only an entrypoint
is insufficient.
