#!/bin/sh
set -eu

full_run=${1:?Full manifest run name is required}
tail_run=${2:?Tail cleanup run name is required}
concurrency=${3:-64}
# Limit parallel batches after observed truncated S3 responses at higher concurrency.
if [ "$concurrency" -gt 32 ]; then
    concurrency=32
fi
engine=/var/www/html/storage/app/peachy-storage-cleanup/cleanup-imported-thumbnails.php
root=/var/www/html/storage/app/peachy-storage-cleanup

php -d memory_limit=768M "$engine" delete "$full_run" "$concurrency"
php -d memory_limit=768M "$engine" verify "$full_run"

# By the time the main purge finishes, the grace-period leftovers are normally old enough.
# Keep their current references protected and apply the same one-hour boundary again.
if [ ! -f "$root/$tail_run/plan.json" ]; then
    php -d memory_limit=768M "$engine" plan "$tail_run"
fi
php -d memory_limit=768M "$engine" delete "$tail_run" "$concurrency"
php -d memory_limit=768M "$engine" verify "$tail_run"
echo FULL_THUMBNAIL_CLEANUP_COMPLETE
