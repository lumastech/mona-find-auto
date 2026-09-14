<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Requests\Storefront;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Modules\Shopping\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding an option to the cart.
 *
 * The quantity ceiling here is the platform's, not the shelf's. Whether the
 * seller actually has six is a question about the world at this instant, and
 * CartService clamps to it — refusing the form instead would make a buyer
 * retype a number to be told a different one.
 */
class CartItemRequest extends FormRequest
{
    use ResolvesAuthenticatedUser;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.CartService::MAX_LINE_QUANTITY],
        ];
    }

    public function quantity(): int
    {
        return (int) ($this->validated('quantity') ?? 1);
    }
}
