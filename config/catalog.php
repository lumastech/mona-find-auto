<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Listing Video
    |--------------------------------------------------------------------------
    |
    | Sellers may attach one short walk-round video to a listing. Transcoding
    | it needs the ffmpeg binaries, which are not present on every box — where
    | they are missing the upload still succeeds and the original is kept, so
    | a missing dependency never costs a seller their listing.
    |
    | The length limit itself is a product rule and lives on the model as
    | App\Modules\Catalog\Models\Product::MAX_VIDEO_SECONDS.
    |
    */

    'video' => [
        'transcode_enabled' => env('CATALOG_VIDEO_TRANSCODE', true),
        'ffmpeg_binary' => env('CATALOG_FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('CATALOG_FFPROBE_BINARY', 'ffprobe'),
        'max_height' => 720,
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing Media Limits
    |--------------------------------------------------------------------------
    |
    | Upload ceilings enforced by the form requests. The photo count itself is
    | a product rule and lives on the Product model beside the collection it
    | governs.
    |
    */

    'media' => [
        'max_photo_kilobytes' => env('CATALOG_MAX_PHOTO_KB', 8192),
        'max_video_kilobytes' => env('CATALOG_MAX_VIDEO_KB', 102400),
    ],

];
