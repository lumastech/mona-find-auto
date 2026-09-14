<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Http\Requests\Admin\ModerateRatingRequest;
use App\Modules\Ratings\Http\Resources\RatingResource;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review queue.
 *
 * It opens on what needs deciding — reviews the automatic screen held back
 * and reviews somebody objected to — oldest first, because a queue that opens
 * on the archive is a queue nobody works. The other statuses are a filter
 * rather than the default view.
 *
 * Both actions take a reason and both audit. Hiding a review is the platform
 * deciding what the public reads about a Zambian business, and restoring one
 * is the platform overruling somebody's objection; neither should be possible
 * without a sentence attached to a name.
 */
class RatingModerationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly RatingModerationService $moderation) {}

    public function index(Request $request): Response
    {
        Gate::authorize('moderate');

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
        ]);

        $status = RatingStatus::tryFrom($filters['status'] ?? '');

        $ratings = $this->moderation->queue($status)
            ->through(fn (Rating $rating): array => RatingResource::make($rating)->resolve($request));

        return Inertia::render('admin/ratings/Index', [
            'ratings' => $ratings,
            'filters' => ['status' => $status?->value],
            'statuses' => RatingStatus::options(),
        ]);
    }

    public function hide(ModerateRatingRequest $request, Rating $rating): RedirectResponse
    {
        Gate::authorize('moderate');

        $this->moderation->hide($rating, $this->currentUser($request), $request->reason());

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Review hidden.'),
        ]);
    }

    public function restore(ModerateRatingRequest $request, Rating $rating): RedirectResponse
    {
        Gate::authorize('moderate');

        $this->moderation->restore($rating, $this->currentUser($request), $request->reason());

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Review restored.'),
        ]);
    }
}
