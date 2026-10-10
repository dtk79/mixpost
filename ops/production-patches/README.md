# Pro frontend patches

## Video upload network errors — Pro Team 7.0.4

`video-upload-network-errors-7.0.4.patch` is rebased against the source in the
running `peachy/mixpost-pro:7.0.4-8def2a9` image, inspected on October 10, 2026.
It was deployed with the companion recovery patch on October 10, 2026, as
`peachy/mixpost-pro:7.0.4-upload-recovery-20261010`. The running source and served
frontend hashes match the tested candidate; the controlled live S3 recovery
check passed. The affected user's specific Chrome upload remains unverified.

The companion `video-upload-recovery-7.0.4.patch` adds server reconciliation and
resumes retries in the existing Vue upload queue. Apply it after this patch.
See [video upload recovery, evidence, gates, and rollback](../../docs/video-upload-recovery.md)
for current deployment status and limitations.

All future frozen candidates must pass the [enforced release validator and
startup guard](../../docs/frozen-release-gates.md). Retiring the local patch
does not retire its behavior contract; exact unpatched upstream source and
compiled assets must pass the same recovery cases.

### Confirmed incident evidence

- `TIS081_SE_AFFILIATE_Preview.mp4` is 90,220,620 bytes, below the configured
  500 MiB video limit. Its upload session recorded only parts 1 and 2 of 9.
- Its initiation succeeded. Two chunk requests succeeded, while the remaining
  attempts ended with HTTP 499. Other attempts in the same client connection
  also returned HTTP 502. A separate regular upload succeeded during this period.
- The application had correct log permissions, about 80 GB of free disk space,
  no container restarts, and no corresponding Laravel upload exception.
- The screenshot's `Cannot read properties of undefined (reading 'status')`
  is reproduced by passing a network error without a response through the
  installed Pro `bootstrap.js` interceptor. `useNotifications.js` has the same
  unsafe assumption.
- The precise cause of the transport interruptions is not established. The
  recorded 499 responses indicate canceled/disconnected requests, but do not
  identify whether the browser, client network, or an intermediary caused them.
- The affected uploader uses Chrome on a PC without a VPN. Further live checks
  found proxy read/write timeouts of 30 minutes and a 70 MiB Nginx body limit;
  the failed 10 MiB chunk requests usually terminated within 1–3 seconds.
  These observations do not fit either configured limit being exceeded.
- The configured chunk size is 5 MiB, but the installed package clamps larger
  files to a 10 MiB minimum. A runtime check of the planner returned nine
  10 MiB chunks for this video. Changing the setting alone will not reduce
  these requests; a smaller-chunk source change would require its own validation.

### Follow-up: confirmed storage timeout at 44 percent

The later retry created session `359042d1-2dfe-431f-af55-195baeba6392`.
Its session recorded parts 1 through 4 of 9, matching the screenshot's 44 percent.
The fifth request started at 06:52:56 PDT, ran for approximately 183 seconds,
and returned HTTP 500. The corresponding Laravel exception at 06:55:58 PDT
was `Aws\\S3\\Exception\\S3Exception` from `UploadPart`, with the storage error
`GatewayTimeout (server): The server did not respond in time.`

A subsequent read-only S3 `ListParts` returned parts 1 through 5. Part 5 therefore
reached storage, but the failed request did not record its acknowledgment in the
local session. The file has not been finalized into a media record.

Hetzner's [HEL1 incident](https://status.hetzner.com/incident/382ed0e5-f1c4-45ec-a7a3-326d3970d6b3)
was still marked Monitoring when checked on October 10. Its October 8 update
reports elevated response times and stalled requests for some buckets, and
background bucket migrations to address storage I/O issues. This is consistent
with the observed storage timeout; it does not prove that every earlier 499/502
had the same cause.

The 11 percent steps are normal for nine chunks: progress uses completed chunk
acknowledgments and reserves the final percent for finalization. The confirmed
44 percent stall is a storage-side failure. Browser or connection changes are no
longer the primary next step for this attempt. Further upload resilience work
should reconcile remotely accepted parts after lost acknowledgments and preserve
retry safety; the frontend error patch alone does not accomplish that.

### Scope

The patch preserves the original network error through the interceptor, makes
notifications safe without a response, and gives regular and chunked uploads a
clear connection-interruption message. Existing chunk retries remain intact.
It does not repair the underlying connection or resume a partially uploaded
file. Retrying from the UI starts a new upload session.

### Apply and verify in an isolated candidate

Set `candidate_package` to an isolated copy of the exact Pro package. Apply from
this repository:

```sh
patch --batch --forward -p1 -d "$candidate_package" \
  < ops/production-patches/video-upload-network-errors-7.0.4.patch
patch --batch --forward -p1 -d "$candidate_package" \
  < ops/production-patches/video-upload-recovery-7.0.4.patch
MIXPOST_FRONTEND_SOURCE_DIR="$candidate_package" \
  node --test ops/production-overrides/tests/VideoUploadNetworkErrorsTest.mjs \
  ops/production-overrides/tests/VideoUploadQueueRecoveryTest.mjs
```

Install the package's locked npm dependencies before the Vue queue suite. The
16 frontend/queue checks exercise response-less failures, HTTP errors, CSRF
replay, notifications, successful and exhausted retries, retained session and
folder/progress state, cancellation, slow confirmations, expired sessions, and
completion replay protection. The backend suite in the linked document adds
12 storage/ownership/concurrency checks. The initial six error-handling checks
failed four times against installed unpatched source and passed with the first
patch; the full suites require both patches.

Rebuild the complete Pro frontend and package it through the established frozen
release playbook. Do not change the live hashed JavaScript assets or pin an
individual bundle/manifest. Verify a real upload from the affected browser after
cutover; passing these tests is not proof of a successful live video upload.
