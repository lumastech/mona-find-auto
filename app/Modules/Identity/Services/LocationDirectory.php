<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use Illuminate\Support\Facades\Cache;

/**
 * The Zambian province and town reference list, shaped for address pickers.
 *
 * The list changes about as often as Zambia redraws a province, so it is
 * cached rather than queried on every registration page render.
 */
class LocationDirectory
{
    private const CACHE_KEY = 'identity.locations';

    /**
     * Provinces in display order, each with its towns.
     *
     * @return array<int, array{id: int, name: string, cities: array<int, array{id: int, name: string, is_major: bool}>}>
     */
    public function provincesWithCities(): array
    {
        /** @var array<int, array{id: int, name: string, cities: array<int, array{id: int, name: string, is_major: bool}>}> */
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => Province::query()
            ->with('cities:id,province_id,name,is_major')
            ->orderBy('position')
            ->get()
            ->map(fn (Province $province): array => [
                'id' => $province->id,
                'name' => $province->name,
                'cities' => $this->cityOptions($province),
            ])
            ->values()
            ->all());
    }

    /**
     * @return array<int, array{id: int, name: string, is_major: bool}>
     */
    private function cityOptions(Province $province): array
    {
        return $province->cities
            ->map(static fn (City $city): array => [
                'id' => $city->id,
                'name' => $city->name,
                'is_major' => $city->is_major,
            ])
            ->values()
            ->all();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
