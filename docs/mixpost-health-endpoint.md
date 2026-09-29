# Mixpost liveness endpoint

Status: deployed on 2026-09-12 at 22:26 UTC with user approval.

Infra began checking `https://mixpost.peachyhq.com/api/health` in commit
`fb1878364c0f708e40e7cf0a343da9989ac556d8` on 2026-09-12. The deployed Mixpost
application did not register that route and returned 404, while the homepage and
login returned 200 and Horizon was running.

The existing `ops/production-overrides/AppServiceProvider.php` override now
registers GET/HEAD `/api/health`, returning HTTP 200 with
`{"ok":true,"service":"mixpost"}` and `Cache-Control: no-store`. It runs without
web/session middleware, database queries, or external API calls. This checks
application liveness; Infra's container checks report MySQL and Redis separately.

Deployment requires backing up `/root/mixpost/AppServiceProvider.php`, applying
only the health-route addition to that existing override, and refreshing the
app's route cache/runtime. Preserve the password-reset and exception-reporting
customizations. The provider is already mounted read-only, so no new mount is
needed. Recreate only the Mixpost app if required; leave MySQL and Redis running.

After deployment, verify the public JSON response, homepage/login HTTP 200,
Horizon status, the mounted provider checksum, and Infra's next monitoring result.

## Deployment verification

- Backed up the previous provider to
  `/root/mixpost/AppServiceProvider.php.bak.20260912T222547Z-health`.
- Verified the live provider matched the expected previous version before applying
  the health-route addition. Updated the existing file in place to preserve its
  read-only bind mount, then rebuilt the route cache as `www-data`.
- PHP lint passed inside the production container. The host-mounted and local
  provider SHA-256 match:
  `0426e5ab4acd21f1112c0e5f8874e4888820078aa708d6ada9badd9a55e07d72`.
- Public `/api/health` returned 200 and `{"ok":true,"service":"mixpost"}` with
  `Cache-Control: no-store, private` and no session cookie.
- Homepage and login returned 200; forgot-password routes remain registered;
  Horizon is running; MySQL and Redis remain healthy.
- No container restart was needed. Mixpost's start time remained
  `2026-09-12T06:04:53.567607599Z`.
- Infra's persisted monitoring sample at `2026-09-12T22:28:30.857Z` recorded
  Mixpost's check at `22:28:26.147Z`: public HTTP 200 in 216 ms, origin HTTP 200
  in 211 ms, `ok: true`, and `jsonMatched: true`.
- Verified the rendered Infra Apps screen: Mixpost is Healthy, the Mixpost 404
  alert is gone, and the totals are 0 critical / 16 healthy services.
