<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Support\RatingParties;
use App\Modules\Ratings\Support\RatingPrompt;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may rate what, and whether they already have.
 *
 * Two gates, and they are separate because they fail for different reasons.
 * The resolver decides whether the thing has finished and whether this person
 * was part of it; this class decides whether the one rating that direction
 * allows has already been used. The database's unique index is the backstop
 * under both — the check here exists so a buyer pressing submit twice gets a
 * sentence instead of a constraint violation.
 *
 * The same two gates answer the UI's question, so the card on a completed
 * order and the guard on the POST it submits to cannot disagree.
 */
class RatingEligibility
{
    public function __construct(private readonly RatingSourceRegistry $sources) {}

    /**
     * Everything this person may still rate about this source.
     *
     * @return array<int, RatingPrompt>
     */
    public function promptsFor(Model $source, User $user): array
    {
        $resolver = $this->sources->find($source);

        if ($resolver === null) {
            return [];
        }

        $used = $this->directionsAlreadyRated($source);

        return array_values(array_filter(
            $resolver->prompts($source, $user),
            static fn (RatingPrompt $prompt): bool => ! in_array($prompt->direction->value, $used, true),
        ));
    }

    /**
     * Check everything, and hand back the two parties for the write.
     *
     * @throws RatingNotAllowed
     */
    public function assert(Model $source, RatingDirection $direction, User $user): RatingParties
    {
        $resolver = $this->sources->for($source);

        if ($resolver->source() !== $direction->source()) {
            throw RatingNotAllowed::directionUnavailable($direction);
        }

        if (! $resolver->isComplete($source)) {
            throw $this->notCompleted($source);
        }

        /* Throws when this person had nothing to do with the source. */
        $parties = $resolver->parties($source, $direction, $user);

        if ($this->exists($source, $direction)) {
            throw RatingNotAllowed::alreadyRated($direction);
        }

        return $parties;
    }

    public function exists(Model $source, RatingDirection $direction): bool
    {
        return Rating::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('direction', $direction)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function directionsAlreadyRated(Model $source): array
    {
        return Rating::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->pluck('direction')
            ->map(static fn (RatingDirection $direction): string => $direction->value)
            ->all();
    }

    /**
     * The "it has not finished" refusal, worded for what the source is.
     */
    private function notCompleted(Model $source): RatingNotAllowed
    {
        return $source instanceof Order
            ? RatingNotAllowed::orderNotCompleted($source)
            : RatingNotAllowed::sourceNotCompleted();
    }
}
