<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Services;

use App\Models\User;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Ratings\Contracts\RatingSourceResolver;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingSource;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Support\RatingParties;
use App\Modules\Ratings\Support\RatingPrompt;
use Illuminate\Database\Eloquent\Model;

/**
 * An endorsement, as something to rate.
 *
 * This is the seam the Ratings module left open. It is registered from this
 * module's provider, and nothing in Ratings names an endorsement — which is
 * why adding mechanic reviews needed no change there at all.
 *
 * An endorsement entitles exactly one rating: the shop that vouched for a
 * mechanic reviewing that mechanic, in public. That is the relationship the
 * row records, and it is the only one it can speak for. The two other
 * directions that hang off an endorsement in RatingDirection — a buyer
 * reviewing a mechanic, and a mechanic rating that buyer — are about a job
 * done for a buyer, and nothing on the platform records one yet. When a
 * mechanic engagement exists, it ships its own resolver and registers it
 * alongside this one; prompts() returning only what an endorsement actually
 * entitles is what keeps that honest in the meantime.
 *
 * "Complete" means the endorsement is in force. A shop may review a mechanic
 * it currently stands behind; a request nobody has answered, or one that has
 * been withdrawn, is not a relationship to review. A rating written while it
 * stood survives the withdrawal, because revoking moves the row rather than
 * deleting it.
 */
class EndorsementRatingSource implements RatingSourceResolver
{
    /**
     * @return class-string<Model>
     */
    public function handles(): string
    {
        return MechanicEndorsement::class;
    }

    public function source(): RatingSource
    {
        return RatingSource::Endorsement;
    }

    public function isComplete(Model $source): bool
    {
        return $source instanceof MechanicEndorsement && $source->isActive();
    }

    /**
     * @return array<int, RatingPrompt>
     */
    public function prompts(Model $source, User $user): array
    {
        if (! $source instanceof MechanicEndorsement || ! $this->isComplete($source)) {
            return [];
        }

        if (! $this->actsForSeller($source, $user)) {
            return [];
        }

        return [
            new RatingPrompt(
                direction: RatingDirection::SellerToMechanic,
                source: $source,
                ratee: $source->profile,
                rateeName: $source->profile->display_name,
                heading: 'How is '.$source->profile->display_name.' to work with?',
            ),
        ];
    }

    public function parties(Model $source, RatingDirection $direction, User $user): RatingParties
    {
        if (! $source instanceof MechanicEndorsement || $direction !== RatingDirection::SellerToMechanic) {
            throw RatingNotAllowed::directionUnavailable($direction);
        }

        if (! $this->actsForSeller($source, $user)) {
            throw RatingNotAllowed::notYours();
        }

        /*
         * The rating belongs to the business, not to the member of staff who
         * typed it — the same rule as a shop's rating of a buyer. A mechanic
         * who deals with a shop sees one reputation, not one per counter hand.
         */
        return new RatingParties(
            rater: $source->seller,
            ratee: $source->profile,
            submittedBy: $user,
            rateeName: $source->profile->display_name,
        );
    }

    /**
     * Whether this person speaks for the shop that gave the endorsement.
     */
    private function actsForSeller(MechanicEndorsement $endorsement, User $user): bool
    {
        $seller = $user->seller;

        return $seller !== null && $endorsement->isAddressedTo($seller);
    }
}
