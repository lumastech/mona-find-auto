<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One saved delivery address, shaped the same for Inertia and the JSON API.
 *
 * @mixin UserAddress
 */
class UserAddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'province_id' => $this->province_id,
            'province' => $this->whenLoaded('province', fn (): string => $this->province->name),
            'city_id' => $this->city_id,
            'city' => $this->whenLoaded('city', fn (): string => $this->city->name),
            'street' => $this->street,
            'plot_number' => $this->plot_number,
            'directions' => $this->directions,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'formatted_address' => $this->formatted_address,
            'is_default' => $this->is_default,
            'single_line' => $this->singleLine(),
        ];
    }
}
