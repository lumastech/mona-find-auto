<?php

declare(strict_types=1);

use App\Modules\Catalog\Jobs\TranscodeListingVideo;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ListingMediaService;
use App\Modules\Catalog\Support\ListingWatermarker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * The pipeline's promise is that what a buyer is served is never what the
 * seller uploaded: the original stays private, and the public copy is a
 * resized, watermarked WebP.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    $this->media = app(ListingMediaService::class);
    $this->product = Product::factory()->draft()->create();
});

it('keeps the original on the private disk and the conversions on the public one', function (): void {
    $this->media->addPhotos($this->product, [UploadedFile::fake()->image('injector.jpg', 1600, 1200)]);

    $media = $this->product->refresh()->getFirstMedia(Product::PHOTOS_COLLECTION);

    expect($media->disk)->toBe('local')
        ->and($media->conversions_disk)->toBe('public')
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeTrue();
});

it('converts a listing photo to webp at every display size', function (): void {
    $this->media->addPhotos($this->product, [UploadedFile::fake()->image('injector.jpg', 1600, 1200)]);

    $media = $this->product->refresh()->getFirstMedia(Product::PHOTOS_COLLECTION);

    foreach (Product::DISPLAY_CONVERSIONS as $conversion) {
        $path = $media->getPathRelativeToRoot($conversion);

        expect(Storage::disk('public')->exists($path))->toBeTrue()
            ->and($path)->toEndWith('.webp');

        $bytes = Storage::disk('public')->get($path);

        /* "RIFF....WEBP" — the container's own magic bytes, not the filename. */
        expect(substr($bytes, 0, 4))->toBe('RIFF')
            ->and(substr($bytes, 8, 4))->toBe('WEBP');
    }
});

it('resizes each conversion within its declared bounds', function (): void {
    $this->media->addPhotos($this->product, [UploadedFile::fake()->image('injector.jpg', 2400, 1800)]);

    $media = $this->product->refresh()->getFirstMedia(Product::PHOTOS_COLLECTION);

    $sizes = ['thumb' => 320, 'card' => 640, 'web' => 1400];

    foreach ($sizes as $conversion => $bound) {
        [$width, $height] = getimagesizefromstring(
            Storage::disk('public')->get($media->getPathRelativeToRoot($conversion)),
        );

        expect(max($width, $height))->toBeLessThanOrEqual($bound);
    }
});

it('burns the watermark into the display conversions', function (): void {
    /*
     * The fixture is a flat image, so any variation in its pixels was drawn
     * there by the pipeline. The wordmark sits inset from the bottom-right
     * corner, so that quadrant gains colours the opposite one does not —
     * which is what says something was drawn rather than that the bytes
     * merely changed.
     */
    $this->media->addPhotos($this->product, [UploadedFile::fake()->image('injector.jpg', 1200, 900)]);

    $media = $this->product->refresh()->getFirstMedia(Product::PHOTOS_COLLECTION);
    $image = imagecreatefromstring(Storage::disk('public')->get($media->getPathRelativeToRoot('web')));

    $width = imagesx($image);
    $height = imagesy($image);

    $coloursIn = static function (int $fromX, int $fromY) use ($image, $width, $height): int {
        $seen = [];

        for ($x = $fromX; $x < $fromX + intdiv($width, 2); $x += 2) {
            for ($y = $fromY; $y < $fromY + intdiv($height, 2); $y += 2) {
                $seen[imagecolorat($image, $x, $y)] = true;
            }
        }

        return count($seen);
    };

    expect($coloursIn(intdiv($width, 2), intdiv($height, 2)))
        ->toBeGreaterThan($coloursIn(0, 0));
});

it('leaves the pipeline working when the watermark asset is missing', function (): void {
    $this->app->instance(ListingWatermarker::class, new ListingWatermarker('/does/not/exist.png'));

    $this->media->addPhotos($this->product, [UploadedFile::fake()->image('injector.jpg', 800, 600)]);

    $media = $this->product->refresh()->getFirstMedia(Product::PHOTOS_COLLECTION);

    expect(Storage::disk('public')->exists($media->getPathRelativeToRoot('web')))->toBeTrue();
});

it('refuses more photos than a listing may hold', function (): void {
    $photos = array_map(
        static fn (int $index): UploadedFile => UploadedFile::fake()->image("part-{$index}.jpg", 400, 300),
        range(1, Product::MAX_PHOTOS + 1),
    );

    expect(fn () => $this->media->addPhotos($this->product, $photos))
        ->toThrow(ValidationException::class);
});

it('counts photos already on the listing against the limit', function (): void {
    $this->media->addPhotos($this->product, [UploadedFile::fake()->image('one.jpg', 400, 300)]);

    $rest = array_map(
        static fn (int $index): UploadedFile => UploadedFile::fake()->image("part-{$index}.jpg", 400, 300),
        range(1, Product::MAX_PHOTOS),
    );

    expect(fn () => $this->media->addPhotos($this->product->refresh(), $rest))
        ->toThrow(ValidationException::class);
});

it('will not leave a published listing without a photo', function (): void {
    $product = Product::factory()->create();
    $this->media->addPhotos($product, [UploadedFile::fake()->image('only.jpg', 400, 300)]);

    $media = $product->refresh()->getFirstMedia(Product::PHOTOS_COLLECTION);

    expect(fn () => $this->media->removePhoto($product, $media))
        ->toThrow(ValidationException::class);
});

it('queues a transcode when a video is attached', function (): void {
    Queue::fake();

    $this->media->replaceVideo($this->product, fakeVideoUpload());

    Queue::assertPushed(TranscodeListingVideo::class);
});

it('keeps only the most recent video', function (): void {
    Queue::fake();

    $this->media->replaceVideo($this->product, fakeVideoUpload('first.mp4'));
    $this->media->replaceVideo($this->product->refresh(), fakeVideoUpload('second.mp4'));

    expect($this->product->refresh()->getMedia(Product::VIDEO_COLLECTION))->toHaveCount(1);
});
