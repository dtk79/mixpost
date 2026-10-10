# Overlay only on the verified local-storage release; preserve all backend code.
ARG BASE=peachy/mixpost-pro:7.0.4-local-uploads-20261010
FROM ${BASE}
COPY useChunkedUpload.js /var/www/html/vendor/inovector/mixpost-pro-team/resources/js/Composables/useChunkedUpload.js
COPY UploadProgressItem.vue /var/www/html/vendor/inovector/mixpost-pro-team/resources/js/Components/Media/UploadProgressItem.vue
COPY assets/ /var/www/html/vendor/inovector/mixpost-pro-team/resources/dist/vendor/mixpost/
COPY assets/ /var/www/html/public/vendor/mixpost/
RUN find /var/www/html/public/vendor/mixpost /var/www/html/vendor/inovector/mixpost-pro-team/resources/dist/vendor/mixpost -type d -exec chmod 755 {} + \
    && find /var/www/html/public/vendor/mixpost /var/www/html/vendor/inovector/mixpost-pro-team/resources/dist/vendor/mixpost -type f -exec chmod 644 {} +
# Keep older published chunks for existing tabs; replace the active manifest.
LABEL org.peachy.mixpost.upload-progress="2026-10-10"
