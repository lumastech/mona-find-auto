<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Database\Factories;

use App\Models\User;
use App\Modules\Ratings\Enums\ReportReason;
use App\Modules\Ratings\Enums\ReportStatus;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Models\RatingReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatingReport>
 */
class RatingReportFactory extends Factory
{
    protected $model = RatingReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rating_id' => Rating::factory(),
            'reported_by' => User::factory(),
            'reason' => ReportReason::Untrue,
            'details' => $this->faker->sentence(),
            'status' => ReportStatus::Open,
        ];
    }

    public function upheld(): static
    {
        return $this->state([
            'status' => ReportStatus::Upheld,
            'reviewed_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state([
            'status' => ReportStatus::Dismissed,
            'reviewed_at' => now(),
        ]);
    }
}
