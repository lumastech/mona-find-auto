<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Support;

use App\Modules\Ratings\Enums\RatingDirection;
use Illuminate\Database\Eloquent\Model;

/**
 * "You can rate this, and you have not yet."
 *
 * One of these is what puts the rating card on a completed order. It carries
 * the direction and who is being rated rather than a boolean, because the
 * same completed order offers a different prompt to each side and the page
 * has to know which it is showing.
 */
final readonly class RatingPrompt
{
    public function __construct(
        public RatingDirection $direction,
        public Model $source,
        public Model $ratee,
        public string $rateeName,
        public string $heading,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'is_public' => $this->direction->isPublic(),
            'source_type' => $this->source->getMorphClass(),
            'source_id' => $this->source->getKey(),
            'ratee_name' => $this->rateeName,
            'heading' => $this->heading,
        ];
    }
}
