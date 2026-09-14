<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Support\Video\VideoProbe;

/**
 * Reading and transcoding a listing's walk-round video.
 *
 * Behind an interface for the same reason the payment gateway is: ffmpeg is
 * an external binary that may not exist on a given box, and no test should
 * depend on one being installed. CatalogServiceProvider binds the ffmpeg
 * implementation in production and a fake everywhere it is not available.
 */
interface VideoProcessor
{
    /**
     * Whether this processor can actually do any work.
     *
     * A deployment without ffmpeg keeps the seller's original video and skips
     * the transcode rather than failing the upload.
     */
    public function isAvailable(): bool;

    /**
     * Read a video's duration and dimensions without decoding it.
     */
    public function probe(string $path): VideoProbe;

    /**
     * Transcode to web-playable MP4, capped at the given height.
     *
     * @return bool Whether an output file was produced.
     */
    public function transcode(string $sourcePath, string $destinationPath, int $maxHeight = 720): bool;

    /**
     * Pull a single frame out as the poster image.
     *
     * @param  float  $atSecond  Where to take the frame from.
     * @return bool Whether an output file was produced.
     */
    public function posterFrame(string $sourcePath, string $destinationPath, float $atSecond = 1.0): bool;
}
