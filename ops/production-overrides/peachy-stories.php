<?php

return [
    'enabled' => env('PEACHY_IG_STORIES_ENABLED', true),
    'download_videos' => env('PEACHY_IG_STORIES_DOWNLOAD_VIDEOS', true),
    'max_video_bytes' => (int) env('PEACHY_IG_STORIES_MAX_VIDEO_BYTES', 100 * 1024 * 1024),
    'max_image_bytes' => (int) env('PEACHY_IG_STORIES_MAX_IMAGE_BYTES', 20 * 1024 * 1024),
];
