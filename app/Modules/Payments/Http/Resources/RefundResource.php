<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A refund for the Finance queue.
 *
 * The destination shows as a masked tail rather than a full number: the queue
 * is a list on a screen in an office, and a Finance operator confirming a
 * refund needs to recognise the account, not to be able to read it out.
 *
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'method' => $this->method->value,
            'methodLabel' => $this->method->label(),
            'reason' => $this->reason_code?->value,
            'reasonLabel' => $this->reason_code?->label(),
            'amountNgwee' => $this->amount_ngwee->ngwee,
            'needsManualProcessing' => $this->method->needsManualProcessing(),
            'destination' => $this->maskedDestination(),
            'failureReason' => $this->failure_reason,
            'notes' => $this->notes,
            'order' => [
                'number' => $this->order->number,
                'seller' => $this->order->seller->business_name,
                'url' => route('admin.orders.show', $this->order->number),
            ],
            'buyer' => $this->buyer->name,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'completedAt' => $this->completed_at?->toDateTimeString(),
        ];
    }

    /**
     * The last four digits of wherever this is going, or nothing.
     */
    private function maskedDestination(): ?string
    {
        $number = $this->destination_phone ?? $this->destination_account;

        return $number === null ? null : '•••• '.substr($number, -4);
    }
}
