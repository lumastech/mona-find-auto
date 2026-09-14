<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The two ends of a rating, worked out from its source.
 *
 * Who the rater is is not always who is holding the phone: a shop's rating of
 * a buyer belongs to the business, and the member of staff who typed it is
 * recorded beside it rather than in place of it. Keeping those two apart here
 * means the rest of the module never has to think about it again.
 */
final readonly class RatingParties
{
    public function __construct(
        public Model $rater,
        public Model $ratee,
        public User $submittedBy,
        public string $rateeName,
    ) {}
}
