<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\LocationDirectory;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Modules\Sellers\Http\Requests\Seller\SellerProfileRequest;
use App\Modules\Sellers\Http\Resources\SellerProfileResource;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A seller editing their own shop.
 *
 * Two things on this screen are read-only and stay that way: the payment mode
 * and the monetisation policy. Both decide how much of a buyer's money reaches
 * the seller and when, so both are MonaFind's to set — but a seller who cannot
 * see which terms they are on cannot check their own payouts, so they are
 * shown.
 */
class ProfileController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly LocationDirectory $locations) {}

    public function edit(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        return Inertia::render('seller/Profile', [
            'seller' => [
                ...SellerProfileResource::make($seller)->resolve($request),
                'registration_number' => $seller->registration_number,
                'province_id' => $seller->province_id,
                'city_id' => $seller->city_id,
                'phone' => $seller->phone,
                'email' => $seller->email,
                'contact_person' => $seller->contact_person,
            ],
            'commercialTerms' => $this->commercialTerms($seller),
            'provinces' => $this->locations->provincesWithCities(),
            'asksBayCount' => $seller->type->hasWorkshopCapacity(),
        ]);
    }

    public function update(SellerProfileRequest $request): RedirectResponse
    {
        $seller = $this->currentSeller($request);
        $before = $seller->only(['business_name', 'registration_number', 'phone', 'email', 'contact_person']);

        $seller->update($request->validated());

        audit(
            $this->currentUser($request),
            'seller.profile.updated',
            $seller,
            $before,
            $seller->only(['business_name', 'registration_number', 'phone', 'email', 'contact_person']),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop details saved.')]);

        return to_route('seller.profile.edit');
    }

    /**
     * The terms MonaFind set, shown but never editable here.
     *
     * @return array<string, mixed>
     */
    private function commercialTerms(Seller $seller): array
    {
        return [
            'payment_mode' => $seller->payment_mode->value,
            'payment_mode_label' => $seller->payment_mode->label(),
            'payment_mode_description' => $seller->payment_mode->description(),
            'carries_reserve' => $seller->payment_mode->carriesReserve(),
            /* Wired up by the Finance module; null means the platform default applies. */
            'monetisation_policy_id' => $seller->monetisation_policy_id,
            'editable' => false,
        ];
    }
}
