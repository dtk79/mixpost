# Mixpost Pro 7.0.3 production upgrade

Upgrade date: 2026-10-01. Status: **live and verified**. Previous frozen release: Pro Team 7.0.2. This record follows the [update playbook](mixpost-update-playbook.md).

## Customization review

The official [7.0.3 release notes](https://mixpost.app/releases/pro) add X API usage controls. A clean reinstall of each exact licensed package supplied the pristine 7.0.2 and 7.0.3 comparison; an inherited frozen application alone is not pristine vendor source.

All 48 managed live files matched current Git before the upgrade. The 51 live bind mounts were read-only, and the unmounted application, bootstrap, route and Pro PHP sources had no extra runtime changes. The original dirty working checkout was preserved in place; upgrade work used an isolated checkout of current `origin/main`.

Upstream changed three mounted targets:

- `ImportTwitterPostsJob.php`: rebased to stop requests when analytics is disabled, enforce 7-day/30-day limits even on already queued pages, skip historical windows outside the selected limit, and preserve start/end bounds and the public-metrics fallback through pagination. Full analytics preserves the existing explicit age-tiered history; unwindowed jobs use the new vendor defaults. Disabled/free and disjoint-window responses are constructed directly because the job runtime has no `response()` helper.
- `ManagesTwitterJobs.php`: rebased the vendor analytics-enabled guards into our initial, follower and daily jobs. Retained our reduced scheduling and the previous decision to keep X mentions fetching disabled.
- `ManagesTwitterResources.php`: retained unchanged after confirming its existing fourth argument supplies the vendor's new start-time behavior; it also retains our end-time windows, long-post text, partial-error resilience and upload safeguards.

Other mounted customizations were retained unchanged. The three analytics Vue overlays have unchanged upstream counterparts in 7.0.3; they were rebuilt with the complete target client package, including the Peachy landing asset, for baking into the frozen image. No manifest, individual hashed asset or client-directory mount was introduced.

The Composer lock changed only the licensed Pro package; unrelated runtime dependencies remained at their previous locked versions. Sensitive source archives, environment, database dumps and Composer credentials remain outside Git on the host.

## Release and rehearsal

- Exact Pro Team version/source: `7.0.3` / `4207e18460dc8080235c7d7e38a4344f3ec61939`.
- Composer lock SHA-256: `1d092924f0e1d3fa73d7ee2cb4cac6e048a5b2345ffb8c4656382be88b5c865d`.
- Vendor base: `inovector/mixpost-pro-team@sha256:0076027bb9e2a0425568e17965bc8c90758de585efc4769e1457ce91de41df06`.
- Full custom client manifest SHA-256: `758a4a46973fea3d5fc07ec014fdbaf834196cbb684e5af0200b30a8f2099b4d`.
- Isolated database restore used an internal-only Docker network, separate MySQL and Redis, no production-writable storage, no scheduler or Horizon, log-only mail and broadcasts. The target schema migration directories match 7.0.2; migration checks reported nothing pending, and stable core-data fingerprints matched before and after.
- PHP lint passed for all 47 managed PHP mounts. Thirteen regression scripts passed, with their explicit completion messages checked: imported thumbnails, Instagram container safety, X upload/timeline parameters, post-version normalization, failure explanations/notifications, social-video duration/preflight, Threads containers, v7 publishing retry/checkpoints, YouTube queue/report handling and the new X usage controls.
- Health PHPUnit and YouTube SQLite snapshot tests remain unavailable in the runtime because PHPUnit and PDO SQLite are absent; independent runtime health, provider loading and cloned-MySQL probes supplement them.

No unsolicited social post or email was sent. Naturally scheduled provider publishing after cutover remains a separate observation.

## Production cutover and verification

- Customization revision: `46836d7eddcab1d4195fd6772c3602d8bd26a9d3`, committed and pushed to `origin/main` before the final frozen build. The production image label matches that exact revision.
- Frozen image: `peachy/mixpost-pro:7.0.3-46836d7`, immutable ID `sha256:2db7a9e90c3fee3961661b4d0625b66acc162869f7843d84b606f1a5adb19511`.
- The final image passed an internal-network boot rehearsal with the same 51 read-only mounts planned for production, runtime provider/health/asset checks, all mounted PHP lint, the X usage regression and stable-data fingerprints. Before initialization, environment files, Composer credentials, compiled configuration and runtime logs were absent from the image.
- Cutover completed at **2026-10-01 18:49:12 UTC (11:49 a.m. Pacific)**. The final consistent database dump, prior frozen-image archive, Compose/environment, every override, migration logs and before/after fingerprints are retained under the root-only checkpoint `/root/mixpost/backups/v703-cutover-20261001-184129`.
- Publishing queues were empty; cron and Horizon were paused and reserved jobs drained before the final checkpoint. The app was recreated inertly for migration and verification, then recreated with web, cron and workers enabled. MySQL and Redis container identities stayed unchanged. No queues, storage volumes, social accounts or credentials were reset.
- All 48 managed file hashes and 51 read-only bind mounts matched. Stable selected fields matched before/after for users (14), workspaces (10), accounts (27), posts (953), destinations (1,593) and media (1,167). Configuration, service and settings table fingerprints also matched, including Google SSO configuration.
- Public health, login and landing returned HTTP 200; all 37 assets referenced by the login HTML returned HTTP 200. The analytics API and MCP `tools/list` correctly returned HTTP 401 without authentication. An authenticated API/MCP token probe was not repeated for this release; no new integration credential was created.
- Authenticated Chrome Home and Analytics rendered with no console errors. The existing X account's September metrics and charts remained visible. The custom X age-tiered and low-cost analytics schedules remain registered. Horizon and cron are running.
- The Google SSO settings retained the expected issuer, scopes, callback, verified-email requirement, password login and existing-user-only policy. **Test connection** succeeded. A fresh login through the Google button completed for the existing Ducati X account and returned to the same workspace as the pre-upgrade session.
- The original dirty checkout was left unchanged. The disposable 7.0.3 rehearsal containers were removed after verification; protected backups, pristine sources and release evidence remain on the host.

The host release record is `/root/mixpost/frozen-release.json`. Database-aware rollback requires restoring the matching pre-cutover database, prior frozen image, Compose and override snapshot together, as described in the playbook. The next naturally scheduled publication is **2026-10-01 21:30 UTC (2:30 p.m. Pacific)**; publishing under the new release has not yet been observed.
