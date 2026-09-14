<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Database\Factories;

use App\Models\User;
use App\Modules\Privacy\Enums\ConsentType;
use App\Modules\Privacy\Models\ConsentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentRecord>
 */
class ConsentRecordFactory extends Factory
{
    protected $model = ConsentRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ConsentType::Privacy,
            'granted' => true,
            'document_slug' => 'privacy',
            'document_version' => 1,
            'source' => 'registration',
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'recorded_at' => now(),
            'created_at' => now(),
        ];
    }

    public function ofType(ConsentType $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'document_slug' => $type->contentPageSlug(),
        ]);
    }

    /**
     * A withdrawal. The row that takes an earlier grant back.
     */
    public function withdrawn(): self
    {
        return $this->state(fn (): array => [
            'granted' => false,
            'source' => 'settings',
        ]);
    }

    /**
     * Consent given against an older version of the document, so a test can
     * exercise the re-consent path.
     */
    public function atVersion(int $version): self
    {
        return $this->state(fn (): array => ['document_version' => $version]);
    }
}
