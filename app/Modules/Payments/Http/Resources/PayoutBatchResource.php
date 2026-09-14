<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Models\PayoutLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout run for the Finance console.
 *
 * Lines are opt-in via `withLines()` rather than `whenLoaded`, because the
 * index lists thirty batches and serialising every line of each would be a
 * few thousand rows nobody asked for.
 *
 * @mixin PayoutBatch
 */
class PayoutBatchResource extends JsonResource
{
    private bool $includeLines = false;

    public function withLines(): self
    {
        $this->includeLines = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'lineCount' => $this->line_count,
            'totalNgwee' => $this->total_ngwee->ngwee,
            'paidNgwee' => $this->paid_ngwee->ngwee,
            'failedNgwee' => $this->failed_ngwee->ngwee,

            /* Dual control is the headline fact about a batch, so both names show. */
            'preparedBy' => $this->preparedBy?->name,
            'approvedBy' => $this->approvedBy?->name,
            'approvalNote' => $this->approval_note,
            'cancellationReason' => $this->cancellation_reason,

            'scheduledFor' => $this->scheduled_for?->toDateString(),
            'approvedAt' => $this->approved_at?->toDateTimeString(),
            'completedAt' => $this->completed_at?->toDateTimeString(),
            'url' => route('admin.payouts.show', $this->resource),

            'lines' => $this->includeLines
                ? $this->lines->map(static fn (PayoutLine $line): array => [
                    'id' => $line->getKey(),
                    'reference' => $line->reference,
                    'seller' => $line->seller->business_name,
                    'sellerId' => $line->seller_id,
                    'status' => $line->status->value,
                    'statusLabel' => $line->status->label(),
                    'needsAttention' => $line->status->needsAttention(),
                    'amountNgwee' => $line->amount_ngwee->ngwee,
                    'feeNgwee' => $line->fee_ngwee?->ngwee,
                    'method' => $line->method?->value,
                    'destination' => $line->account?->last_four,
                    'blockReason' => $line->block_reason,
                    'failureReason' => $line->failure_reason,
                    'sentAt' => $line->sent_at?->toDateTimeString(),
                    'settledAt' => $line->settled_at?->toDateTimeString(),
                ])->values()
                : null,
        ];
    }
}
