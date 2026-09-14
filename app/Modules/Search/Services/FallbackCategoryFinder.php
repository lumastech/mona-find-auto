<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Search\Support\SearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tier 3: when nothing matched, the closest heading the buyer's words point
 * at.
 *
 * Somebody typing "hilux front brake pads 2012" and getting nothing has still
 * told us a great deal — "brake pads" is a category that certainly exists,
 * even if no listing carries all four terms. Showing them that category with
 * the notice is far better than an empty page, and far better than silently
 * loosening the query until something matches.
 *
 * The deepest matching category wins. "Brake pads" is a more useful answer
 * than "Brakes", and a more useful answer still than "Suspension & brakes".
 */
final class FallbackCategoryFinder
{
    /** Shorter than this and a token matches half the tree. */
    private const MIN_TOKEN_LENGTH = 3;

    /**
     * The category to fall back to, or null when the words point nowhere.
     */
    public function for(SearchCriteria $criteria): ?Category
    {
        /*
         * A buyer who already picked a category and still saw nothing is not
         * helped by being shown the category they picked.
         */
        if ($criteria->categoryId !== null) {
            return null;
        }

        /* Two letters matches half the tree; three is where words start. */
        $tokens = array_filter(
            $criteria->tokens()->tokens,
            static fn (string $token): bool => mb_strlen($token) >= self::MIN_TOKEN_LENGTH,
        );

        if ($tokens === []) {
            return null;
        }

        return Category::query()
            ->active()
            ->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $query->orWhere('name', 'like', '%'.$token.'%');
                }
            })
            ->orderByDesc('depth')
            ->orderBy('name')
            ->first();
    }
}
