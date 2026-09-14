<?php

declare(strict_types=1);

namespace App\Modules\Admin\Database\Factories;

use App\Modules\Admin\Enums\AnnouncementAudience;
use App\Modules\Admin\Enums\AnnouncementLevel;
use App\Modules\Admin\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'level' => AnnouncementLevel::Info,
            'audience' => AnnouncementAudience::Everyone,
            'starts_at' => now()->subHour(),
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    /** Scheduled, not yet begun. */
    public function upcoming(): static
    {
        return $this->state(['starts_at' => now()->addDay()]);
    }

    /** Its window has closed. */
    public function finished(): static
    {
        return $this->state([
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Aimed at one area. Not named `for()`: that is Factory's own relationship
     * helper, and overriding it with a different signature is a fatal error.
     */
    public function shownTo(AnnouncementAudience $audience): static
    {
        return $this->state(['audience' => $audience]);
    }
}
