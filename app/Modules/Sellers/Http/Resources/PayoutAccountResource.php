<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Resources;

use App\Modules\Sellers\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout destination as its owner sees it.
 *
 * Never the full account number, even to the seller who typed it: the value
 * of encrypting the column is lost if every page render decrypts it back onto
 * a screen somebody can photograph.
 *
 * @mixin PayoutAccount
 */
class PayoutAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'label' => $this->label,
            'display_name' => $this->displayName(),
            'masked_number' => $this->maskedNumber(),
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'network' => $this->network?->value,
            'network_label' => $this->network?->label(),
            /* The bank's name for the account, not the seller's — a mismatch is worth seeing. */
            'resolved_name' => $this->resolved_name,
            'resolved' => $this->isResolved(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'is_default' => $this->is_default,
        ];
    }
}
