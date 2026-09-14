<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Exceptions\ReplyNotAllowed;
use App\Modules\Ratings\Http\Requests\Seller\ReplyToRatingRequest;
use App\Modules\Ratings\Http\Requests\Storefront\SubmitRatingRequest;
use App\Modules\Ratings\Http\Resources\RatingResource;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Ratings\Services\TrustScoreService;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The seller's reviews: what buyers said, and the one answer they get.
 *
 * The page also carries the shop's trust score, because a seller looking at a
 * bad review deserves to see whether it moved anything — and because the same
 * number decides where their listings sit in search, which is the part they
 * actually care about.
 *
 * Ratings the shop has written about buyers are listed separately. They are
 * not public and this is one of the two places they are readable.
 */
class RatingController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly RatingService $ratings,
        private readonly TrustScoreService $trust,
    ) {}

    public function index(Request $request): Response
    {
        $seller = $this->sellerFor($request);

        $received = Rating::query()
            ->about($seller)
            ->public()
            ->with(['rater', 'media'])
            ->latest('created_at')
            ->paginate(10, pageName: 'received')
            ->withQueryString()
            ->through(fn (Rating $rating): array => RatingResource::make($rating)->resolve($request));

        $given = Rating::query()
            ->by($seller)
            ->with(['ratee'])
            ->latest('created_at')
            ->paginate(10, pageName: 'given')
            ->withQueryString()
            ->through(fn (Rating $rating): array => [
                ...RatingResource::make($rating)->resolve($request),
                /* The buyer's full name: this list is private to the shop. */
                'buyer' => $rating->ratee->getAttribute('name'),
            ]);

        $score = $this->trust->for($seller);

        return Inertia::render('seller/ratings/Index', [
            'received' => $received,
            'given' => $given,
            'summary' => $this->ratings->aggregateFor($seller)->toArray(),
            'trust' => [
                'score' => $score->trust_score,
                'band' => $score->trust_band->value,
                'band_label' => $score->trust_band->label(),
                'band_variant' => $score->trust_band->badgeVariant(),
                'dispute_rate_percent' => $score->dispute_rate_percent,
                'computed_at' => $score->computed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * The shop rates a buyer on a completed order.
     *
     * The rating belongs to the business rather than to the member of staff
     * who typed it — see OrderRatingSource — so a buyer who deals with a shop
     * twice sees one reputation rather than one per counter hand.
     */
    public function store(SubmitRatingRequest $request, Order $order): RedirectResponse
    {
        Gate::authorize('fulfil', $order);

        try {
            $this->ratings->submit(
                $order,
                RatingDirection::SellerToBuyer,
                $this->currentUser($request),
                $request->stars(),
                $request->body(),
            );
        } catch (RatingNotAllowed $exception) {
            return back()->withErrors(['rating' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Rating saved. Only other sellers and MonaFind staff can see it.'),
        ]);
    }

    public function reply(ReplyToRatingRequest $request, Rating $rating): RedirectResponse
    {
        Gate::authorize('reply', $rating);

        try {
            $this->ratings->reply(
                $rating,
                $this->currentUser($request),
                $this->sellerFor($request),
                $request->reply(),
            );
        } catch (ReplyNotAllowed $exception) {
            return back()->withErrors(['reply' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Your reply is on the review.'),
        ]);
    }

    private function sellerFor(Request $request): Seller
    {
        $seller = $this->currentUser($request)->seller;

        abort_unless($seller instanceof Seller, HttpResponse::HTTP_FORBIDDEN);

        return $seller;
    }
}
