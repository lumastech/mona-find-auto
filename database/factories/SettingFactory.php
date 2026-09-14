<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use App\Support\Settings\SettingType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->word(),
            'group' => 'test',
            'type' => SettingType::String,
            'value' => fake()->word(),
            'label' => fake()->sentence(3),
            'description' => null,
            'is_public' => false,
        ];
    }

    public function ofType(SettingType $type, mixed $value): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'value' => $type->encode($value),
        ]);
    }

    public function public(): self
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }
}
