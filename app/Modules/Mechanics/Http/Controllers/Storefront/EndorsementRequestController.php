<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Mechanics\Exceptions\EndorsementNotAllowed;
use App\Modules\Mechanics\Http\Requests\Storefront\EndorsementRequestRequest;
use App\Modules\Mechanics\Services\EndorsementService;
use App\Modules\Mechanics\Services\MechanicProfileService;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A mechanic asking a shop to vouch for them.
 *
 * Only approved mechanics may ask — see MechanicProfilePolicy — because
 * asking a shop to vouch for a qualification MonaFind has not checked puts
 * the shop in the position of doing the checking, which is not what the badge
 * means.
 */
class EndorsementRequestController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly EndorsementService $endorsements,
        private readonly MechanicProfileService $profiles,
    ) {}

    public function store(EndorsementRequestRequest $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $profile = $this->profiles->draftFor($user);

        abort_unless($profile->exists, 404);
        Gate::forUser($user)->authorize('requestEndorsement', $profile);

        $seller = Seller::query()->findOrFail($request->sellerId());

        try {
            $this->endorsements->request($profile, $seller, $user, $request->message());
        } catch (EndorsementNotAllowed $exception) {
            return back()->withErrors(['seller_id' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Your request has gone to :business.', ['business' => $seller->business_name]),
        ]);
    }
}
