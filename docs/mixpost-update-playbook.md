# Mixpost Update Playbook

Use this when updating the production Mixpost Pro Team Docker image on `mixpost-hetzner`.

Production runs from `/root/mixpost/docker-compose.yml` and keeps Peachy-specific files as read-only bind mounts. Do not delete those files and do not run `docker compose down -v`.

## Preflight

```bash
ssh mixpost-hetzner
cd /root/mixpost

stamp=$(date +%Y%m%d-%H%M%S)
mkdir -p backups/update-$stamp
cp docker-compose.yml backups/update-$stamp/
cp *.php *.sh *.ini *.blade.php *.json *.js *.png backups/update-$stamp/ 2>/dev/null || true

docker compose ps
docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && composer show inovector/mixpost-pro-team --no-interaction" || true
```

## Update

Prefer recreating only the app container. This keeps MySQL and Redis running unless the vendor update explicitly requires otherwise.

```bash
docker compose pull mixpost
docker compose up -d --force-recreate mixpost
```

## Archived Upload Resilience Image

Production no longer uses the locally built upload-resilience image; Pro Team 6.2.2 and later incorporated the required upload flow. `ops/production-image/` is retained only as an incident-history and rollback reference. Do not copy, build, or promote it during a normal update, and do not bind-mount a hashed client manifest or individual JavaScript chunk.

If a regression ever requires reviving that image, rebase both patches against the exact target Pro Team source, update the pinned digest and package version, rebuild from a scrubbed archive, and validate the entire upload flow before changing Compose. Never reuse the archived 6.2.0 defaults unchanged.

If the vendor specifically requires a full compose restart, this is acceptable after the backup:

```bash
docker compose pull
docker compose down
docker compose up -d
```

Do not use `docker compose down -v`.

## Post-Update Asset Check

Major Mixpost updates can change Vite asset names. Production should not bind-mount Mixpost's hashed client manifest or app chunks; let the image serve its packaged `public/vendor/mixpost/manifest.json`.

```bash
docker exec mixpost-mixpost-1 sh -lc "grep -n 'resources/js/app.js\|assets/app-' /var/www/html/public/vendor/mixpost/manifest.json | tail -20"
curl -sS -L https://mixpost.peachyhq.com/mixpost/login | grep -Eo '/vendor/mixpost/assets/[^\" ]+' | head
```

Fail the update if the login page or app entrypoint references missing assets:

```bash
entry=$(curl -sS -L https://mixpost.peachyhq.com/mixpost/login | grep -Eo '/vendor/mixpost/assets/app-[^\" ]+\.js' | head -1)
curl -fsS "https://mixpost.peachyhq.com$entry" |
  perl -ne 'while(m#assets/[A-Za-z0-9_.-]+\.(?:js|css|png|svg|jpg|jpeg|webp|woff2?)#g){print "/vendor/mixpost/$&\n"}' |
  sort -u |
  xargs -n1 -P16 -I{} sh -c 'code=$(curl -s -o /dev/null -w "%{http_code}" "https://mixpost.peachyhq.com$1"); [ "$code" = 200 ] || printf "%s %s\n" "$code" "$1"' sh {}
```

The command should print nothing. If it reports missing assets, inspect the current image and Compose mounts before recreating `mixpost`.

## Verification

```bash
docker compose ps
docker compose logs --tail=120 mixpost

docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Commands/MigrateStorage.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesTwitterJobs.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Twitter/Concerns/ManagesResources.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Bluesky/Concerns/UsesUploads.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Analytics/InstagramAnalytics.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Concerns/ManagesInstagramResources.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/SocialProviders/Meta/Jobs/ImportInstagramMediaJob.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Media/ChunkedUploadComplete.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/MediaConversions/MediaSocialVideoConversion.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Jobs/OptimizeSocialVideoMediaJob.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Support/PeachyPostVersionContent.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Post/PostFormRequest.php
docker exec mixpost-mixpost-1 php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Actions/Post/AccountPublishPost.php
docker exec -e PEACHY_POST_VERSION_CONTENT_PATH=/var/www/html/vendor/inovector/mixpost-pro-team/src/Support/PeachyPostVersionContent.php -i mixpost-mixpost-1 php < ops/production-overrides/tests/PeachyPostVersionContentTest.php

docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && composer show inovector/mixpost-pro-team --no-interaction | sed -n '1,8p'"
docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan about --only=environment"
docker exec -i mixpost-mixpost-1 sh -lc "cd /var/www/html && php artisan schedule:list | grep -E 'low-cost-post-analytics|twitter-post-analytics'"
```

Then verify the browser:

1. Open `https://mixpost.peachyhq.com/mixpost/login` in a fresh/private browser.
2. Hard reload the logged-in dashboard with `Cmd+Shift+R`.
3. Confirm the dashboard renders and no blank white page remains.
4. Upload a valid video under 500 MB, then confirm `mixpost.chunked_upload.chunk_stored` entries include an upload UUID and chunk index in `storage/logs/laravel.log`.
5. Select a video larger than 500 MB and confirm it is rejected before chunks are transferred with both the 500 MB limit and the selected file size.
6. Interrupt a chunked upload and confirm the progress panel reports a connection interruption rather than a JavaScript exception.

## If The Page Is Blank

First suspect a stale asset manifest or cached browser module.

```bash
curl -sS -L https://mixpost.peachyhq.com/mixpost/login | grep -Eo '/vendor/mixpost/assets/[^\" ]+'
curl -sS -L https://mixpost.peachyhq.com/mixpost/login \
  | grep -Eo '/vendor/mixpost/assets/[^\" ]+' \
  | sort -u \
  | while read -r asset; do
      printf '%s ' "$asset"
      curl -sSI "https://mixpost.peachyhq.com$asset" | head -1
    done
```

If any referenced asset returns `404`, inspect the current image and Compose mounts; do not reintroduce a pinned manifest unless there is a maintained custom client bundle. If assets return `200`, hard reload Chrome with `Cmd+Shift+R` or test in a private window.

## Rollback

Use the timestamped backup from `backups/update-$stamp` to restore host-mounted overrides. If the new image itself is bad, pin the previous image digest in `docker-compose.yml`, then recreate only the app container.

```bash
cp backups/update-YYYYMMDD-HHMMSS/* .
docker compose up -d --force-recreate mixpost
```
