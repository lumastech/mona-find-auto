<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support\Video;

/**
 * What ffprobe can tell us about a listing video before it is transcoded.
 */
final readonly class VideoProbe
{
    public function __construct(
        public float $durationSeconds,
        public int $width,
        public int $height,
        public bool $readable,
    ) {}

    /**
     * An unreadable file: a corrupt upload, or no ffmpeg on this box.
     */
    public static function unreadable(): self
    {
        return new self(0.0, 0, 0, false);
    }

    /**
     * Whether the clip is longer than sellers are allowed to post.
     *
     * Half a second of slack: phone cameras routinely record a 60-second clip
     * as 60.04, and rejecting that would be maddening for the seller.
     */
    public function exceeds(int $maximumSeconds): bool
    {
        return $this->readable && $this->durationSeconds > $maximumSeconds + 0.5;
    }
}
