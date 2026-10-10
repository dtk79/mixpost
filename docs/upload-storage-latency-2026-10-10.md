# Upload storage latency investigation — October 10, 2026

The remaining upload delay is reproducible between the Mixpost server and its
Hetzner HEL1 object storage. The frontend retry and temporary-directory repairs
are deployed, but storage latency is not resolved.

## Direct reproduction

Eight controlled tests used the running Pro `ChunkedUpload` implementation as
`www-data`, with 12 MiB of synthetic bytes split into 10 MiB and 2 MiB parts.
They completed multipart storage, exercised the same-disk final copy, read back
identical bytes, and verified remote/local cleanup. No Media record was inserted,
no user upload was modified, and no publishing job was dispatched.

| Test | Full probe time, including readback and cleanup |
| --- | ---: |
| Original request behavior, 60-second diagnostic attempt cap | 62.240 s |
| `Expect` disabled, 60-second cap | 2.497 s |
| Original behavior, 20-second cap, repeat 1 | 1.885 s |
| `Expect` disabled, 30-second cap, repeat 1 | 8.685 s |
| Original behavior, 20-second cap, repeat 2 | 1.349 s |
| `Expect` disabled, 30-second cap, repeat 2 | 1.385 s |
| Original behavior, 20-second cap, repeat 3 | 2.116 s |
| `Expect` disabled, 30-second cap, repeat 3 | 1.593 s |

In the slow test, the first storage request sent all 10,485,760 bytes but never
received a final success response. It received interim HTTP 100, then hit the
diagnostic's 60-second cap. TCP inspection showed the body had been acknowledged
and no bytes remained queued. The SDK replay finished in 0.512 seconds. The
second part took 0.110 seconds, completion plus existence check 0.087 seconds,
final copy 0.229 seconds, and readback 0.484 seconds.

The timeout caps apply only to the diagnostic process. The production client
has no explicit HTTP attempt timeout configured. SDK retries were preserved;
the 62-second example includes an SDK replay within one application call.
CRC32 checksums remained enabled. Repeated comparisons do not establish that
disabling `Expect: 100-Continue` fixes the stalls, so production headers and
checksums have not been changed. cURL's recorded `http_version: 2` denotes
`CURL_HTTP_VERSION_1_1`; it does not mean HTTP/2.

Private raw evidence is retained on `mixpost-hetzner` under
`/root/mixpost/backups/upload-latency-20261010`.

## Broader checks

- Host memory was available, disks had room, and sampling showed no active swap
  pressure, substantial I/O wait, app OOM, or app CPU throttling.
- PHP workers, MySQL, Redis, Horizon, scheduler, and public health were running.
  No new application error explained the latest successful small upload.
- The video request uses deferred conversion; full video encoding runs in jobs
  after upload. The synthetic reproduction invokes no video conversion at all.
- A 12 MiB body from Dan's Mac to the public proxy completed in 3.010 seconds.
  This was an unsupported-method POST to `/api/health` returning 405, not an
  authenticated media upload or a test of the other user's Windows connection.
- The latest 12,134,858-byte video record was created at 16:12:26 UTC. Existing
  checkpoints do not provide per-operation timings for that specific browser
  attempt, so its exact delay is not inferred from those timestamps.

## Provider evidence and mitigation limits

[Hetzner's HEL1 incident](https://status.hetzner.com/incident/382ed0e5-f1c4-45ec-a7a3-326d3970d6b3)
is still in Monitoring. Its October 8 update reports intermittent elevated
response times and stalled requests, with background bucket migrations to address
I/O pressure. Our configured storage endpoint is in HEL1. This is consistent with
the observed storage acknowledgment stall; Hetzner has not confirmed whether our
particular bucket is on an affected backend or migration schedule.

The existing application safeguards recover failed chunk acknowledgments and
preserve progress. They cannot make an in-flight storage request respond promptly.
Shorter per-attempt timeouts with reconciliation could bound that wait, but need
separate exact-release testing: blind retries of multipart completion or media
insertion can duplicate records. A global filesystem timeout would also affect
publishing, downloads, and other storage consumers. Neither was changed in this
investigation. A persistent local staging path with background storage delivery
would decouple user confirmation from remote writes, but requires durable pending
media state, quotas, recovery, and a publishing readiness check.

Provider escalation can use the 16:16–16:19 UTC traces and request a bucket-specific
backend check. No support message was sent. A bucket/region migration requires
an inventory of keys, copy validation, URL compatibility, cutover, and rollback;
no storage destination, user data, or production image was changed.

## Historical local storage question

The July 27 `production-image/app.tgz` archive and July 28 upgrade's saved Pro
source were checked directly on the host. Both `ChunkedUpload` implementations
choose S3 multipart when the configured media disk is cloud storage: initiation,
each part, and completion run within the upload requests. Their local-disk branch
applies when the selected disk itself is local; it does not stage locally and
queue a later cloud transfer. The archived `MediaUploader::uploadAndInsert()`
stores the source on the configured disk before creating the media record.

The retained request overrides defer video conversion and queue the optimized
social-video derivative. That background processing does not defer the original
object-storage write. No durable local-first/background cloud-delivery mechanism
was found in these verified July versions. Earlier releases or one-off media
migrations were not exhaustively audited, so no claim is made that local storage
was never used historically.

## Maintained probe verification

The maintained diagnostic passed PHP syntax checking in the running application
and a live 12 MiB run as `www-data`: 1.415 seconds, exact-byte readback,
remote/local cleanup verified, no Media insertion. This ninth successful probe
does not establish that the intermittent provider stalls have ended.

## Repeatable check

`ops/scripts/measure-upload-storage-latency.php` records whitelisted transfer
timings per storage operation and HTTP attempt. It deliberately excludes URLs,
request headers, object keys, bucket names, credentials, exception messages,
and traces. Run explicitly, once, as the app user:

```sh
docker exec -i -u www-data mixpost-mixpost-1 php /dev/stdin \
  < /root/mixpost/backups/upload-latency-20261010/measure-upload-storage-latency.php
```

The script creates a temporary multipart object and a unique
`ops-upload-latency/<uuid>/` copy, attempts independent cleanup even after failure,
and exits nonzero unless readback and cleanup pass. It is not a health endpoint
or scheduled job. Each HTTP attempt is capped at 60 seconds; SDK retries can
extend the command beyond that. A lost initiation acknowledgment can leave a
provider-side multipart upload with no returned ID; in that case cleanup is not
claimed as verified. Failed cleanup needs operator review of the private probe
session, never deletion of a user upload.
