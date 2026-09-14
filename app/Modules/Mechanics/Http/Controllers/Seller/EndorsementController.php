<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Mechanics\Enums\EndorsementStatus;
use App\Modules\Mechanics\Exceptions\EndorsementNotAllowed;
use App\Modules\Mechanics\Http\Requests\Seller\EndorsementDecisionRequest;
use App\Modules\Mechanics\Http\Requests\Seller\RevokeEndorsementRequest;
use App\Modules\Mechanics\Http\Resources\MechanicEndorsementResource;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Services\EndorsementService;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop's side of endorsements: who has asked, and who it stands behind.
 *
 * Every action is gated twice on the same rule — the policy checks that this
 * shop is the one addressed, and EndorsementService checks it again before
 * writing. That is deliberate: a badge saying "Endorsed by Kabwata Motors"
 * has to mean Kabwata Motors said so, and a guard that lives only on the
 * route protects the route rather than the claim.
 *
 * The list is scoped to the current shop in the query, so a stale id in a URL
 * finds nothing rather than finding somebody else's request to refuse.
 */
class EndorsementController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly EndorsementService $endorsements) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        $base = fn () => MechanicEndorsement::query()
            ->forSeller($seller)
            ->with(['profile.province', 'profile.city', 'profile.media', 'seller']);

        $pending = $base()
            ->pending()
            ->get()
            ->map(fn (MechanicEndorsement $endorsement): array => MechanicEndorsementResource::make($endorsement)->resolve($request))
            ->all();

        $decided = $base()
            ->whereNot('status', EndorsementStatus::Requested)
            ->latest('decided_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (MechanicEndorsement $endorsement): array => MechanicEndorsementResource::make($endorsement)->resolve($request));

        return Inertia::render('seller/endorsements/Index', [
            'pending' => $pending,
            'decided' => $decided,
        ]);
    }

    public function endorse(EndorsementDecisionRequest $request, MechanicEndorsement $endorsement): RedirectResponse
    {
        return $this->decide(
            $request,
            $endorsement,
            fn () => $this->endorsements->endorse(
                $endorsement,
                $this->currentSeller($request),
                $this->currentUser($request),
                $request->note(),
            ),
            'decide',
            __('Your shop now shows as an endorsement on :name\'s profile.', [
                'name' => $endorsement->profile->display_name,
            ]),
        );
    }

    public function decline(EndorsementDecisionRequest $request, MechanicEndorsement $endorsement): RedirectResponse
    {
        return $this->decide(
            $request,
            $endorsement,
            fn () => $this->endorsements->decline(
                $endorsement,
                $this->currentSeller($request),
                $this->currentUser($request),
                $request->note(),
            ),
            'decide',
            __('Request declined. They can ask you again later.'),
        );
    }

    public function revoke(RevokeEndorsementRequest $request, MechanicEndorsement $endorsement): RedirectResponse
    {
        return $this->decide(
            $request,
            $endorsement,
            fn () => $this->endorsements->revoke(
                $endorsement,
                $this->currentSeller($request),
                $this->currentUser($request),
                $request->reason(),
            ),
            'revoke',
            __('Your endorsement has been withdrawn and the badge is off their profile.'),
        );
    }

    /**
     * Authorise, act, and turn a workflow refusal into a message on the page
     * rather than an error screen — a shop answering from a stale tab is a
     * thing to explain, not a crash.
     *
     * @param  callable(): MechanicEndorsement  $action
     */
    private function decide(
        Request $request,
        MechanicEndorsement $endorsement,
        callable $action,
        string $ability,
        string $message,
    ): RedirectResponse {
        Gate::forUser($this->currentUser($request))->authorize($ability, $endorsement);

        try {
            $action();
        } catch (EndorsementNotAllowed $exception) {
            return back()->withErrors(['endorsement' => $exception->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }
}
