<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\Seller\FulfilmentSettingsRequest;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the shop offers buyers at checkout.
 *
 * A short form, but the one that decides whether a buyer sees "Collect" or
 * "Delivery" against this shop — and a shop with delivery switched on and no
 * fee set would be quoting free delivery to the whole country.
 *
 * The fee is typed in kwacha and stored in ngwee. Money::ofKwacha parses the
 * decimal string exactly; nothing here goes near a float.
 */
class FulfilmentSettingsController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function edit(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        return Inertia::render('seller/Fulfilment', [
            'fulfilment' => [
                'offers_pickup' => $seller->offers_pickup,
                'offers_delivery' => $seller->offers_delivery,
                'delivery_fee_ngwee' => $seller->delivery_fee_ngwee->ngwee,
                'delivery_note' => $seller->delivery_note,
                'address' => $seller->singleLine(),
                'latitude' => $seller->latitude,
                'longitude' => $seller->longitude,
            ],
        ]);
    }

    public function update(FulfilmentSettingsRequest $request): RedirectResponse
    {
        $seller = $this->currentSeller($request);
        $user = $this->currentUser($request);

        $before = [
            'offers_pickup' => $seller->offers_pickup,
            'offers_delivery' => $seller->offers_delivery,
            'delivery_fee_ngwee' => $seller->delivery_fee_ngwee->ngwee,
        ];

        $fee = $request->boolean('offers_delivery')
            ? Money::ofKwacha((string) ($request->validated('delivery_fee') ?? '0'))
            : Money::zero();

        $seller->update([
            'offers_pickup' => $request->boolean('offers_pickup'),
            'offers_delivery' => $request->boolean('offers_delivery'),
            'delivery_fee_ngwee' => $fee,
            'delivery_note' => $request->validated('delivery_note'),
        ]);

        audit(
            $user,
            'seller.fulfilment_updated',
            $seller,
            $before,
            [
                'offers_pickup' => $seller->offers_pickup,
                'offers_delivery' => $seller->offers_delivery,
                'delivery_fee_ngwee' => $fee->ngwee,
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Your delivery settings are saved.'),
        ]);
    }
}
