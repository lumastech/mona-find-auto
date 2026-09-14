<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Models\Rating;
use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounterTone;
use App\Support\Console\ProvidesConsoleCounters;

/**
 * What the staff dashboard says about reviews.
 */
class RatingConsoleCounters implements ProvidesConsoleCounters
{
    public function counters(): array
    {
        $flagged = Rating::query()->needingReview()->count();

        return [
            new ConsoleCounter(
                key: 'ratings.flagged',
                label: 'Reviews awaiting a decision',
                value: $flagged,
                href: route('admin.ratings.index', ['status' => RatingStatus::PendingReview->value]),
                ability: 'moderate',
                tone: $flagged > 0 ? ConsoleCounterTone::Warning : ConsoleCounterTone::Neutral,
                hint: 'Held by the screen, or objected to by somebody.',
            ),
        ];
    }
}
