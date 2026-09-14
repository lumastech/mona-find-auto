<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The report a seller reads before applying a bulk upload.
 *
 * Invalid rows come first and in full, because they are the reason this
 * screen exists. Valid rows are summarised rather than listed — a seller does
 * not read four hundred lines of "this was fine", they read the six that were
 * not.
 *
 * @mixin StockImportBatch
 */
class StockImportBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'variant' => $this->status->badgeVariant(),
                'applicable' => $this->status->isApplicable(),
                'finished' => $this->status->isFinished(),
            ],
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'invalid_rows' => $this->invalid_rows,
            'applied_rows' => $this->applied_rows,
            'failure_reason' => $this->failure_reason,
            /* Every rejected row, with its line number and every complaint about it. */
            'problems' => $this->invalidRows(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
