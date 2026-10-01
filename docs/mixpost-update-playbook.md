# Mixpost customization-preserving upgrade playbook

This supersedes the former “pull latest and recreate” procedure. Production is a licensed Pro application, while this repository also contains the separate Lite package. Updating the Lite dependency file does not upgrade production.

## Release contract

A release is the **vendor base digest + exact Pro version/source + composer.lock + customization Git commit + mount manifest + database checkpoint**. The vendor image downloads packages at startup: an image digest alone is not an application version or a reliable rollback. Build a frozen image with `ops/upgrades/build-frozen-image.sh`; its startup uses the installed code and never resolves packages or restores an application from shared storage.

The authoritative PHP application override map is `ops/production-overrides/deployment-manifest.json`. Startup (`peachy-start.sh`) and PHP upload limits (`uploads.ini`, both CLI and FPM) are also versioned; their mounts are infrastructure paths outside the legacy Infra manifest format. Preserve the storage volume, environment, proxy routing, networks and service credentials. Never mount a Vite manifest or individual hashed client asset. Since 7.0.2, the X analytics Vue overlays are built with the target Pro package and included in the frozen image; do not restore the former `/root/mixpost/x-analytics-dist` directory mount.

Google sign-in is a database-backed production setting rather than a file override. Preserve and recheck it using the [Google SSO runbook](google-sso.md), including the existing-user email match and the login-page button.

## 1. Inventory and checkpoint

1. Use an isolated checkout of current `origin/main`. Reconcile local-only commits and edits; never overwrite the user's checkout or assume remote Git contains every live fix.
2. Record running Pro version, Composer source reference and lock hash, Docker image ID, Compose files, entrypoint, all bind mounts and hashes, and MySQL/Redis container IDs. Inspect runtime app/bootstrap/package source against the pristine installed archive to catch unmounted hotfixes.
3. Save the current Compose, environment, every mounted file, release metadata, pristine app archive and a **consistent database dump** under a root-only timestamped backup directory. Do not put credentials, database dumps or licensed full-source archives in Git. Use the app container's database dump client with configured credentials, `--single-transaction --no-tablespaces --skip-lock-tables`; check exit status and dump size. MySQL 8.0.32's dump client can require unavailable FLUSH privileges; the app's MariaDB client works with the application user.
4. Retain a frozen copy of the old application for rollback, not merely the old vendor base image. Preserve local media/storage and the external object-store configuration. Record queued/delayed jobs and upcoming scheduled publications before cutover.

## 2. Obtain and compare the target

1. Read the [official release notes](https://mixpost.app/releases/pro) and [upgrade guidance](https://docs.mixpost.app/pro/upgrading/). Resolve the licensed package in a disposable container using a protected Composer auth file. Do not run the vendor startup against production to obtain source: it also migrates and starts workers.
2. Export pristine old and target sources. For every mount, compare **old upstream → custom** and **old upstream → new upstream**. `ops/upgrades/audit-overrides.py` produces a complete checksum inventory from exported sources and `docker inspect` mount JSON.
3. Reapply the required behavior to the new source. Review successful merges too: syntactically valid old state handling can break new queue/retry contracts. Use upstream implementations where equivalent; retain stronger custom safeguards where upstream only partially covers them.
4. Record each customization as retained unchanged, rebased, replaced by equivalent upstream behavior, or inactive/historical. Add newly required classes to the manifest. Include server-only files in Git. Do not restore retired upload patches merely because a file remains in the repository.
5. For the X analytics client overlay, compare all three Vue components against the target package and build a complete bundle with `ops/scripts/build-pro-x-analytics-assets.sh INSTALLED_PRO_TEAM_PACKAGE_DIR NEW_OUTPUT_DIR`. Keep the licensed compiled bundle outside Git. Verify its manifest references files in the same output directory and retain the Peachy landing-page image inside it.
6. Record the exact target package version/source and lock hash. Package resolution can advance between the release announcement and the audit.
7. Since 7.0.3, preserve the vendor X API usage controls when rebasing custom import jobs. Disabled analytics must stop all X analytics requests, limited ranges must clamp every page and skip disjoint historical cadence windows, and full analytics must retain our explicit age-tiered history. Run `TwitterUsageControlsTest.php` in the isolated Pro rehearsal alongside the existing X resource regression. Do not re-enable the retired X mentions import while incorporating upstream job arrays.

## 3. Rehearse in isolation

1. Restore the database dump to **separate MySQL and Redis containers** on a Docker `--internal` network. The candidate must not join production networks or mount writable production storage.
2. Copy the application key only through protected host files so the copied database remains readable. Override database/Redis hosts, mailer to `log`, broadcasts to `log`, sessions/cache to isolated stores. Run no cron or Horizon. Block outbound networking even when HTTP is faked in tests.
3. Apply the complete candidate manifest. Run package discovery, publish the packaged assets, normal migrations, and `mixpost:upgrade-database --force`. Review every migration, including column drops. Check existing users/workspaces/accounts/posts/media and provider IDs survive, and validate new per-account statuses and schedules.
4. Lint all PHP inside the target PHP runtime. Run the override regression suite under `ops/production-overrides/tests/`; plain script tests run directly, while PHPUnit tests require the appropriate runner. A Lite test suite is not proof of Pro compatibility.
5. Exercise success, partial failure, exhausted jobs, staggered schedules, concurrent publishing runs, retries with existing remote IDs, blank additional content, social-video preparation/timeouts, Instagram terminal-only retry, X historical pagination, thumbnail repeat imports, Threads uploads, YouTube reports and failure-mail rendering. Fake external HTTP and notifications; never run test publishes against customer accounts.
6. Verify routes, cached routes/views, scheduler registration, provider class loading, health/login/public page and all packaged assets. Rehearse boot from the frozen image, with the same mount structure planned for production.

## 4. Freeze and deploy

From the Docker host, with a tested source container and an exact committed customization revision:

```sh
ops/upgrades/build-frozen-image.sh \
  mixpost-v7-build \
  inovector/mixpost-pro-team@sha256:REVIEWED_BASE_DIGEST \
  peachy/mixpost-pro:VERSION-COMMIT \
  FULL_CUSTOMIZATION_COMMIT
```

The builder excludes environment files, runtime caches, logs, sessions, media and Composer credentials. Inspect the resulting image for absence of secrets and check its installed package/lock hash. Use a unique tag and record its immutable image ID.

1. Acquire `/run/lock/infra-deploy-mixpost.lockdir` to exclude dashboard deployments. Check for due publications and drain active publishing jobs. Put the app into maintenance and pause scheduler/workers for the final database checkpoint; do not clear Redis queues.
2. Take another consistent database dump after draining. Save Compose and all current mounts again. Existing serialized v6 queue jobs must drain or be reviewed for compatibility before v7 workers consume them.
3. Install reviewed host override files atomically and generate the manifest's read-only mounts. Preserve non-manifest mounts. Explicitly retain `MIXPOST_CORE_PATH=mixpost` and `MIXPOST_CALLBACK_PATH=mixpost`; v7 otherwise defaults to the root path. Set the app service to the frozen image. First recreate **only** `mixpost` with `--no-deps --pull never` and a temporary third Compose file setting `entrypoint: ["sleep", "infinity"]` and `command: []`. MySQL and Redis remain running. This keeps HTTP, cron and Horizon inactive during migration.
4. Execute only the initialization portion of the frozen startup, stopping before `service cron start` and supervisord. For the reviewed startup in this repository: `docker exec mixpost-mixpost-1 bash -o pipefail -c "sed '/^service cron start/,\$d' /usr/local/bin/start.sh | bash -e"`. This creates the environment, migrates and caches the app without starting processes. Compare `ops/upgrades/data-fingerprint.php` output before/after; it hashes selected stable user, workspace, account, post, destination and media fields without exposing their values. Review any difference before continuing. Verify runtime and mount hashes while the container is inert, then recreate the app with the normal two Compose files to start web, cron and workers. Resume Horizon if its pause state remains in Redis. Confirm migration completion before resuming normal traffic and workers. Check exact package/source/lock identity, expected image ID, every mounted hash, Horizon, scheduler, health, login, assets and an authenticated page. Recheck Google SSO using its runbook. Confirm protected container IDs are unchanged.
5. Save release evidence and the rollback checkpoint. Monitor the first naturally scheduled publication; do not create an unsolicited social post or email as a test.

### Infra dashboard limitation

The legacy `deploy-mixpost.sh` pulls the vendor `latest` image and cannot freeze Composer resolution or undo database migrations. Do not use its generic Deploy/Rollback buttons for this major-version release. The frozen image uses a different configured image reference so the old updater's preflight refuses it. Until Infra is upgraded to this release contract, use this reviewed playbook for subsequent updates. Never retag a frozen release as vendor `latest` to bypass that guard.

## 5. Rollback

Stop publishing and writes first. If migrations ran, **image-only rollback is insufficient**: restore the matching pre-upgrade database checkpoint, prior frozen application, Compose and override snapshot together. Do not restore a database over later successful publications without first reconciling provider IDs and queued jobs; that can cause duplicate publishing. Retain the displaced database for reconciliation.

Recreate only the app (`--no-deps --pull never`), then verify package identity, schema, every mount, log ownership, health, login, scheduler, Horizon and unchanged MySQL/Redis containers. Do not use `docker compose down -v`, delete media, clear all queues or reconnect social accounts to complete an upgrade.

## Required release evidence

Record old/new versions, package source and lock hashes, base/frozen image IDs, Git commit, mount inventory, checkpoint paths, migration output, regression results and runtime/browser evidence. Clearly distinguish tests with fake providers from witnessed live publishing. Keep sensitive backups on the host; publish only sanitized hashes and outcomes in the repository.
