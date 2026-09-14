<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Http\Requests\Storefront\ReportRatingRequest;
use App\Modules\Ratings\Http\Requests\Storefront\SubmitRatingRequest;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingModerationService;
use App\Modules\Ratings\Services\RatingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The buyer's side: leaving a review, and objecting to one.
 *
 * The route is hung off the order rather than off a generic "source",
 * because the order is what the buyer is looking at and what the URL should
 * say. Endorsements will hang off their own route in the Mechanics module —
 * the service behind both is the same one.
 *
 * Nothing here decides whether the buyer may rate. RatingService asks
 * RatingEligibility, which is the same code that decided whether to show the
 * card, so the form and the guard behind it cannot disagree.
 */
class RatingController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly RatingService $ratings,
        private readonly RatingModerationService $moderation,
    ) {}

    public function store(SubmitRatingRequest $request, Order $order): RedirectResponse
    {
        Gate::authorize('view', $order);

        try {
            $this->ratings->submit(
                $order,
                $request->direction(),
                $this->currentUser($request),
                $request->stars(),
                $request->body(),
                $request->photos(),
            );
        } catch (RatingNotAllowed $exception) {
            return back()->withErrors(['rating' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Thanks — your review has been saved.'),
        ]);
    }

    /**
     * "There is something wrong with this review."
     *
     * Always answers as though it worked, including for the second press: a
     * person who reports the same review twice should be told it is with us,
     * not shown an error.
     */
    public function report(ReportRatingRequest $request, Rating $rating): RedirectResponse
    {
        Gate::authorize('report', $rating);

        $this->moderation->report(
            $rating,
            $this->currentUser($request),
            $request->reason(),
            $request->details(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Thanks. A moderator will look at this review.'),
        ]);
    }
}
