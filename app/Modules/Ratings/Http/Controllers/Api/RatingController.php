<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Http\Requests\Storefront\ReportRatingRequest;
use App\Modules\Ratings\Http\Requests\Storefront\SubmitRatingRequest;
use App\Modules\Ratings\Http\Resources\RatingResource;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingEligibility;
use App\Modules\Ratings\Services\RatingModerationService;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Ratings for the mobile app.
 *
 * The list is public and unauthenticated, and it is public in the strict
 * sense: `Rating::scopePublic()` is applied before anything else touches the
 * query, so the three public directions are the only ones this endpoint can
 * return no matter what is passed to it. There is deliberately no filter for
 * direction — an endpoint that accepts `?direction=seller_to_buyer` is one
 * refactor away from honouring it.
 *
 * Submitting goes through the same service the web form uses, so the rule
 * about rating only completed orders, once per direction, is enforced in one
 * place for both front doors.
 */
class RatingController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly RatingService $ratings,
        private readonly RatingEligibility $eligibility,
        private readonly RatingModerationService $moderation,
    ) {}

    /**
     * Object to a review.
     *
     * The counterpart of the web's report button. Without it an app could
     * show a buyer a review calling them a thief and offer them no way to
     * say so — which is the situation the moderation queue exists to handle.
     *
     * Answers 202: the report is accepted, and what happens next is a
     * moderator's decision rather than something this request settles.
     */
    public function report(ReportRatingRequest $request, Rating $rating): JsonResponse
    {
        Gate::authorize('report', $rating);

        $this->moderation->report(
            $rating,
            $this->currentUser($request),
            $request->reason(),
            $request->details(),
        );

        return ApiResponse::ok(
            ['reported' => true, 'message' => 'Thanks. A moderator will look at this review.'],
            status: HttpResponse::HTTP_ACCEPTED,
        );
    }

    /**
     * Reviews about one seller, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'seller' => ['required', 'string'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $seller = Seller::query()
            ->publiclyVisible()
            ->where('slug', $filters['seller'])
            ->first();

        if ($seller === null) {
            return ApiResponse::error('not_found', __('No such seller.'), status: HttpResponse::HTTP_NOT_FOUND);
        }

        $ratings = Rating::query()
            ->about($seller)
            ->public()
            ->with(['rater', 'media'])
            ->latest('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->through(fn (Rating $rating): array => RatingResource::make($rating)->resolve($request));

        return ApiResponse::paginated($ratings, [
            'summary' => $this->ratings->aggregateFor($seller)->toArray(),
        ]);
    }

    /**
     * Leave a rating on a completed order.
     */
    public function store(SubmitRatingRequest $request, Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        try {
            $rating = $this->ratings->submit(
                $order,
                $request->direction(),
                $this->currentUser($request),
                $request->stars(),
                $request->body(),
                $request->photos(),
            );
        } catch (RatingNotAllowed $exception) {
            return ApiResponse::error(
                'rating_not_allowed',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        return ApiResponse::created(RatingResource::make($rating->load(['rater', 'media']))->resolve($request));
    }

    /**
     * What this person may still rate about an order they were part of.
     *
     * The app asks for this rather than working it out, so that the card it
     * shows and the guard on the submit endpoint are the same decision.
     */
    public function prompts(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        $prompts = $this->eligibility->promptsFor($order, $this->currentUser($request));

        return ApiResponse::ok(array_map(
            static fn ($prompt): array => $prompt->toArray(),
            $prompts,
        ));
    }
}
