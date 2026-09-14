<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Resources;

use App\Models\User;
use App\Modules\Ledger\Models\LedgerAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerAdjustment
 */
class LedgerAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'description' => $this->description,
            'reason' => $this->reason,
            'lines' => $this->readableLines(),
            'total_ngwee' => $this->total_ngwee->ngwee,
            'author' => $this->author?->name,
            'decider' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'journal_entry_uuid' => $this->entry?->uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            /*
             * Whether THIS viewer may decide it, which is not a property of
             * the adjustment: the dual-control rule turns on who is asking.
             */
            'can_decide' => $viewer instanceof User && $viewer->can('decide', $this->resource),
        ];
    }
}
