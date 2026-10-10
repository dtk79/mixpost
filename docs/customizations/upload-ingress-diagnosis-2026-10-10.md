# Slow ingress: active-user evidence and protocol experiment

Status: diagnostic candidate prepared and isolated protocol checks passed.
The production connection settings have **not** been changed by this experiment.
The deployed app remains the verified progress r2 release described in
[upload-transfer-progress.md](upload-transfer-progress.md).

## Actual affected upload

At 21:07:49–21:15:22 UTC on October 10, the affected computer uploaded
`VID009 Logy Drake Beau Butler Part 2 Preview.mp4`, 98,344,091 bytes, session
`04e87aba-a6c3-4324-bcdb-25f2f7df824b`. The advertised route was persistent
local storage (`disk=public`, `is_s3=false`). Ten successful piece requests used
HTTP/2. Their durations were 55.134, 46.537, 48.012, 43.548, 46.405, 119.922,
3.441, 5.230, 71.061, and 11.057 seconds. All returned HTTP 200; completion
returned HTTP 201 in 488 ms, accepting media 1586. This is roughly 7.5 minutes
of transfer, with half a second for final confirmation. Early pieces averaged
about 1.7 Mbps, but later pieces varied substantially.

Mixpost and proxy CPU stayed low, the volume had 79 GiB free, no proxy rate
limiter was attached, and nginx had no reported upload errors. An incoming TCP
connection from the affected peer ended with 98,580,036 bytes received,
68,730 incoming data segments, 7,046 out-of-order packets and 370 socket drops;
minimum RTT was approximately 170 ms. The socket counters show transport
reordering/drops, but do not identify whether the sender, access network,
internet path, receiver, or some combination caused the slowdown. Do not infer
that low server CPU proves the network is healthy.

Media 1586 subsequently needed one background S3 retry. Read-only HEAD probes
found the original (98,344,091 bytes) and thumbnail present while a second
derivative was still absent. Local bytes remained available. Automatic delivery
reached `ready` on S3 at 21:18:06 UTC, without a manual requeue or resubmission.
The precise exception operation/cause was not retained by the existing log.
This is a separate cloud-delivery issue; it did not hold up the 488 ms local
acceptance response. Do not delete or republish this user media as a test.

## Controlled protocol evidence

Unauthenticated synthetic 10 MiB multipart POSTs targeted the same upload
endpoint from the connected test computer. They were rejected with HTTP 419
before the upload handler and created no media or chunk files. They measure
transport/nginx body reception, not authenticated upload completion. Their
requests appear alongside the user session in proxy logs; exclude 419 responses
when calculating the actual user's successful piece times.

The initial separate connections took 13.360 s on HTTP/2 and 2.481 s on HTTP/1.1.
Eight further alternating fresh-connection probes gave HTTP/2 times of 12.936,
3.407, 3.785, 3.645 s, versus HTTP/1.1 times of 2.775, 2.450, 2.594, 3.193 s.
This is a variable difference, not proof of a deterministic HTTP/2 failure.

Four successive POSTs on one reused connection gave HTTP/2 times of 3.560,
2.070, 1.908, 1.939 s, versus HTTP/1.1 times of 2.727, 0.713, 0.629, 0.673 s.
The final three requests negotiated no new connections. This strengthens the
case for a controlled protocol comparison, but the test computer is different
from the affected one. Both protocols can transfer quickly here. A local test
cannot establish that the affected computer's transfer is fixed.

## Prepared experiment and acceptance

The candidate files are:

- `ops/production-network/mixpost-upload-http1.yml`: a named Traefik TLS option
  that advertises only HTTP/1.1.
- `ops/production-network/docker-compose.upload-http1.yml`: attaches that option
  only to Mixpost's existing router. This review overlay expresses one label;
  merge that label into the existing dashboard override layer for activation,
  preserving both current Compose files. The baseline validator reads the
  files recorded on the running container; it cannot authorize an untracked
  third activation layer.

TLS negotiation applies to the entire Mixpost hostname, including pages, rather
than an individual upload URL. Other sites retain their existing protocols.
The candidate changes no storage route, app image, database schema or upload
retry behavior. Applying its router label requires an app-only recreation;
the shared proxy does not require a restart. Existing HTTP/2 connections may
survive a setting change, so the affected browser must fully close its Mixpost
tabs and open a fresh connection for the comparison.

`ops/scripts/verify-upload-http1-proxy.py` tested the actual candidate configuration
on the current immutable Traefik image
`sha256:eb328e2c806c53aafbbace6c451fa54d268961261a85452fcf0fb752a30c17be`.
The container had no network access, production volumes, Docker socket, or
published ports. Offering HTTP/2 negotiated HTTP/1.1 for Mixpost and HTTP/2 for
a control hostname. Reverting the option reference restored HTTP/2 on new
connections. The deliberately absent backend returned expected HTTP 502;
these checks prove TLS selection and rollback, not media acceptance. The
candidate TLS config SHA-256 was
`9204fc23a1b7383937c17fb89ceb8d40b6f2fad80459720101a837e237a89df1`.

Effective production Compose rendering with the review overlay changed exactly
one router TLS label; all services, image, environment, mounts, and networks
remained equal. Sanitized session/probe evidence, the isolated TLS/rollback
result, and that Compose comparison are retained under
`/root/mixpost/backups/upload-ingress-20261010-2119`. The preservation register
and its 15 tests passed, together with 23 frozen-gate guard tests. These checks
do not substitute for the affected-machine speed comparison or the fresh
production gate required below.

Before production activation, checkpoint the current effective Compose files,
proxy configuration, current container/image/mount identities, wrapper/gate,
and health. Stage the reviewed label change in the effective configuration;
run the frozen validator against the same exact app image and current baseline
to issue new evidence for the changed Compose configuration. The previous
gate's Compose fingerprint cannot authorize the new label. Preserve all 52
mounts and the startup guard. Keep web service available while workers drain,
then perform the short app-only replacement. Never restart MySQL/Redis, clear
queues, restore old database dumps, or change shared default TLS options.

Verify new connections negotiate HTTP/1.1, all source/mount hashes and worker
settings remain intact, and health/login work. Then time the same file from the
**affected computer**, recording the start, each piece, and completion. Compare
with a Drive upload on that computer if available. Retain the experiment only
if the affected-machine evidence improves; otherwise restore the prior router
label/configuration and corresponding release evidence, and verify HTTP/2 on
new connections. If retained, register the host TLS file and Compose behavior
as required customization evidence before future upgrades.

If the protocol test does not improve affected-machine throughput, proceed to
browser Network timings and a second access network. Consider bounded parallel
piece transfer only after comparing aggregate bandwidth on that same machine;
preserve acknowledged-chunk resume, cancellation, idempotency, and no ambiguous
completion replay. Do not label a progress-display improvement as a speed fix.

## Repeatable private diagnostics

Run `ops/scripts/diagnose-upload-ingress.py <session-uuid>` on the Docker host.
It reads a bounded proxy-log tail and persistent chunk metadata, samples matching
TCP counters for 10 seconds, and emits sanitized JSON without addresses, full
URLs, headers, credentials, filenames, request bodies or media content. It
does not capture packet payloads, send test requests, alter storage, reload the
app, or enqueue work. TCP counters correlate the peer, not individual HTTP/2
streams. The live accepted session and an invalid-UUID rejection were checked.
