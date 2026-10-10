# Durable local uploads and background storage delivery

Active production route: ordinary media-library and draft file uploads when the
configured final media disk is cloud storage. Existing cloud media is not moved.
The final bucket, object URLs, credentials, and provider integrations stay intact.
See the [October 10 release evidence](local-upload-release-2026-10-10.md).

## Acceptance and delivery contract

1. New chunk sessions use `storage/app/peachy-upload-state/chunked/<uuid>`;
   originals and generated files use the existing persistent `public` Laravel
   disk under `storage/app/public`. Both are inside the existing app volume.
   Browser acceptance performs no S3 initiation, write, listing, or completion.
2. The accepted Media record is the durable outbox. Its server-owned
   `data.peachy_storage` stores destination, pending/ready state, attempts,
   retry timing, and retained local-file cleanup state. Queue dispatch failure
   does not invalidate an already saved file; the minute scheduler re-enqueues it.
3. A unique media job and persistent per-media lock serialize preparation and
   transfer. Video conversion/optimization occurs locally in the background.
   Storage stalls leave accepted local originals/derivatives available for retry.
   Non-MP4 source bytes are retained separately before the vendor converter
   replaces its input; that retained source is cleaned only after cloud promotion.
4. Delivery uses deterministic existing keys and single PUTs with Content-MD5.
   Each attempt has a 10-second connection and 60-second request cap, without
   hidden SDK retries. The next attempt first reconciles size and content-MD5
   ETag. Unknown digests fail closed. No database pointer changes before every
   original/derivative verifies. Current upload limits keep objects below 5 GB.
5. Database promotion changes the original and conversion disk pointers together.
   The source file list remains in the outbox until cleanup succeeds; restart
   after promotion cannot authorize retransmission or strand unnoticed cleanup.
6. Queued and direct publishing for every provider checks storage readiness.
   No pending local file is sent to a provider. Video derivative readiness checks
   remain required. A publication waiting over an hour receives an actionable
   failure; its accepted media remains recoverable and publishing can be retried.

## Recovery, admission, and ownership

The old chunk implementation's owner, size, hash reconciliation, atomic session,
and lock checks remain. Legacy sessions stay on their original S3 route. Local
completion is serialized and finds the same workspace's existing upload UUID
before storing again. Completed checkpoints remain briefly for idempotent retry.
After 24 hours of inactivity, only unreferenced chunk/session directories are
pruned under a nonblocking lock. Pending accepted Media files are never pruned
by the temporary-session cleaner.

Admission checks preserve a 5 GiB free-space reserve plus assembly/copy headroom
and refuse new work when accepted pending media exceeds 20 GiB. These are
admission checks, not reservations for all simultaneous uploads or a volume-wide
quota. Existing file-size/ownership/workspace/folder validation remains required.
The existing publication retry deadline permits waiting releases; the storage
branch enforces its own one-hour deadline and real exceptions remain capped at one.
Storage failure retries back off from 30 seconds to 15 minutes. Worker termination
leaves a 1900-second lease that expires; the database outbox remains authoritative.

## Operations and rollback

Keep `storage/app` on the persistent Docker volume. Verify its parent ownership
as `www-data`, the public storage link/preview, local free space, scheduler,
delivery queue, and Horizon timeouts/retry-after before enabling the route.
Review pending age/attempt count and sanitized `staged_media` log events.
Exceptions contain classes, not signed storage URLs or credentials.

Media workers use the same Redis backend and media queue with a dedicated
`peachy-media` connection: one worker, a 1800-second timeout, and 1900-second
retry-after. Other queues retain their existing retry policy. This prevents the
former 90-second lease from redelivering a long-running media conversion.

The code-only image recipe is `ops/production-patches/local-uploads/Dockerfile`.
`build-local-upload-image.py` requires the expected immutable frozen-parent ID,
checks every copied destination against the register's deployment manifest, and
records the resulting hashes. New helper/request/resource/copy files are marked
`delivery: frozen-image`; existing bind destinations are updated separately
under the guarded cutover. No extra volume or provider setting is required.

Do not switch storage disk globally to implement staging; that changes unrelated
providers/downloads/imports. Do not migrate existing originals or delete user
media. Do not roll back to an image lacking the staging reader, delivery job,
and publication gate while pending records exist. Drain pending delivery, retained cleanup, and queued/delayed staging jobs first,
or retain
those components in the rollback candidate. Database checkpoints must not erase
uploads accepted after the checkpoint.

The implementation is not considered deployed until the frozen release record
contains candidate, rehearsal, app-only cutover, restart, exact-byte delivery,
cleanup, and runtime evidence. Tests and unavailable browser checks are recorded
separately; a healthy login does not prove the upload contract.
