<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Contracts;

use App\Models\User;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingSource;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Support\RatingParties;
use App\Modules\Ratings\Support\RatingPrompt;
use Illuminate\Database\Eloquent\Model;

/**
 * What one kind of rateable event knows about itself.
 *
 * Ratings has to answer three questions about anything somebody wants to rate
 * — has it finished, who was involved, and which directions it offers — and
 * the answers are completely different for a completed order and a mechanic
 * endorsement. Rather than branch on the source type inside the service, each
 * source ships a resolver and registers it.
 *
 * This is the seam the Mechanics module plugs into. Endorsements do not exist
 * yet; when they do, that module implements this once and registers it, and
 * nothing in Ratings changes.
 */
interface RatingSourceResolver
{
    /**
     * The model class this resolver speaks for.
     *
     * @return class-string<Model>
     */
    public function handles(): string;

    public function source(): RatingSource;

    /**
     * Whether the event has finished, and a rating is therefore earned.
     */
    public function isComplete(Model $source): bool;

    /**
     * The directions this person may still rate in, for this source.
     *
     * Already-used directions are filtered out by RatingEligibility, so a
     * resolver answers only "what is this person entitled to at all".
     *
     * @return array<int, RatingPrompt>
     */
    public function prompts(Model $source, User $user): array;

    /**
     * Who is rating whom, for one direction.
     *
     * @throws RatingNotAllowed when this person has no standing to rate in
     *                          this direction on this source.
     */
    public function parties(Model $source, RatingDirection $direction, User $user): RatingParties;
}
