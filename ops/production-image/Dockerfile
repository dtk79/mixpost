ARG MIXPOST_BASE_IMAGE=inovector/mixpost-pro-team@sha256:9ee0493811b5fbf11190cde21436f1f789b789117781f1b7f938ff1e67ff4117
ARG MIXPOST_PRO_PACKAGE_VERSION=6.2.0

FROM ${MIXPOST_BASE_IMAGE} AS upstream

FROM upstream AS app
ADD app.tgz /var/www/html/
COPY upload-resilience.patch /tmp/upload-resilience.patch
COPY startup-baked-app.patch /tmp/startup-baked-app.patch

RUN mkdir -p /var/www/html/bootstrap/cache \
    && chmod 775 /var/www/html/bootstrap/cache \
    && cd /var/www/html/vendor/inovector/mixpost-pro-team \
    && patch --batch --forward -p1 < /tmp/upload-resilience.patch \
    && patch --batch --forward -p1 -d / < /tmp/startup-baked-app.patch

FROM node:20-bookworm-slim AS assets
COPY --from=app /var/www/html/vendor/inovector/mixpost-pro-team /app
WORKDIR /app
RUN npm ci --ignore-scripts && npm run build && rm -rf node_modules

FROM upstream
ARG MIXPOST_PRO_PACKAGE_VERSION

COPY --from=app /var/www/html /var/www/html
COPY --from=app /usr/local/bin/start.sh /usr/local/bin/start.sh
COPY --from=assets /app/resources/dist/vendor/mixpost /var/www/html/vendor/inovector/mixpost-pro-team/resources/dist/vendor/mixpost

ENV SKIP_DOWNLOAD=true \
    MIXPOST_PRO_PACKAGE_VERSION=${MIXPOST_PRO_PACKAGE_VERSION}
