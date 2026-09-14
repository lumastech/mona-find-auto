<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Privacy;

use App\Models\User;
use App\Modules\Privacy\Contracts\PersonalDataSource;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Models\RatingReport;

/**
 * Reviews written and reviews received.
 *
 * ## The star stays, the words go
 *
 * A review body is free text somebody wrote and is removed. The star rating
 * is not: it is already part of a seller's public average and of the trust
 * score that decides where their listings rank. Deleting it would silently
 * move a seller's rating — possibly upward, by removing a bad review — which
 * would make erasure a way to launder a reputation.
 *
 * So the row survives with `body` redacted and its author anonymised. The
 * seller's average is untouched, and nobody can tell who left it.
 *
 * ## Ratings received are not touched
 *
 * A buyer erasing their account does not get to remove what sellers said
 * about them: those are the sellers' words about their own trading
 * experience, and the buyer is identified in them only by an account that is
 * now a tombstone.
 *
 * `submitted_by` is deliberately left pointing at the erased account. It is
 * the one-per-counterparty guard that stops a second review being written
 * against the same order, and clearing it would open that door.
 */
class RatingPersonalData implements PersonalDataSource
{
    public function key(): string
    {
        return 'reviews';
    }

    /**
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array
    {
        return [
            PersonalDataSection::make(
                'Reviews you wrote',
                Rating::query()
                    ->where('submitted_by', $user->getKey())
                    ->latest('id')
                    ->get()
                    ->map(static fn (Rating $rating): array => [
                        'Stars' => $rating->stars,
                        'What you wrote' => $rating->body,
                        'Status' => $rating->status->value,
                        'Seller replied' => $rating->reply_body,
                        'Written on' => $rating->created_at?->toDateTimeString(),
                    ])->all(),
            ),

            PersonalDataSection::make(
                'Reviews about you',
                Rating::query()
                    ->where('ratee_type', $user->getMorphClass())
                    ->where('ratee_id', $user->getKey())
                    ->latest('id')
                    ->get()
                    ->map(static fn (Rating $rating): array => [
                        'Stars' => $rating->stars,
                        'What they wrote' => $rating->body,
                        'Written on' => $rating->created_at?->toDateTimeString(),
                    ])->all(),
                'What sellers and mechanics said about trading with you. These are their words and are not removed when your account is deleted.',
            ),

            PersonalDataSection::make(
                'Reviews you reported',
                RatingReport::query()
                    ->where('reported_by', $user->getKey())
                    ->latest('id')
                    ->get()
                    ->map(static fn (RatingReport $report): array => [
                        'Reason' => $report->reason,
                        'What you told us' => $report->details,
                        'Status' => $report->status,
                        'Reported on' => $report->created_at?->toDateTimeString(),
                    ])->all(),
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array
    {
        /*
         * Written reviews: words out, star left standing. See the class
         * docblock for why the two are treated differently.
         */
        $written = Rating::query()
            ->where('submitted_by', $user->getKey())
            ->whereNotNull('body')
            ->update([
                'body' => $anonymiser->text(),
                'updated_at' => now(),
            ]);

        /*
         * Replies this person wrote as a seller, on reviews about their own
         * shop. Same reasoning: free text they authored.
         */
        $replies = Rating::query()
            ->where('replied_by', $user->getKey())
            ->whereNotNull('reply_body')
            ->update([
                'reply_body' => $anonymiser->text(),
                'updated_at' => now(),
            ]);

        /*
         * Reports go entirely. A moderation complaint is not a public record
         * and has no bearing on anybody's rating once it has been decided.
         */
        $reports = RatingReport::query()->where('reported_by', $user->getKey())->delete();

        return [
            'ratings' => $written,
            'rating_replies' => $replies,
            'rating_reports' => $reports,
        ];
    }
}
