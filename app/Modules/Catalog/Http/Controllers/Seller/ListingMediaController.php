<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\Seller\ListingPhotoRequest;
use App\Modules\Catalog\Http\Requests\Seller\ListingVideoRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ListingMediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The listing media manager.
 *
 * Uploads return to the form rather than to a JSON endpoint, because the
 * conversions are generated on the queue: the seller sees the photo appear
 * immediately and the watermarked WebP replaces it a moment later.
 */
class ListingMediaController extends Controller
{
    public function __construct(private readonly ListingMediaService $media) {}

    public function storePhotos(ListingPhotoRequest $request, Product $product): RedirectResponse
    {
        $this->media->addPhotos($product, $request->photos());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photos uploaded.')]);

        return back();
    }

    public function destroyPhoto(Request $request, Product $product, Media $media): RedirectResponse
    {
        Gate::authorize('update', $product);

        $this->media->removePhoto($product, $media);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo removed.')]);

        return back();
    }

    /**
     * The first photo is the one on every card and every search result, so
     * the order is a real editorial decision.
     */
    public function reorderPhotos(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $validated = $request->validate([
            'media_ids' => ['required', 'array'],
            'media_ids.*' => ['integer'],
        ]);

        $this->media->reorderPhotos($product, array_map('intval', $validated['media_ids']));

        return back();
    }

    public function storeVideo(ListingVideoRequest $request, Product $product): RedirectResponse
    {
        $this->media->replaceVideo($product, $request->video());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Video uploaded. We will process it and let you know if it is too long.'),
        ]);

        return back();
    }

    public function destroyVideo(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $this->media->removeVideo($product);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Video removed.')]);

        return back();
    }
}
