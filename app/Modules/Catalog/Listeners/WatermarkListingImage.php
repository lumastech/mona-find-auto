<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ListingWatermarker;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

/**
 * Burns the wordmark into each listing conversion as it is generated.
 *
 * Conversions already run on the queue, so this runs inside that job rather
 * than queueing another one — the file is on disk and warm, and a second hop
 * would only widen the window in which an unmarked image is reachable.
 *
 * Only the sizes buyers actually see are marked; see
 * Product::DISPLAY_CONVERSIONS.
 */
class WatermarkListingImage
{
    public function __construct(private readonly ListingWatermarker $watermarker) {}

    public function handle(ConversionHasBeenCompletedEvent $event): void
    {
        $media = $event->media;
        $conversion = $event->conversion->getName();

        if (! $media->model instanceof Product) {
            return;
        }

        if ($media->collection_name !== Product::PHOTOS_COLLECTION) {
            return;
        }

        if (! ListingWatermarker::marksConversion($conversion)) {
            return;
        }

        $applied = $this->watermarker->applyTo(
            $media->conversions_disk ?? $media->disk,
            $media->getPathRelativeToRoot($conversion),
            $media->model->watermarkPosition(),
        );

        if (! $applied) {
            Log::warning('Listing image conversion could not be watermarked.', [
                'media_id' => $media->getKey(),
                'conversion' => $conversion,
            ]);
        }
    }
}
