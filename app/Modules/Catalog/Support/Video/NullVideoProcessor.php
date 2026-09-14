<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support\Video;

use App\Modules\Catalog\Contracts\VideoProcessor;

/**
 * The processor used where ffmpeg is not installed — tests, and any box that
 * simply does not have it.
 *
 * It reports itself unavailable, which is what the transcode job checks
 * before doing anything. A seller's video is kept as they uploaded it rather
 * than being lost to a missing binary.
 */
class NullVideoProcessor implements VideoProcessor
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function probe(string $path): VideoProbe
    {
        return VideoProbe::unreadable();
    }

    public function transcode(string $sourcePath, string $destinationPath, int $maxHeight = 720): bool
    {
        return false;
    }

    public function posterFrame(string $sourcePath, string $destinationPath, float $atSecond = 1.0): bool
    {
        return false;
    }
}
