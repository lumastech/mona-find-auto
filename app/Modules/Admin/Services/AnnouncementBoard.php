<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Enums\AnnouncementAudience;
use App\Modules\Admin\Models\Announcement;
use Illuminate\Http\Request;

/**
 * Which banners are showing, in which area, right now.
 *
 * Read on every single page render across all three areas, so it is cached
 * for a minute. A minute is the compromise the scheduling requirement forces:
 * a banner with a start time has to appear without anybody pressing anything,
 * and a query per request for a table that is empty most of the time is a
 * cost every visitor pays for a feature used a few times a year.
 *
 * The cache is dropped whenever staff touch an announcement, so an urgent
 * banner going up is immediate and only the scheduled ones wait.
 */
class AnnouncementBoard
{
    public const CACHE_KEY = 'monafind.announcements.live';

    private const CACHE_SECONDS = 60;

    /**
     * The banners for the area this request is in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forRequest(Request $request): array
    {
        $area = $this->areaFor($request);

        return array_values(array_filter(
            $this->live(),
            static fn (array $banner): bool => AnnouncementAudience::from($banner['audience'])->reaches($area),
        ));
    }

    /**
     * Every banner currently inside its window, whatever the audience.
     *
     * @return array<int, array<string, mixed>>
     */
    public function live(): array
    {
        /** @var array<int, array<string, mixed>> $banners */
        $banners = cache()->remember(self::CACHE_KEY, self::CACHE_SECONDS, static fn (): array => Announcement::query()
            ->showing()
            ->orderByDesc('level')
            ->orderByDesc('starts_at')
            ->get()
            ->map(static fn (Announcement $announcement): array => [
                ...$announcement->toBanner(),
                'audience' => $announcement->audience->value,
            ])
            ->all());

        return $banners;
    }

    public function flush(): void
    {
        cache()->forget(self::CACHE_KEY);
    }

    /**
     * Which of the three Inertia areas this request belongs to.
     */
    private function areaFor(Request $request): string
    {
        return match (true) {
            $request->is('admin', 'admin/*') => 'admin',
            $request->is('seller', 'seller/*') => 'seller',
            default => 'storefront',
        };
    }
}
