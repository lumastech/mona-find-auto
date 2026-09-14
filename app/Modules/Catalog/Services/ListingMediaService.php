<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Jobs\TranscodeListingVideo;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A listing's photos and its walk-round video.
 *
 * Originals land on the private disk; what buyers get is the resized,
 * watermarked WebP conversion. Conversions are generated on the media queue,
 * so a seller adding ten photos is not left staring at a spinner.
 *
 * The photo count is enforced here rather than in a form request because it
 * has to hold for the API and any seeder too — and because "at least one
 * photo" is the rule a listing is submitted against, not one a single form
 * happens to check.
 */
class ListingMediaService
{
    /**
     * Add photos to a listing.
     *
     * @param  array<int, UploadedFile>  $photos
     * @return array<int, Media>
     *
     * @throws ValidationException when the listing would end up over the limit
     */
    public function addPhotos(Product $product, array $photos): array
    {
        $existing = $product->getMedia(Product::PHOTOS_COLLECTION)->count();

        if ($existing + count($photos) > Product::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => __('A listing can have at most :max photos; this one already has :existing.', [
                    'max' => Product::MAX_PHOTOS,
                    'existing' => $existing,
                ]),
            ]);
        }

        return array_map(
            fn (UploadedFile $photo): Media => $product
                ->addMedia($photo)
                ->withCustomProperties(['original_name' => $photo->getClientOriginalName()])
                ->toMediaCollection(Product::PHOTOS_COLLECTION),
            array_values($photos),
        );
    }

    /**
     * Replace the listing's video.
     *
     * The collection is single-file, so adding one drops the old one. Length
     * cannot be checked here — nothing in PHP can read a duration without
     * decoding the file — so the transcode job checks it and removes a clip
     * that is over, telling the seller why.
     */
    public function replaceVideo(Product $product, UploadedFile $video): Media
    {
        $media = $product
            ->addMedia($video)
            ->withCustomProperties(['original_name' => $video->getClientOriginalName()])
            ->toMediaCollection(Product::VIDEO_COLLECTION);

        TranscodeListingVideo::dispatch($media->getKey());

        return $media;
    }

    /**
     * Remove one photo.
     *
     * A published listing is not allowed to lose its last photo: it would go
     * on being shown to buyers with nothing to look at.
     *
     * @throws ValidationException
     */
    public function removePhoto(Product $product, Media $media): void
    {
        $this->guardBelongsTo($product, $media);

        $remaining = $product->getMedia(Product::PHOTOS_COLLECTION)->count() - 1;

        if ($remaining < Product::MIN_PHOTOS && $product->isPublished()) {
            throw ValidationException::withMessages([
                'photos' => __('A published listing needs at least one photo. Add another before removing this one.'),
            ]);
        }

        $media->delete();
        $product->unsetRelation('media');
    }

    public function removeVideo(Product $product): void
    {
        $product->clearMediaCollection(Product::VIDEO_COLLECTION);
    }

    /**
     * Put the photos in the order the seller dragged them into.
     *
     * The first photo is the one that appears on every card and in every
     * search result, so this is a real editorial decision rather than a
     * nicety.
     *
     * @param  array<int, int>  $mediaIds  In the order they should appear.
     */
    public function reorderPhotos(Product $product, array $mediaIds): void
    {
        $owned = $product->getMedia(Product::PHOTOS_COLLECTION)->pluck('id')->all();

        Media::setNewOrder(array_values(array_filter(
            $mediaIds,
            static fn (int $id): bool => in_array($id, $owned, true),
        )));
    }

    /**
     * @throws ValidationException when the media belongs to a different listing
     */
    private function guardBelongsTo(Product $product, Media $media): void
    {
        if ((int) $media->model_id !== $product->getKey() || $media->model_type !== $product->getMorphClass()) {
            throw ValidationException::withMessages([
                'photos' => __('That photo does not belong to this listing.'),
            ]);
        }
    }
}
