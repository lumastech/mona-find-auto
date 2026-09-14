<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Resources;

use App\Modules\Sellers\Models\SellerPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One version of a seller policy.
 *
 * The version number is public on purpose: it is what a buyer accepted at
 * checkout and what a dispute is later argued against.
 *
 * @mixin SellerPolicy
 */
class SellerPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'version' => $this->version,
            'body' => $this->body,
            'excerpt' => $this->excerpt(),
            'effective_from' => $this->effective_from->toIso8601String(),
            'in_force' => $this->isInForce(),
            'is_current' => $this->is_current,
            'shows_platform_minimum' => $this->type->showsPlatformMinimum(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
