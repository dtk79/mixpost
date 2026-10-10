# Build only from the verified frozen 7.0.4 image, never the archived 6.2.0 image.
# Verify its image ID against release.json before building.
ARG MIXPOST_BASE_IMAGE=peachy/mixpost-pro:7.0.4-8def2a9
FROM ${MIXPOST_BASE_IMAGE}

ADD package.tgz /var/www/html/vendor/inovector/mixpost-pro-team/
# Publish the complete rebuilt asset set. Old asset files remain available to already-open tabs.
COPY assets/ /var/www/html/public/vendor/mixpost/

RUN php -l /var/www/html/vendor/inovector/mixpost-pro-team/src/Support/ChunkedUpload.php

LABEL org.peachy.mixpost.upload-recovery="2026-10-10"
