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

## Validated candidate

The candidate is `peachy/mixpost-pro:7.0.4-local-uploads-progress-20261010`,
immutable ID `sha256:0d25c7f755a34d9d970a4a2410477ac55f4890f9f2099ec55eba9cef671decad`.
Its parent is the verified local-storage release
`sha256:5bffe8f060b2846b07a8f75450c51cc72f6c2f97f899ecf9c3f2d13b7128a333`.
All six exact-image suites passed, the entire frontend rebuild matched the
published assets, and all 57 registered customizations plus the startup wrapper
matched the candidate gate. Private evidence is under
`/root/mixpost/backups/upload-progress-20261010/validated`. This candidate has
not been deployed. No app restart has occurred during the active user upload.
