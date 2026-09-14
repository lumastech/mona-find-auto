<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Events;

use App\Models\User;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Models\Rating;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Staff moved a rating between published, in review and hidden.
 *
 * Carries where it came from, because hiding a rating takes its stars out of
 * the aggregate and restoring one puts them back — a listener that only knew
 * the new status could not tell which of those just happened.
 */
class RatingModerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Rating $rating,
        public RatingStatus $from,
        public ?User $actor = null,
    ) {}

    /**
     * Whether the stars this rating carries just entered or left an
     * aggregate.
     */
    public function changesAggregate(): bool
    {
        return $this->from->countsTowardsAggregate() !== $this->rating->status->countsTowardsAggregate();
    }
}
