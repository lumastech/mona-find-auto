<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\AlignPosition;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\Unit;
use Spatie\Image\Image;
use Throwable;

/**
 * Burns the MonaFindAuto wordmark into a listing's public image conversions.
 *
 * Media library's conversion API has no watermark step, so this runs after a
 * conversion is generated, reading and writing through the conversions disk
 * rather than a local path — the disk is local in development and object
 * storage in production, and the pipeline should not care which.
 *
 * The mark is sized as a fraction of the image and set at low opacity: large
 * enough that a crop cannot remove it without visibly cropping the part,
 * faint enough that it does not hide what the buyer came to look at.
 */
final readonly class ListingWatermarker
{
    /** Roughly a third of the image width, whatever the conversion size. */
    private const WIDTH_PERCENT = 32;

    private const PADDING_PERCENT = 3;

    /** Visible on a photograph, never competing with it. */
    private const ALPHA = 35;

    public function __construct(private string $watermarkPath) {}

    public static function default(): self
    {
        return new self(resource_path('images/watermark.png'));
    }

    /**
     * Whether there is a mark to burn in at all.
     *
     * A missing asset must not fail the pipeline: an unwatermarked photo is a
     * cosmetic problem, a listing stuck without conversions is a broken page.
     */
    public function isAvailable(): bool
    {
        return is_file($this->watermarkPath);
    }

    /**
     * Apply the mark to one generated conversion, in place.
     *
     * @return bool Whether the file was rewritten.
     */
    public function applyTo(string $disk, string $relativePath, AlignPosition $position = AlignPosition::BottomRight): bool
    {
        if (! $this->isAvailable() || ! Storage::disk($disk)->exists($relativePath)) {
            return false;
        }

        $working = tempnam(sys_get_temp_dir(), 'mfa-wm-').'.'.pathinfo($relativePath, PATHINFO_EXTENSION);

        try {
            file_put_contents($working, Storage::disk($disk)->get($relativePath));

            Image::load($working)
                ->watermark(
                    $this->watermarkPath,
                    $position,
                    paddingX: self::PADDING_PERCENT,
                    paddingY: self::PADDING_PERCENT,
                    paddingUnit: Unit::Percent,
                    width: self::WIDTH_PERCENT,
                    widthUnit: Unit::Percent,
                    fit: Fit::Contain,
                    alpha: self::ALPHA,
                )
                ->save();

            Storage::disk($disk)->put($relativePath, (string) file_get_contents($working));

            return true;
        } catch (Throwable) {
            /*
             * A photo that could not be marked is still a usable photo. The
             * listing is what matters; the pipeline logs and moves on rather
             * than leaving the seller with a listing that will not render.
             */
            return false;
        } finally {
            if (is_file($working)) {
                unlink($working);
            }
        }
    }

    /**
     * Whether this conversion is one buyers see, and so one that carries the
     * mark. Internal sizes are left alone.
     */
    public static function marksConversion(string $conversion): bool
    {
        return in_array($conversion, Product::DISPLAY_CONVERSIONS, true);
    }
}
