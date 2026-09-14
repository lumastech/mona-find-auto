<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Setting;
use App\Support\Money\Money;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Cached, typed access to the `settings` table.
 *
 * Reach for this through the settings() helper. The whole table is small and
 * read on nearly every request, so it is cached as a single entry and the
 * cache is dropped the moment a value changes.
 */
final class SettingsRepository
{
    public const CACHE_KEY = 'monafind.settings';

    /** @var array<string, array{type: string, value: string|null, group: string, is_public: bool}>|null */
    private ?array $resolved = null;

    public function __construct(private readonly CacheRepository $cache) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $record = $this->records()[$key] ?? null;

        if ($record === null) {
            return $default;
        }

        return SettingType::from($record['type'])->decode($record['value']);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->records());
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    public function money(string $key, ?Money $default = null): Money
    {
        $value = $this->get($key);

        return $value instanceof Money ? $value : ($default ?? Money::zero());
    }

    /**
     * @param  array<array-key, mixed>  $default
     * @return array<array-key, mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * Every setting in a group, keyed by its short name.
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $values = [];

        foreach ($this->records() as $key => $record) {
            if ($record['group'] === $group) {
                $values[$key] = SettingType::from($record['type'])->decode($record['value']);
            }
        }

        return $values;
    }

    /**
     * The settings marked safe to hand to the browser.
     *
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        $values = [];

        foreach ($this->records() as $key => $record) {
            if ($record['is_public']) {
                $values[$key] = SettingType::from($record['type'])->decode($record['value']);
            }
        }

        return $values;
    }

    /**
     * Change a setting, writing an audit row for the change.
     */
    public function set(string $key, mixed $value, ?Model $actor = null, ?string $reason = null): Setting
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();

        $before = $setting->value;
        $setting->value = $setting->type->encode($value);
        $setting->save();

        $this->flush();

        audit(
            $actor,
            'setting.updated',
            $setting,
            ['key' => $key, 'value' => $before],
            ['key' => $key, 'value' => $setting->value],
            $reason,
        );

        return $setting;
    }

    /**
     * Create or update a setting's definition. Used by seeders and by admin
     * screens that introduce new configurable values.
     *
     * @param  array{group?: string, type?: SettingType, label?: string, description?: string|null, is_public?: bool}  $definition
     */
    public function define(string $key, mixed $value, array $definition = []): Setting
    {
        $type = $definition['type'] ?? SettingType::String;

        $setting = Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'group' => $definition['group'] ?? 'general',
                'type' => $type,
                'label' => $definition['label'] ?? str($key)->afterLast('.')->headline()->toString(),
                'description' => $definition['description'] ?? null,
                'is_public' => $definition['is_public'] ?? false,
                'value' => $type->encode($value),
            ],
        );

        $this->flush();

        return $setting;
    }

    /**
     * Drop the cached snapshot; the next read reloads from the database.
     */
    public function flush(): void
    {
        $this->resolved = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array{type: string, value: string|null, group: string, is_public: bool}>
     */
    private function records(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        /** @var array<string, array{type: string, value: string|null, group: string, is_public: bool}> $records */
        $records = $this->cache->rememberForever(self::CACHE_KEY, static function (): array {
            /** Tolerate a database that has not been migrated yet. */
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return Setting::query()
                ->get(['key', 'type', 'value', 'group', 'is_public'])
                ->mapWithKeys(static fn (Setting $setting): array => [
                    $setting->key => [
                        'type' => $setting->type->value,
                        'value' => $setting->value,
                        'group' => $setting->group,
                        'is_public' => $setting->is_public,
                    ],
                ])
                ->all();
        });

        return $this->resolved = $records;
    }
}
