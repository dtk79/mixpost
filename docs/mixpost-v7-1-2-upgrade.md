# Pro 7.1.2 customization-preserving upgrade

Production was upgraded from customized Pro 7.0.4 on October 17, 2026 (Los Angeles).
The licensed source is `8faf3eb31e8e85a5194f8ba29f0901c965bdb364` and the Composer
lock hash is `ad5acac5666e3c0620bb88409fcab6508b14c40255564eef0d862131695c6c92`.

- Frozen image: `peachy/mixpost-pro:7.1.2-b550aa0-20261018-r2`.
- Immutable image ID: `sha256:1d39a3a403adcc16427761060293ad3ff30b2e7c5c654a266b28ff312e2c638d`.
- Customization source commit: `b550aa0c9fba911785ce5624d52ed3cead431557`.
- Private evidence: `mixpost-hetzner:/root/mixpost/backups/v712-upgrade-20261018T0330`.
- Final database/configuration/mount/gate checkpoint: that directory's `cutover-r2/`.
- Previous frozen image: `peachy/mixpost-pro:7.0.4-video-proportions-20261012`,
  ID `sha256:9f025f1f5968d3e9d9cea2640fce5fc2286c35766b2b74992637caebf54c3a9f`.

## Preservation and validation

The complete audit includes 64 active file customizations: all 61 registered
application overrides plus the startup wrapper and PHP limit mounts. Both
Compose files, all 52 mounts, environment values, MySQL/Redis container IDs,
networks, storage, Google sign-in/service settings, and the active HTTP/1.1
experiment were preserved. Environment ordering changed; names and values did
not. No generic vendor updater or `latest` image was used.

Publishing/request overrides were rebased to retain upstream first-comment media,
failed-publication events, and API line breaks while keeping local blank-item,
video/storage readiness, checkpoint, and retry behavior. Frontend changes retain
upstream accessibility/mobile improvements and our upload progress/recovery and
Analytics overlays. The existing portrait/display-proportions fix is included in
the source lineage. See `ops/upgrades/v7-1-2-override-audit.json`.

The six exact-image upload suites passed (52 checks), the full frontend rebuild
matched the packaged active assets, and every committed override matched the
passing image gate. The 18 register and 23 guard checks passed locally; GitHub's
release guard and six Lite PHP/Laravel matrix jobs passed on the implementation.
Lite CI is separate from the licensed Pro rehearsal.

A fresh production dump was restored to isolated MySQL/Redis on an internal
network. Both upgrade migrations (personal-key suffix and OAuth tables) passed
with unchanged stable user/account/post/media fingerprints. Seventeen installed
Pro regression suites passed, including Facebook import requests, API line
breaks, publishing/checkpoint readiness, provider retries, thumbnail reuse,
notifications, X usage controls, and the original video's conversion/display.
Accepted bytes, unfinished chunks, and queued storage delivery survived actual
replacement of the isolated app container using a private persistent volume.
No production storage, cron, Horizon, or external provider was used in rehearsal.

Production received another consistent checkpoint after draining active work.
Only the app was recreated, first inert for guarded migrations and fingerprint
comparison, then with its ordinary guarded startup. Existing database values and
service/SSO configuration hashes stayed stable. All 390 validated runtime source,
asset, and mount hashes matched after startup. Horizon and cron are running.
Health, public landing, and login returned 200; all 37 login asset references
returned 200; the served app entrypoint matched the candidate hash; unauthenticated
API ping returned 401. Fresh Mixpost connections still use HTTP/1.1 and the control
host still uses HTTP/2.

Both live storage probes ran as `www-data`: 12 MiB single-PUT delivery with lost
acknowledgment recovery, and 10 MiB multipart recovery. Both verified exact bytes,
one remote write, and cleanup, with no Media record or publication created.
The existing signed-in browser session loaded Home, Media Library, and Facebook
Analytics without console errors. A fresh Google authorization was not performed.
The initial live browser file-picker/upload/interruption check was blocked by
Chrome's local-file restriction and the locked Mac. After the Mac was unlocked,
the native picker completed the check on October 18. A disposable 15,450,862-byte,
24-second, 960-by-540 video was uploaded through Media Library with a deliberately
interrupted browser connection. The UI showed the connection error and Retry;
Retry reused the same chunk session, completed with HTTP 201, and created exactly
one Media record (1685). The original object's SHA-256 matched the local fixture
(`20827d067a67285f21952df6d3ff666decf42bf165713acd38a35b48a91e7097`).
Cloud delivery reached ready with thumbnail and social-video conversions, and
the browser played the video at the correct dimensions and duration. Temporary
browser network throttling was reset. The fixture had no post references and was
removed after verification; its Media record and all three cloud objects were
confirmed absent, and the library search returned no files. Private screenshots
and readback evidence are retained under `browser-followup-20261018/` in the audit
directory. No test publication was created.
PHPUnit and PDO SQLite are absent from the Pro runtime; health-route and available
report/queue tests passed separately.

## Facebook compatibility and historical gap

The [Facebook compatibility customization](customizations/facebook-post-insights.md)
is now registered, image-baked, fingerprinted, regression-tested, and required by
the update playbook. Both fetchers omit only `post_video_views_unique`, which live
Meta requests reject. All seven other daily video metrics tested successfully.
Historical unique-view enum/database values remain intact.

After activation, natural and scoped analytics imports resumed, source counters
became fresh, and missing recent posts were discovered. Reporting then synced
2,054 posts/metric rows successfully. Facebook did not supply complete per-post
media-view snapshots for October 15–17, so catch-up counters must not be described
as an exact daily reconstruction. The historical daily gap remains unresolved;
do not substitute page totals or three-second video views for post impressions.

## Rollback

Keep the matching checkpoint, both Compose files, mounted-file snapshots,
previous tested image and gates. Migrations changed schema: follow the playbook's
database-aware rollback and reconcile any later writes/publications first. Never
restore an older dump over newly accepted media or provider IDs blindly. Both
images retain local staging/readiness support. No data volume or database/cache
container was replaced.
