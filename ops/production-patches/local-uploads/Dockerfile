ARG BASE
FROM ${BASE}
COPY LocalMediaStaging.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Support/LocalMediaStaging.php
COPY LocalChunkedUpload.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Support/LocalChunkedUpload.php
COPY StagedFileTransfer.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Support/StagedFileTransfer.php
COPY DeliverStagedMediaJob.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Jobs/DeliverStagedMediaJob.php
COPY ChunkedUploadInitiate.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Media/ChunkedUploadInitiate.php
COPY ChunkedUploadChunk.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Media/ChunkedUploadChunk.php
COPY ChunkedUploadAbort.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Media/ChunkedUploadAbort.php
COPY ChunkedUploadComplete.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/Media/ChunkedUploadComplete.php
COPY MediaUploadFile.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Requests/Workspace/MediaUploadFile.php
COPY MediaResource.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Http/Base/Resources/MediaResource.php
COPY CopyMedia.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Actions/Media/CopyMedia.php
COPY VideoProbe.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Support/VideoProbe.php
COPY MediaVideoThumbConversion.php /var/www/html/vendor/inovector/mixpost-pro-team/src/MediaConversions/MediaVideoThumbConversion.php
COPY AccountPublishPost.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Actions/Post/AccountPublishPost.php
COPY AccountPublishPostJob.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Jobs/AccountPublishPostJob.php
COPY Schedule.php /var/www/html/vendor/inovector/mixpost-pro-team/src/Schedule.php
COPY AppServiceProvider.php /var/www/html/app/Providers/AppServiceProvider.php
LABEL com.ducatix.mixpost.local-uploads=true
