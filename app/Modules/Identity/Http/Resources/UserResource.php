<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as its owner sees it.
 *
 * Never use this to describe somebody else's account to a buyer — contact
 * details on the storefront follow the blur-until-logged-in rule instead.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'phone' => $this->phone,
            'phone_national' => $this->phoneNumber()?->national(),
            'phone_network' => $this->phone_network,
            'phone_verified' => $this->hasVerifiedPhone(),
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'province_id' => $this->province_id,
            'city_id' => $this->city_id,
            'street' => $this->street,
            'plot_number' => $this->plot_number,
            'roles' => $this->getRoleNames()->values()->all(),
            'two_factor_enabled' => $this->hasConfirmedTwoFactor(),
            'two_factor_required' => $this->requiresTwoFactor(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
