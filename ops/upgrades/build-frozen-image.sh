#!/usr/bin/env bash
# Run on the Docker host after compatibility tests, against an isolated prepared container.
# Usage: build-frozen-image.sh CONTAINER BASE_DIGEST OUTPUT_TAG OVERRIDE_COMMIT
set -euo pipefail
source_container=${1:?source container required}
base_digest=${2:?immutable vendor digest required}
output_tag=${3:?new image tag required}
override_commit=${4:?override commit required}
legacy_php_series=${MIXPOST_LEGACY_PHP_CONFIG_SERIES:-}
[[ -z "$legacy_php_series" || "$legacy_php_series" =~ ^[0-9]+\.[0-9]+$ ]] || exit 1
[[ "$base_digest" == *@sha256:* ]] || { echo 'Base must be digest-pinned' >&2; exit 1; }
[[ "$override_commit" =~ ^[0-9a-f]{40}$ ]] || exit 1
script_dir=$(cd "$(dirname "$0")" && pwd)
build_dir=$(mktemp -d /tmp/mixpost-frozen-build.XXXXXX)
trap 'rm -rf "$build_dir"' EXIT
# Export only code. Credentials, compiled config, logs, media and user sessions are excluded.
docker exec "$source_container" tar -C /var/www/html \
  --exclude='./.env' --exclude='./.env.*' --exclude='./auth.json' --exclude='./.git' \
  --exclude='./bootstrap/cache/*' --exclude='./storage/*' --exclude='./ops' \
  --exclude='./node_modules' -czf - . > "$build_dir/app.tar.gz"
docker exec "$source_container" php -r 'require "/var/www/html/vendor/autoload.php"; echo json_encode(["package"=>"inovector/mixpost-pro-team","version"=>Composer\InstalledVersions::getPrettyVersion("inovector/mixpost-pro-team"),"source"=>Composer\InstalledVersions::getReference("inovector/mixpost-pro-team"),"lockSha256"=>hash_file("sha256","/var/www/html/composer.lock")], JSON_PRETTY_PRINT);' > "$build_dir/peachy-release.json"
cp "$script_dir/start-frozen.sh" "$build_dir/start-frozen.sh"
cat > "$build_dir/Dockerfile" <<'DOCKER'
ARG BASE
FROM ${BASE}
ARG LEGACY_PHP_CONFIG_SERIES
# Preserve existing read-only Compose mounts across a reviewed PHP series change.
# Docker resolves the legacy destination through this alias to the active series.
RUN if [ -n "$LEGACY_PHP_CONFIG_SERIES" ]; then \
      active_series=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;') && \
      test -d "/etc/php/$active_series/fpm/conf.d" && \
      test -d "/etc/php/$active_series/cli/conf.d" && \
      test ! -e "/etc/php/$LEGACY_PHP_CONFIG_SERIES" && \
      ln -s "$active_series" "/etc/php/$LEGACY_PHP_CONFIG_SERIES"; \
    fi
ADD app.tar.gz /var/www/html/
COPY start-frozen.sh /usr/local/bin/start.sh
COPY peachy-release.json /var/www/html/peachy-release.json
RUN chmod 755 /usr/local/bin/start.sh && mkdir -p /var/www/html/storage/app/public /var/www/html/storage/framework/cache/data /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/storage/logs /var/www/html/bootstrap/cache && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
ARG OVERRIDE_COMMIT
LABEL com.ducatix.mixpost.overrides=$OVERRIDE_COMMIT
LABEL com.ducatix.mixpost.frozen=true
DOCKER
docker build --build-arg "BASE=$base_digest" --build-arg "OVERRIDE_COMMIT=$override_commit" --build-arg "LEGACY_PHP_CONFIG_SERIES=$legacy_php_series" -t "$output_tag" "$build_dir"
docker image inspect "$output_tag" --format '{{.Id}}'
