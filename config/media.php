<?php
return [
    // 共有全体の仮上限。ゴミ箱の原本も同じ上限に含める。
    'quota_bytes' => (int) env('MEDIA_QUOTA_BYTES', 100 * 1024 ** 3),
    'image_max_kb' => (int) env('MEDIA_IMAGE_MAX_KB', 20 * 1024),
    'video_max_kb' => (int) env('MEDIA_VIDEO_MAX_KB', 500 * 1024),
    'root' => storage_path('app/private/media'),
];
