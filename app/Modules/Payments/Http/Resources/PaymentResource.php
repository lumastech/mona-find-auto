<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment, as the API shows it.
 *
 * The `raw` gateway payload is deliberately absent. It carries card
 * fingerprints, wallet names and whatever else Lenco decides to include, none
 * of which the mobile app needs and all of which would then be cached on a
 * handset.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'attempt' => $this->attempt,
            'status' => $this->status->value,
            'channel' => $this->channel?->value,
            'channelLabel' => $this->channel?->label(),
            'amountNgwee' => $this->amount_ngwee->ngwee,
            'amount' => $this->amount_ngwee->toDecimalString(),
            'currency' => 'ZMW',
            'feeNgwee' => $this->fee_ngwee?->ngwee,
            'failureReason' => $this->failure_reason,
            'observedAt' => $this->observed_at->toIso8601String(),
            'orderGroup' => $this->whenLoaded('orderGroup', fn (): ?array => $this->orderGroup === null ? null : [
                'publicId' => $this->orderGroup->public_id,
                'status' => $this->orderGroup->status->value,
            ]),
        ];
    }
}
