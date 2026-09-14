<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Exceptions\ListingNotReadyForReview;
use App\Modules\Catalog\Exceptions\SellerMayNotList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ListingModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Moving a listing in and out of the moderation queue, from the seller's end.
 *
 * The readiness check is turned into field-level validation errors, so a
 * seller who is missing a photo is told beside the photo box rather than in a
 * sentence at the top of a form they have already scrolled past.
 */
class ListingSubmissionController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly ListingModerationService $moderation) {}

    /**
     * @throws ValidationException when the listing or the shop is not ready
     */
    public function submit(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('submit', $product);

        try {
            $this->moderation->submit($product, $this->currentUser($request));
        } catch (ListingNotReadyForReview $exception) {
            throw ValidationException::withMessages($exception->reasons);
        } catch (SellerMayNotList $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Sent for review. We usually come back within a day.'),
        ]);

        return back();
    }

    /**
     * Pull a listing back out of the queue so it can be edited again.
     */
    public function withdraw(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('submit', $product);

        $this->moderation->withdraw($product, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing withdrawn. It is a draft again.')]);

        return back();
    }

    /**
     * Take a live listing down without retiring it — usually because the
     * seller has run out of stock.
     */
    public function unpublish(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('submit', $product);

        $this->moderation->unpublish($product, $this->currentUser($request), 'Taken down by the seller.');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing hidden from buyers.')]);

        return back();
    }

    /**
     * Put it back. No second review: it was approved once and nothing about
     * it changed while it was down.
     */
    public function republish(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('submit', $product);

        $this->moderation->republish($product, $this->currentUser($request), 'Put back by the seller.');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing is live again.')]);

        return back();
    }
}
