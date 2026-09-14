<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Modules\Sellers\Enums\PayoutMethod;
use App\Modules\Sellers\Exceptions\PayoutAccountUnresolved;
use App\Modules\Sellers\Http\Requests\Seller\PayoutAccountRequest;
use App\Modules\Sellers\Http\Resources\PayoutAccountResource;
use App\Modules\Sellers\Models\PayoutAccount;
use App\Modules\Sellers\Services\PayoutAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Where a seller's money is sent.
 *
 * An account the gateway does not recognise is refused rather than saved with
 * a warning: a payout addressed to a number nobody owns either bounces weeks
 * later or lands in a stranger's wallet, and both cost far more to unpick
 * than making the seller retype it now.
 */
class PayoutAccountController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly PayoutAccountService $accounts) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        return Inertia::render('seller/PayoutAccounts', [
            'accounts' => PayoutAccountResource::collection($seller->payoutAccounts()->get())->resolve(),
            'methods' => PayoutMethod::options(),
            'banks' => array_map(static fn ($bank): array => $bank->toArray(), $this->accounts->banks()),
        ]);
    }

    public function store(PayoutAccountRequest $request): RedirectResponse
    {
        $seller = $this->currentSeller($request);

        try {
            $account = $this->accounts->add($seller, $request->validated(), $this->currentUser($request));
        } catch (PayoutAccountUnresolved $exception) {
            /*
             * The gateway's refusal is a validation failure as far as the
             * seller is concerned: it belongs on the form beside the number
             * they typed, not on an error page.
             */
            throw ValidationException::withMessages([
                $request->input('method') === PayoutMethod::Bank->value ? 'account_number' : 'mobile_number' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account confirmed with the bank as :name.', ['name' => $account->resolved_name]),
        ]);

        return to_route('seller.payout-accounts.index');
    }

    public function makeDefault(Request $request, PayoutAccount $payoutAccount): RedirectResponse
    {
        $this->assertOwned($request, $payoutAccount);

        $this->accounts->makeDefault($payoutAccount, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payouts will go to this account.')]);

        return to_route('seller.payout-accounts.index');
    }

    public function destroy(Request $request, PayoutAccount $payoutAccount): RedirectResponse
    {
        $this->assertOwned($request, $payoutAccount);

        $this->accounts->remove($payoutAccount, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account removed.')]);

        return to_route('seller.payout-accounts.index');
    }

    /**
     * Route-model binding finds any account by id, so ownership is checked
     * here rather than assumed from the URL.
     */
    private function assertOwned(Request $request, PayoutAccount $account): void
    {
        abort_unless(
            $account->seller_id === $this->currentSeller($request)->getKey(),
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
