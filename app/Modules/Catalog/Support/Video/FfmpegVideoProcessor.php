<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support\Video;

use App\Modules\Catalog\Contracts\VideoProcessor;
use Illuminate\Support\Facades\Process;

/**
 * Transcoding through the ffmpeg binaries.
 *
 * Every call is a plain process invocation with an explicit argument array —
 * never a shell string — because the paths involved come from filenames a
 * seller chose.
 */
class FfmpegVideoProcessor implements VideoProcessor
{
    public function __construct(
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly string $ffprobe = 'ffprobe',
        private readonly int $timeoutSeconds = 600,
    ) {}

    public function isAvailable(): bool
    {
        return Process::run([$this->ffmpeg, '-version'])->successful();
    }

    public function probe(string $path): VideoProbe
    {
        $result = Process::timeout($this->timeoutSeconds)->run([
            $this->ffprobe,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height',
            '-show_entries', 'format=duration',
            '-of', 'json',
            $path,
        ]);

        if (! $result->successful()) {
            return VideoProbe::unreadable();
        }

        /** @var array{streams?: array<int, array{width?: int, height?: int}>, format?: array{duration?: string}} $data */
        $data = json_decode($result->output(), true) ?: [];

        return new VideoProbe(
            durationSeconds: (float) ($data['format']['duration'] ?? 0),
            width: (int) ($data['streams'][0]['width'] ?? 0),
            height: (int) ($data['streams'][0]['height'] ?? 0),
            readable: true,
        );
    }

    /**
     * H.264 in MP4 with the index at the front, so playback starts before the
     * file has finished downloading — which on a Zambian mobile connection is
     * the difference between a video being watched and being abandoned.
     */
    public function transcode(string $sourcePath, string $destinationPath, int $maxHeight = 720): bool
    {
        $result = Process::timeout($this->timeoutSeconds)->run([
            $this->ffmpeg,
            '-y',
            '-i', $sourcePath,
            /* Only ever scale down; upscaling a phone clip wastes bytes. */
            '-vf', "scale=-2:'min({$maxHeight},ih)'",
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '26',
            '-c:a', 'aac',
            '-b:a', '96k',
            '-movflags', '+faststart',
            $destinationPath,
        ]);

        return $result->successful() && is_file($destinationPath);
    }

    public function posterFrame(string $sourcePath, string $destinationPath, float $atSecond = 1.0): bool
    {
        $result = Process::timeout($this->timeoutSeconds)->run([
            $this->ffmpeg,
            '-y',
            '-ss', (string) $atSecond,
            '-i', $sourcePath,
            '-frames:v', '1',
            '-q:v', '3',
            $destinationPath,
        ]);

        return $result->successful() && is_file($destinationPath);
    }
}
