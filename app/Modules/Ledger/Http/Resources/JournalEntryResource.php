<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry as the ledger browser shows it.
 *
 * Lines are included whenever they are loaded, because an entry without its
 * lines is a total with no explanation — and the whole reason staff open this
 * screen is to find out what a movement was made of.
 *
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'recipe' => $this->recipe->value,
            'recipe_label' => $this->recipe->label(),
            'description' => $this->description,
            'total_ngwee' => $this->total_ngwee->ngwee,
            'actor_label' => $this->actor_label,
            'reference_label' => $this->referenceLabel(),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'idempotency_key' => $this->idempotency_key,
            'context' => $this->context,
            'posted_at' => $this->posted_at->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(
                static fn (JournalLine $line): array => [
                    'account' => $line->account->code->value,
                    'account_label' => $line->account->code->label(),
                    'direction' => $line->direction->value,
                    'amount_ngwee' => $line->amount_ngwee->ngwee,
                    'subject_type' => $line->subject_type === null ? null : class_basename($line->subject_type),
                    'subject_id' => $line->subject_id,
                    'subject_label' => $line->subject?->getRouteKey(),
                    'memo' => $line->memo,
                ],
            )->all()),
        ];
    }
}
