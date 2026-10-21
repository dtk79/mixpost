# PHP security mitigation — October 21, 2026

CVE-2026-109618 and CVE-2026-109619 affect PHP request initialization and cleanup.
The official [PHP advisory](https://www.php.net/index.php#2026-10-21-1) requires
PHP 8.3.36 or a vendor-confirmed backport for the deployed 8.3 series. Disabling
session upload progress blocks the confirmed session attack paths; it is a
temporary mitigation, not a complete fix for both vulnerabilities.

## Configuration and preservation

Copy `ops/production-overrides/php-security.ini` to
`/root/mixpost/php-security.ini` (mode 0644). Add this read-only mount under the
existing `mixpost` service's volumes in `/root/mixpost/docker-compose.yml`:

```yaml
- ./php-security.ini:/etc/php/8.3/fpm/conf.d/99-security.ini:ro
```

Keep both production Compose files effective. Preserve the exact frozen image,
startup wrapper, existing upload limits, customizations, services and storage.
Do not modify `uploads.ini`: existing release evidence fingerprints that file.
The security setting must load before request parsing; an application-level
`ini_set()` or `.user.ini` is insufficient. Mixpost's client-side byte progress
and custom upload-session state do not depend on PHP's session upload tracking.

Checkpoint both Compose files, environment, mounted files, frozen release record,
container identities and the database. Compare the effective Compose JSON to
prove the only change is the new read-only FPM mount. Check publishing queues
before an app-only recreation. Preserve and run the existing startup gate; do
not waive its source/build or recovery contract. Recreate only `mixpost` using
both Compose files and `--no-deps --pull never`; never recreate MySQL or Redis.

## Acceptance and rollback

- Require the existing frozen startup check to pass before and after recreation.
- Verify the active FPM worker reports `session.upload_progress.enabled=Off`
  through its private Unix socket, with a temporary probe outside the document
  root. A CLI-only check does not prove the HTTP worker configuration. Remove
  the probe immediately. Do not expose `phpinfo()` or environment details.
- Confirm every previous mount and hash, unchanged image/service identities,
  persistent storage, health, login, Horizon and scheduler. Compare stable data
  fingerprints around initialization while traffic/workers are stopped.
- Test an ordinary multipart upload through the same FPM socket and an
  authenticated Media Library upload. State unavailable checks explicitly.
- Retain the checkpoint. If activation fails, restore the saved Compose files
  and previous guarded app configuration; account for writes before restoring
  any database. Removing the mitigation restores exposure, so prefer repairing
  the configuration or restricting access if rollback becomes necessary.

After the vendor publishes a patched image, resolve its exact digest and inspect
its actual PHP package version. A changed image digest or push timestamp does
not prove remediation. Follow the full frozen-release validation and isolated
rehearsal process for the replacement; preserve this mount as defense in depth.
Update the FPM path if a reviewed image changes PHP series. Record source, CI,
rehearsal, runtime, storage and browser proof separately in the release record.
