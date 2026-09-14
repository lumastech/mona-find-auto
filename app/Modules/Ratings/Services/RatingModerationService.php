<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Models\User;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Enums\ReportReason;
use App\Modules\Ratings\Enums\ReportStatus;
use App\Modules\Ratings\Events\RatingModerated;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Models\RatingReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Reports, and what staff do about them.
 *
 * The design decision worth stating is what a report does NOT do. A seller
 * who dislikes a review can press Report, and on its own that changes
 * nothing: the review stays up, its stars keep counting, and the report joins
 * a queue for a person to read. The two exceptions are abuse and leaked
 * personal details, where the waiting is itself the harm — those come off the
 * page immediately and go to the front of the queue.
 *
 * Hiding and restoring both require a reason and both write an audit row,
 * because this is staff deciding what the public sees about a business. The
 * event they fire is what takes the stars out of the seller's trust score and
 * puts them back.
 */
class RatingModerationService
{
    /**
     * Somebody objects to a review.
     *
     * One report per person per review: pressing the button twice is not
     * twice the objection, and the second press should read as "we have
     * this" rather than as an error.
     */
    public function report(Rating $rating, User $reporter, ReportReason $reason, ?string $details = null): RatingReport
    {
        $existing = $rating->reports()->where('reported_by', $reporter->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        $report = DB::transaction(function () use ($rating, $reporter, $reason, $details): RatingReport {
            $report = RatingReport::query()->create([
                'rating_id' => $rating->getKey(),
                'reported_by' => $reporter->getKey(),
                'reason' => $reason,
                'details' => $details,
                'status' => ReportStatus::Open,
            ]);

            $rating->increment('reports_count');
            $rating->refresh();

            if ($this->shouldHoldBack($rating, $reason)) {
                $this->moveTo($rating, RatingStatus::PendingReview, null, 'Reported: '.$reason->label());
            }

            return $report;
        });

        audit(
            $reporter,
            'rating.reported',
            $rating,
            null,
            ['reason' => $reason->value, 'reports_count' => $rating->reports_count],
            $details,
        );

        return $report;
    }

    /**
     * Take a review off the page.
     *
     * The reason is not optional. A seller whose review disappeared is owed
     * an explanation, and a moderator who has to write one hides fewer
     * reviews they merely disagree with.
     */
    public function hide(Rating $rating, User $actor, string $reason): Rating
    {
        $rating = $this->moveTo($rating, RatingStatus::Hidden, $actor, $reason);

        $this->closeOpenReports($rating, $actor, ReportStatus::Upheld, $reason);

        return $rating;
    }

    /**
     * Put a review back, or release one from the queue.
     */
    public function restore(Rating $rating, User $actor, string $reason): Rating
    {
        $rating = $this->moveTo($rating, RatingStatus::Published, $actor, $reason);

        $this->closeOpenReports($rating, $actor, ReportStatus::Dismissed, $reason);

        return $rating;
    }

    /**
     * The moderation queue: flagged by the screen, or objected to by a person.
     *
     * @return LengthAwarePaginator<int, Rating>
     */
    public function queue(?RatingStatus $status = null, int $perPage = 20): LengthAwarePaginator
    {
        return Rating::query()
            /*
             * `moderator` is read by RatingResource to show who last decided
             * a review — easy to miss because most rows in the queue have
             * not been decided yet and the relation is null for them.
             */
            ->with(['rater', 'ratee', 'submitter', 'moderator', 'reports.reporter', 'media'])
            ->when(
                $status !== null,
                fn (Builder $query): Builder => $query->where('status', $status),
                fn (Builder $query): Builder => $query->needingReview(),
            )
            ->oldest('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Whether a fresh report should take the review off the page now.
     */
    private function shouldHoldBack(Rating $rating, ReportReason $reason): bool
    {
        if (! $rating->status->isVisible()) {
            return false;
        }

        if ($reason->hidesImmediately()) {
            return true;
        }

        $threshold = (int) settings('ratings.moderation.reports_before_review', 3);

        return $threshold > 0 && $rating->reports_count >= $threshold;
    }

    /**
     * The one writer of `ratings.status`.
     *
     * Everything that has to happen together happens here: the column, the
     * moderation columns beside it, the audit row and the event the trust
     * score listens to.
     */
    private function moveTo(Rating $rating, RatingStatus $to, ?User $actor, string $reason): Rating
    {
        $from = $rating->status;

        if ($from === $to) {
            return $rating;
        }

        $rating->forceFill([
            'status' => $to,
            'moderation_reason' => $reason,
            'moderated_by' => $actor?->getKey(),
            'moderated_at' => now(),
            'published_at' => $to->isVisible() ? ($rating->published_at ?? now()) : $rating->published_at,
        ])->save();

        audit(
            $actor,
            'rating.'.$to->value,
            $rating,
            ['status' => $from->value],
            ['status' => $to->value],
            $reason,
        );

        RatingModerated::dispatch($rating->refresh(), $from, $actor);

        return $rating;
    }

    private function closeOpenReports(Rating $rating, User $actor, ReportStatus $outcome, string $note): void
    {
        $rating->reports()->open()->update([
            'status' => $outcome,
            'resolution_note' => $note,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ]);
    }
}
