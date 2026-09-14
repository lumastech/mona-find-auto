<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Contracts\VideoProcessor;
use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Turns a seller's uploaded clip into something a phone can play.
 *
 * Sellers upload whatever their phone recorded — often a 40MB MOV. This
 * transcodes it to a faststart MP4 and pulls a poster frame, so the listing
 * page can show a still and only fetch the video when it is asked for.
 *
 * A clip over the allowed length is removed and the seller told, rather than
 * quietly truncated: they should know their walk-round was too long, not
 * discover half of it missing.
 */
class TranscodeListingVideo implements ShouldQueue
{
    use Queueable;

    /** ffmpeg is slow and occasionally flaky; two tries, spaced out. */
    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly int $mediaId)
    {
        $this->onQueue(config('monafind.queues.media'));
    }

    public function handle(VideoProcessor $processor): void
    {
        $media = Media::query()->find($this->mediaId);

        if ($media === null || ! $media->model instanceof Product) {
            return;
        }

        if (! $processor->isAvailable()) {
            /* No ffmpeg here. The original stays and the listing still works. */
            Log::info('Listing video left untranscoded: no video processor available.', [
                'media_id' => $media->getKey(),
            ]);

            return;
        }

        $source = $this->pullToTemporaryFile($media);

        try {
            $probe = $processor->probe($source);

            if ($probe->exceeds(Product::MAX_VIDEO_SECONDS)) {
                $this->rejectTooLong($media, $probe->durationSeconds);

                return;
            }

            $this->storePoster($processor, $media, $source);
            $this->storeTranscode($processor, $media, $source);
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    /**
     * The clip broke the length rule, so it does not stay on the listing.
     */
    private function rejectTooLong(Media $media, float $duration): void
    {
        Log::warning('Listing video rejected as too long.', [
            'media_id' => $media->getKey(),
            'duration' => $duration,
            'maximum' => Product::MAX_VIDEO_SECONDS,
        ]);

        $media->setCustomProperty('rejected_reason', sprintf(
            'This video is %d seconds long. Listing videos must be %d seconds or shorter.',
            (int) ceil($duration),
            Product::MAX_VIDEO_SECONDS,
        ));
        $media->save();

        $media->delete();
    }

    /**
     * The still the listing page shows until somebody presses play.
     */
    private function storePoster(VideoProcessor $processor, Media $media, string $source): void
    {
        $poster = $this->temporaryPath('jpg');

        if (! $processor->posterFrame($source, $poster)) {
            return;
        }

        $this->putConversion($media, 'poster', $poster);
        unlink($poster);
    }

    private function storeTranscode(VideoProcessor $processor, Media $media, string $source): void
    {
        $output = $this->temporaryPath('mp4');

        if (! $processor->transcode($source, $output)) {
            return;
        }

        $this->putConversion($media, 'web', $output);
        unlink($output);
    }

    /**
     * Write a derived file beside the conversions media library generates, on
     * the public conversions disk, and record where it went.
     *
     * Media library does not manage video conversions itself, so the paths
     * are kept on the media row rather than guessed at read time.
     */
    private function putConversion(Media $media, string $name, string $localPath): void
    {
        $disk = $media->conversions_disk ?? $media->disk;
        $directory = dirname($media->getPathRelativeToRoot());
        $extension = pathinfo($localPath, PATHINFO_EXTENSION);
        $relative = trim($directory, '/').'/conversions/'.$media->getKey().'-'.$name.'.'.$extension;

        Storage::disk($disk)->put($relative, (string) file_get_contents($localPath));

        $media->setCustomProperty("video.{$name}", $relative);
        $media->save();
    }

    /**
     * Bring the original down from wherever it is stored, so ffmpeg has a
     * real file to work on whether the disk is local or object storage.
     */
    private function pullToTemporaryFile(Media $media): string
    {
        $path = $this->temporaryPath(pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'mp4');

        file_put_contents($path, Storage::disk($media->disk)->get($media->getPathRelativeToRoot()));

        return $path;
    }

    private function temporaryPath(string $extension): string
    {
        return tempnam(sys_get_temp_dir(), 'mfa-video-').'.'.$extension;
    }
}
