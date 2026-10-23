# Bluesky publishing resilience

Status: deployed to production on October 23, 2026, from customization commit
`ad1f9e73ab392696c5a78695d547a81c252692fb`. Historical post recovery is separate.

## Failure evidence

On October 22, 2026, TTB posts 2215 and 2218 failed before record creation because
`rivernorth.bsky.social` returned HTTP 400 `Unable to resolve handle`. The mounted
Helpers implementation caught connection exceptions, but Laravel's retry helper
threw a request exception after the rejected lookups. These are photo posts;
the generic video-preparation failure text was unrelated.

On October 23, post 2228 published its main record, then failed on a thread reply.
The linked page advertised `/signup/new_join_2026/img/logo-white.svg` as its
preview image. Passing that relative path to the downloader threw a URL
validation exception. The main record's provider ID remained stored with a
published account status, hiding the missing reply from the account outcome.

## Maintained patches

These patches target the effective customized Pro 7.1.4 source. They deliberately
modify small sections instead of replacing the provider with a Lite or older
Pro implementation. Preserve all other provider functionality and overrides.

| Patch | Effective target | Contract |
| --- | --- | --- |
| `bluesky-mentions-7.1.4.patch` | `src/SocialProviders/Bluesky/Helpers.php` (existing mounted `BlueskyHelpers.php`) | Bounded retries return unsuccessful identity responses; unresolved mentions remain plain text while valid mentions retain their facets. |
| `bluesky-link-previews-7.1.4.patch` | `src/SocialProviders/Bluesky/Concerns/ManagesPost.php` (registered `BlueskyManagesPost.php`, inside frozen image) | Resolve relative thumbnail URLs against the linked page. Keep the card and publish without its thumbnail if downloading, converting, or uploading that optional image fails. Preserve actual record-creation errors. |
| `bluesky-thread-failures-7.1.4.patch` | `src/Jobs/AccountPublishPostJob.php` (existing mounted file) | A terminal exception with missing Bluesky replies records failure and emits the normal failure event, preserving live provider IDs. Completed threads stay successful. Generic errors no longer blame video preparation. |

The thumbnail patch accepts only HTTP(S) image URLs and retains temporary-file
cleanup even when the download throws. It does not change required post media,
record creation, authentication, or retry actual publication requests.

## Required update procedure

1. Export/stage the exact licensed Pro candidate and compare/rebase existing
   customizations first. The working tree must contain the **effective** Helpers
   and publishing-job files, including the currently maintained customizations.
   Do not point the application command at a live mounted directory.
2. Run from the reviewed repository:

   ```bash
   python3 ops/scripts/apply-bluesky-publishing-fixes.py --source "$staged_pro"
   python3 ops/scripts/apply-bluesky-publishing-fixes.py --source "$staged_pro" --check
   ```

   All three patches are preflighted before writes. Repeating the command is
   safe; each already-applied patch is detected. A changed upstream context
   blocks before any staged file is changed. Rebase the affected patch against
   that exact source and retain its regression cases; do not waive the failure.
3. Reconcile the reviewed staged publishing trait with the registered
   `ops/production-overrides/BlueskyManagesPost.php`. Include it in the frozen
   image through the manifest and `local-uploads/Dockerfile` recipe. Keep the
   patched Helpers and job in their existing read-only mounted overrides;
   changing only an image copy of a mounted file does not change the effective
   runtime. Carry those reviewed files forward on every update.
4. Run `BlueskyPublishingResilienceTest.php` against the candidate with its staged
   effective overrides, in a disposable network-disabled read-only container.
   It needs only Composer autoloading and a writable `/tmp`; it never boots the
   app, connects to a database, or makes real provider requests. Require all
   18 `PASS` lines and exit status zero.
5. Run the full frozen release validator. It runs this suite automatically,
   requires all 18 checks alongside the six existing upload/storage suites, and
   fingerprints Helpers, the publishing trait, the publishing job, the content parser, empty-item normalization, and URL-card
   extraction. The startup wrapper rejects missing/failed evidence or changed
   fingerprints. Follow the existing isolated database rehearsal, drain,
   checkpoint, configuration comparison, and app-only cutover procedure.

For a change to existing mounted files, use the validator
`--candidate-overrides` mapping to test staged copies before installation. Then
drain/checkpoint, install only the reviewed override files, and verify fresh full
validation evidence against the final image/mount set before cutover. If
validation fails, restore the exact prior override/wrapper snapshots before
resuming the prior release. Do not edit the currently served mounts as a way to
experiment with a patch.

Install the updated validator, wrapper, and all seven suite files together under
the release-tools paths in the frozen-release guide. The new wrapper requires a
Bluesky suite, so old upload-only gates cannot start it. Stage passing evidence
before replacing that wrapper; preserve the previous exact wrapper, evidence,
image, and overrides as a matching rollback set.

## Verification and recovery boundaries

Run the guard and reapplication checks locally/CI:

```bash
python3 ops/production-overrides/tests/FrozenUploadReleaseGateTest.py
python3 ops/production-overrides/tests/BlueskyPatchApplicationTest.py
bash -n ops/production-overrides/peachy-start.sh
```

The behavior suite covers valid/unresolved/temporarily failing mentions, root-,
path-, and protocol-relative thumbnail URLs, escaped queries, unsupported image
schemes, download/upload failures and cleanup, cards without images, actual
record rejection, partial and completed threads, and empty additional items.
The reapplication checks cover repeated runs, mixed already-patched files,
check-only behavior, and refusal to write when upstream source drifts.

These fixes do not reconnect accounts, change stored contact handles, rewrite
historical post outcomes, or publish/retry existing posts. Historical recovery
is separate: inspect provider IDs and the live thread first, then resume only
an approved affected account/reply. Never retry the whole multi-account post or
republish its already-live main record.

## October 23 production verification

The guarded app-only cutover installed
`peachy/mixpost-pro:7.1.4-ad1f9e7-bluesky-20261023`, immutable image
`sha256:79b48a008ecfff063404a0dca51158a5ac0df5f0c264b0762e56f7d220c54e6e`.
The parent remains the previously tested Pro 7.1.4 / PHP 8.5.12 frozen runtime;
this deployment did not resolve packages or downgrade PHP.

- All seven exact-candidate suites passed (70 checks), including all 18 Bluesky
  checks. The full frontend rebuild matched the packaged assets on the third
  deterministic-build attempt; all 62 registered sources matched the evidence.
- An isolated restored database passed 18 Pro regression suites, stable data
  fingerprints, persistent-volume container-replacement/outbox recovery, and
  actual FPM checks. PHPUnit and PDO SQLite checks remain unavailable in this
  runtime; their limits are recorded in the private rehearsal result.
- The final maintenance checkpoint and inert initialization preserved schema,
  stable account/post/media fields, SSO/service settings, both Compose files,
  all 53 mounts, environment values, networks, and MySQL/Redis container IDs.
- Live health/login returned HTTP 200, unauthenticated API access returned 401,
  Horizon/cron/scheduler were active, and all 313 public assets matched their
  candidate hashes. Existing authenticated Home, Analytics, and Media Library
  pages loaded. Fresh Google authorization was not repeated.
- The actual FPM worker remained PHP 8.5.12, with 500M/520M upload limits and
  native upload progress Off. The public unresolved-handle lookup completed
  without exception and retained plain text; TTB remained authorized.
- The browser's synthetic 18,007,073-byte video upload reported interruption,
  offered Retry, reused its original session, and completed with HTTP 201.
  Playback reached readyState 4 at 960 by 540 with no media error. Cloud bytes
  matched the original SHA-256; its record, three cloud objects, and small
  upload-session checkpoint were removed after proving zero post references.
- Both controlled live cloud recovery probes verified their exact bytes and
  cleanup. Only this run's disposable rehearsal resources were removed.
- No test publication, historical post retry, account reconnection, or contact
  correction was performed. Naturally scheduled publishing was not witnessed.

The authoritative live record is `/root/mixpost/frozen-release.json`; the private
audit, matching rollback image, and final database/configuration checkpoint are
under `/root/mixpost/backups/bluesky-deploy-20261023T214110Z/`. The installed
startup guard now requires the Bluesky suite, and `release-tools` points to this
reviewed deployment's tooling. Keep the previous exact wrapper, image, mounts,
and gates together for rollback.
