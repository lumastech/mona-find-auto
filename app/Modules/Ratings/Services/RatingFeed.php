<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Modules\Ratings\Http\Resources\RatingResource;
use App\Modules\Ratings\Models\Rating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The review block a public page shows about a shop or a mechanic.
 *
 * One assembler for all of them, because the listing page, the seller page
 * and the API answer the same question and must not answer it differently.
 * Everything it returns has been through scopePublic(), so a private
 * buyer-directed rating cannot reach a storefront page even if somebody wires
 * this up to the wrong subject.
 *
 * Sorted newest first with photographs eager-loaded. Reviews with pictures of
 * the part are the ones buyers read, and a review list that lazy-loads media
 * is one query per review.
 */
class RatingFeed
{
    public function __construct(private readonly RatingService $ratings) {}

    /**
     * Summary plus a page of reviews, shaped for an Inertia page or the API.
     *
     * @return array{summary: array<string, mixed>, reviews: array<string, mixed>}
     */
    public function publicFor(Model $party, Request $request, int $perPage = 10): array
    {
        $reviews = Rating::query()
            ->about($party)
            ->public()
            ->with(['rater', 'media'])
            ->latest('created_at')
            ->paginate($perPage, pageName: 'reviews')
            ->withQueryString()
            ->through(fn (Rating $rating): array => RatingResource::make($rating)->resolve($request));

        return [
            'summary' => $this->ratings->aggregateFor($party)->toArray(),
            'reviews' => $reviews->toArray(),
        ];
    }
}
