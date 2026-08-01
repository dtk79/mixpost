# Archived Peachy Mixpost Upload-Resilience Image

Production no longer uses this image. Pro Team 6.2.2 and later incorporated the required upload flow, so this directory is retained only as an incident-history and rollback reference. Do not build or promote it during a normal update, and never reuse its archived 6.2.0 defaults against a newer package.

If a regression explicitly requires reviving it, first rebase both patches against the exact target Pro Team source and update the pinned digest, package version, and tag. The image starts from a scrubbed archive, applies the upload-resilience patch, and rebuilds its client bundle. The archive must exclude `.env` and `storage`, so no credentials or uploaded media enter the image.

Historical build procedure:

```bash
docker exec mixpost-mixpost-1 sh -lc 'cd /var/www/html && tar --exclude=.env --exclude=storage --exclude=bootstrap/cache -czf - .' > app.tgz

docker build \
  --build-arg MIXPOST_BASE_IMAGE=inovector/mixpost-pro-team@sha256:9ee0493811b5fbf11190cde21436f1f789b789117781f1b7f938ff1e67ff4117 \
  --build-arg MIXPOST_PRO_PACKAGE_VERSION=6.2.0 \
  --tag peachy/mixpost-pro-team:6.2.0-upload-resilience.2 \
  .
```

The build fails when the patch no longer matches the upstream package. Rebase or remove the patch before promoting a newer Mixpost image.
