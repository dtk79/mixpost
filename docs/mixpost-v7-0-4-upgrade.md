# Mixpost Pro 7.0.4 production upgrade

Upgrade date: 2026-10-08. Status: **live and verified**. Previous release: frozen Pro Team 7.0.3. This upgrade follows the [customization-preserving playbook](mixpost-update-playbook.md).

## Customizations and upstream comparison

The [official 7.0.4 release](https://mixpost.app/releases/pro) fixes the Ollama API endpoint. The exact licensed package comparison found only `CHANGELOG.md` and `src/AIProviders/Ollama/OllamaProvider.php` changed outside compiled assets. The provider now strips a trailing `/api` from the configured base URL. A no-network runtime check verified base URLs and legacy `/api` forms with and without trailing slashes.

All 48 managed production files matched current `origin/main`. All 51 live bind mounts were read-only. Runtime application, bootstrap, routes and Pro PHP sources matched the preceding frozen image after excluding managed overrides and runtime caches; no unmounted hotfixes were lost. Every mounted upstream target is unchanged in 7.0.4, so all customizations were retained unchanged. The complete checksum comparison is [v7-0-4-override-audit.json](../ops/upgrades/v7-0-4-override-audit.json).

This retains publishing/retry and duplicate safeguards, post-version normalization, social-video processing, failure notifications, Threads and Instagram container protections, X upload diagnostics, full long-form post text, X usage controls and age-tiered analytics, Inbox authorship isolation, YouTube audience reports, thumbnail reuse, branding, health and Google SSO. Historical or inactive repository files were not added as new production mounts.

The three custom X analytics Vue components have unchanged upstream counterparts. A complete client bundle was rebuilt against the exact 7.0.4 package with the Peachy landing image and baked into the frozen image. No hashed asset, Vite manifest or client-directory mount was introduced. Composer updated only the licensed Pro package; every other locked dependency remained unchanged.

The user's existing checkout and uncommitted files were preserved. Upgrade work used `/Users/Dan/.codex/worktrees/mixpost-704/mixpost`, starting from current `origin/main`.

## Exact release

- Pro Team version/source: `7.0.4` / `ed541fd0b1b77b839065edf40508143ab9b27ff1`.
- Composer lock SHA-256: `c61f3e931a3c34355a1dd01354b8119ad3cb59d7ff855834e526d6253fdedbe8`.
- Unchanged customization revision: `8def2a9339edca551a138c5a8098d775e48664e7`.
- Pinned vendor runtime base: `inovector/mixpost-pro-team@sha256:0076027bb9e2a0425568e17965bc8c90758de585efc4769e1457ce91de41df06`.
- Frozen image: `peachy/mixpost-pro:7.0.4-8def2a9`.
- Immutable image ID: `sha256:33f3ceaa52875e937e85a587d0740c0232631584907d29f63e6dedaab57ea0df`.
- Custom client manifest SHA-256: `8c693957b91285945bdebee409fb95a9edee23ed95eefe5e2d649f6e9f38c7d6`.
- Production cutover completed: **2026-10-08 16:21:58 UTC / 9:21 a.m. Pacific**.
- Root-only rollback checkpoint: `/root/mixpost/backups/v704-cutover-20261008-161901`.
- Root-only audit and rehearsal evidence: `/root/mixpost/backups/v704-audit`.
- Authoritative host release record: `/root/mixpost/frozen-release.json`.

Licensed source, credentials, environment files and database dumps remain outside Git on the host.

## Rehearsal and regression verification

A consistent 251,142,030-byte database dump was restored to separate MySQL and Redis containers on an internal-only Docker network. The rehearsal never joined production networks or mounted writable production storage. Cron and Horizon were not started; mail and broadcasts used logs. Package discovery, asset publication, migration timestamp checks, normal migrations, Mixpost upgrade migrations and cached routes/views passed. Both migration commands reported nothing pending. Selected stable core-data fingerprints matched before and after initialization.

All 47 managed PHP files passed lint in the target PHP runtime. All 13 available regression scripts passed, with explicit completion messages checked as well as process status:

- Imported thumbnail repeat imports and local storage reuse.
- Instagram terminal-container, retry and publication guards.
- X upload/timeline resource parameters and diagnostics.
- Post-version content normalization and failure explanations.
- Failure notifications across all 14 providers, successful silence, exhausted failures, staggered schedules and concurrent runs.
- Full social-video duration and bounded upload/preparation retries.
- Threads container request handling.
- X disabled/limited/full-history usage controls and pagination.
- V7 publishing checkpoints, partial failure, retry and duplicate prevention.
- YouTube audience queue hooks and report calculations.

`HealthEndpointTest.php` requires PHPUnit, and `YoutubeAudienceSnapshotTest.php` requires PDO SQLite; neither is installed in the vendor runtime, so those two checks remain unavailable. Independent cloned-MySQL probes verified provider loading and persisted data, and runtime/public health was checked separately. No real provider test publish or email was sent.

The exact frozen image then passed a second internal-network boot rehearsal with the same 51 read-only mount destinations planned for production. Image metadata, mounted hashes, PHP lint, X usage regression, data fingerprints, provider loading, health and all packaged assets passed. Environment files, Composer credentials, compiled configuration and runtime logs were absent from the uninitialized image.

## Production verification

Publishing queues were empty, including ready, delayed and reserved publishing jobs. A per-destination schedule check found no publication due within fifteen minutes. Cron and Horizon were paused, active jobs drained, and the app entered maintenance before the final consistent database checkpoint. The preceding frozen image, Compose files, environment and all mounted files were archived for recovery.

Compose changed only the application image. The app was first recreated inertly, initialized without HTTP/cron/workers, verified, then recreated with normal startup. All 48 managed file hashes and all 51 read-only bind mounts matched. The production environment and both application network memberships were unchanged. MySQL, Redis and all 41 other previously running containers kept their identities and remained running.

Selected stable fields matched through cutover for users (14), workspaces (10), accounts (27), posts (981), destinations (1,642) and media (1,240). Full configuration/services/settings fingerprints also matched, preserving Google SSO and social-provider credentials. Migration checks reported nothing pending.

Public health, login and the landing page returned HTTP 200. All 37 assets referenced by login HTML returned HTTP 200; the runtime manifest also referenced no missing assets. The running package source, Composer lock and custom client manifest match the release hashes. All 14 provider classes load. The analytics API and MCP `tools/list` returned HTTP 401 without authentication; no integration credential was created. Authenticated API/MCP token probes were not repeated for this patch release.

Horizon and cron are running, and the custom X age-tiered and low-cost 30-minute analytics schedules remain registered. Laravel logs retain `www-data:www-data` ownership and mode 664.

The Google SSO provider, issuer, scopes, callback, verified-email requirement, password-login option and existing-user-only provisioning remained unchanged. The first connection test shortly after boot hit a brief Google connection error; direct host/container discovery checks and the repeated UI test succeeded without configuration changes. A fresh Google sign-in completed for the existing Ducati X user and returned to the same user and Trailer Trash Boys workspace.

Authenticated Home, Analytics, the X account metrics and the custom X content list render after the upgrade with no browser console errors. The next naturally scheduled publications are at **2026-10-08 19:00 UTC / noon Pacific**; real provider publication under 7.0.4 has not yet been observed. Follow the playbook's database-aware recovery procedure if rollback is needed, and reconcile later successful provider IDs and queued jobs before restoring a checkpoint.

The release record and sanitized checksum audit are committed separately from the unchanged customization revision labeled on the frozen image. Disposable 7.0.4 rehearsal containers and their internal network were removed after verification; protected backups and pristine source exports remain on the host.

A thread heartbeat, `Verify Mixpost 7.0.4 publications` (`verify-mixpost-7-0-4-publications`), is scheduled hourly to complete the publication observation. It stays quiet before 12:15 p.m. Pacific, makes read-only production checks thereafter, and pauses after conclusive verification or a reported failure requiring user action. It will not publish, retry, send messages, change data or redeploy.
