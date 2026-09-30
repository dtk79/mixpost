# Mixpost Pro 7.0.2 production upgrade

Upgrade date: 2026-09-30. Status: **live and verified**. Previous frozen release: Pro Team 7.0.1. This record follows the [update playbook](mixpost-update-playbook.md) and the [v7 customization audit](mixpost-v7-upgrade-audit.md).

## Reviewed release

- Licensed Composer package: `inovector/mixpost-pro-team` **7.0.2**, source `4e107936339d466279d53a642d3c94f4d2307b35`.
- Composer lock SHA-256: `31e69f4fd34cad4e9bf0e4837196545e1074fab001906711b1432a5d3820d52b`.
- Customization source: `4f2ea40d1a962e291b2277cb0af4fdf8e2be8dcf`, pushed to `origin/main` before cutover. The independent X analytics files that were live but missing from `origin/main` were preserved in `da6f737`.
- Frozen image: `peachy/mixpost-pro:7.0.2-4f2ea40`, ID `sha256:60b4f9677b3a1c14a910243dcb65debb1e54ebc6fbc823aad8e40b846b8b2450`.
- Vendor base digest remained `inovector/mixpost-pro-team@sha256:0076027bb9e2a0425568e17965bc8c90758de585efc4769e1457ce91de41df06`.
- Upstream 7.0.1 to 7.0.2 changed one mounted PHP target: `PostFormRequest.php`. Its new request-timezone conversion was rebased with our empty-thread normalization. A full 7.0.2 client bundle was built with the preserved X analytics overlays and baked into the frozen image; the old directory mount that shadowed the whole bundle was removed.
- The original working checkout remained untouched. The upgrade used a managed isolated worktree.

## Rehearsal and cutover

- A production database snapshot was restored to isolated MySQL and Redis containers on an internal Docker network. The app had no production-writable storage, cron, Horizon, mail delivery or broadcasting. Upgrade migration checks reported nothing pending.
- The rehearsal passed PHP lint for all 47 mounted PHP files, 12 focused regression scripts, provider loading, route loading, health, packaged asset inventory and an API scheduling conversion from `2026-10-03 09:30 America/Los_Angeles` to `2026-10-03 16:30:00 UTC`.
- Two test-only scripts could not run inside the vendor image: the health endpoint PHPUnit test lacks PHPUnit, and the YouTube snapshot test lacks PDO SQLite. Runtime health, cloned database, and the relevant provider checks passed independently.
- Before cutover, publishing queues had no ready, reserved or delayed jobs. The final consistent database dump, existing Compose/environment and override files, prior frozen image archive, hashes, migration log and rollback metadata are at `/root/mixpost/backups/v702-cutover-20260930-191447` on the production host. The prior image archive was checked with gzip.
- Cutover completed at **2026-09-30 19:20:06 UTC**. The app was recreated after inert migration checks. MySQL and Redis container identities stayed the same. All 48 reviewed override hashes matched, PHP lint passed, and 51 read-only bind mounts remained after removing the obsolete client-directory mount.
- Stable-data fingerprints matched before and after for users (14), workspaces (10), accounts (27), posts (929), destinations (1,556) and media (1,143). These compare selected stable fields, not a byte-for-byte database image.

## Live verification

- The running image ID and Composer package match the reviewed release. Horizon is running; the custom X age-tiered and low-cost analytics schedules are registered.
- Public health and login pages returned HTTP 200. An unauthenticated analytics API request returned HTTP 401.
- A temporary token for an existing workspace member confirmed the public HTTPS analytics API returns HTTP 200 with `period` and `data`. The public HTTPS MCP `tools/list` call returned HTTP 200 with 30 tools, including four analytics tools. The temporary token was deleted after the checks; no standing integration credential was created.
- In authenticated Chrome, Home and Analytics loaded, including the existing X account metrics and charts. The browser reported no console errors on those screens.
- No unsolicited provider post or email was sent. The first naturally scheduled publication after cutover is still the remaining live-provider observation.

The host release record is `/root/mixpost/frozen-release.json`. The frozen image and database checkpoint are retained for database-aware rollback. Connecting Media Platform is a separate integration change that will need a scoped, persistent credential and an agreed data flow.
