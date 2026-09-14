<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Models\User;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Events\RatingSubmitted;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Exceptions\ReplyNotAllowed;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Support\RatingAggregate;
use App\Support\Content\ContentScreen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leaving a rating, and answering one.
 *
 * Everything a review has to be true about happens before the row is written:
 * the source has finished, this person was part of it, and the one rating
 * that direction allows has not been used. Only then is the text screened and
 * the row inserted, inside a transaction, so a review can never exist in a
 * state where it counts towards a seller's score without having been checked.
 *
 * Photographs go on after the commit, for the same reason the disputes
 * service does it: media library writes to disk, and a failed upload must not
 * discard a review the buyer has already been told was saved. A review with
 * no photos is still a review.
 *
 * The reply is deliberately a pair of columns on the rating rather than a
 * table. One reply per review is the rule, and a column that is either null
 * or filled cannot be broken by a race the way a `count() < 1` check can.
 */
class RatingService
{
    /** Stars are out of five; anything else is a bug, not a user error. */
    private const MIN_STARS = 1;

    private const MAX_STARS = 5;

    public function __construct(
        private readonly RatingEligibility $eligibility,
        private readonly ContentScreen $screen,
        private readonly RatingSourceRegistry $sources,
    ) {}

    /**
     * Leave a rating.
     *
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws RatingNotAllowed
     */
    public function submit(
        Model $source,
        RatingDirection $direction,
        User $user,
        int $stars,
        ?string $body = null,
        array $photos = [],
    ): Rating {
        $stars = $this->guardStars($stars);

        $parties = $this->eligibility->assert($source, $direction, $user);
        $resolver = $this->sources->for($source);
        $screened = $this->screen->screen($body);
        $status = RatingStatus::forScreen($screened);

        $rating = DB::transaction(fn (): Rating => Rating::query()->create([
            'direction' => $direction,
            'source' => $resolver->source(),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'rater_type' => $parties->rater->getMorphClass(),
            'rater_id' => $parties->rater->getKey(),
            'ratee_type' => $parties->ratee->getMorphClass(),
            'ratee_id' => $parties->ratee->getKey(),
            'submitted_by' => $parties->submittedBy->getKey(),
            'stars' => $stars,
            'body' => $screened->text,
            'status' => $status,
            /*
             * The verified-purchase label is derived from the source, never
             * from the author: it says money changed hands here, and only a
             * completed order can say that.
             */
            'verified_purchase' => $resolver->source()->isVerifiedPurchase(),
            'screen_flags' => $screened->flagValues(),
            'published_at' => $status->isVisible() ? now() : null,
        ]));

        foreach ($photos as $photo) {
            $rating->addMedia($photo)->toMediaCollection(Rating::PHOTOS_COLLECTION);
        }

        audit(
            $user,
            'rating.submitted',
            $rating,
            null,
            [
                'direction' => $direction->value,
                'stars' => $stars,
                'status' => $status->value,
                'screen_flags' => $screened->flagValues(),
            ],
        );

        RatingSubmitted::dispatch($rating->refresh());

        return $rating;
    }

    /**
     * The party a review is about answers it — once.
     *
     * @throws ReplyNotAllowed
     */
    public function reply(Rating $rating, User $user, Model $as, string $body): Rating
    {
        if (! $rating->direction->allowsReply()) {
            throw ReplyNotAllowed::notPublic();
        }

        if (! $rating->isRatee($as)) {
            throw ReplyNotAllowed::notYours();
        }

        if ($rating->hasReply()) {
            throw ReplyNotAllowed::alreadyReplied();
        }

        if (! $rating->status->isVisible()) {
            throw ReplyNotAllowed::notPublished();
        }

        /*
         * A reply is published text like any other, so it goes through the
         * same screen — a seller answering "call me on 0977..." would
         * otherwise get the contact details onto the page that the review
         * itself was stopped from carrying. A reply that trips the profanity
         * list is refused outright rather than queued: unlike a review, there
         * is nothing lost by asking its author to write it again.
         */
        $screened = $this->screen->screen($body);

        if ($screened->requiresReview() || blank($screened->text)) {
            throw ValidationException::withMessages([
                'reply' => __('Please reword your reply and try again.'),
            ]);
        }

        $rating->forceFill([
            'reply_body' => $screened->text,
            'replied_by' => $user->getKey(),
            'replied_at' => now(),
        ])->save();

        audit($user, 'rating.replied', $rating, null, ['reply' => $screened->text]);

        return $rating->refresh();
    }

    /**
     * The star breakdown for one party, counting everything staff have not
     * taken down.
     */
    public function aggregateFor(Model $party): RatingAggregate
    {
        $counts = Rating::query()
            ->about($party)
            ->counted()
            ->whereIn('direction', RatingDirection::publicValues())
            ->selectRaw('stars, count(*) as total')
            ->groupBy('stars')
            ->pluck('total', 'stars')
            ->all();

        $verified = Rating::query()
            ->about($party)
            ->counted()
            ->whereIn('direction', RatingDirection::publicValues())
            ->where('verified_purchase', true)
            ->count();

        /** @var array<int, int> $counts */
        return RatingAggregate::fromCounts($counts, $verified);
    }

    private function guardStars(int $stars): int
    {
        if ($stars < self::MIN_STARS || $stars > self::MAX_STARS) {
            throw ValidationException::withMessages([
                'stars' => __('Choose between 1 and 5 stars.'),
            ]);
        }

        return $stars;
    }
}
