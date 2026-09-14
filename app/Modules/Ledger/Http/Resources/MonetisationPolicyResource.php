<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Models\MonetisationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MonetisationPolicy
 */
class MonetisationPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'commission_type' => $this->commission_type->value,
            'commission_percent' => $this->commission_percent,
            'commission_flat_ngwee' => $this->commission_flat_ngwee->ngwee,
            'commission_description' => $this->commissionDescription(),
            'addon_fee_ngwee' => $this->addon_fee_ngwee->ngwee,
            'referral_fee_percent' => $this->referral_fee_percent,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'seller_count' => $this->whenCounted('sellers'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
