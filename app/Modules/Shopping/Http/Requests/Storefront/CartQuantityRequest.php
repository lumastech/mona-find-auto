<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Requests\Storefront;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Modules\Shopping\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing how many of a line the buyer wants.
 *
 * Zero is allowed and means "remove", because that is what the stepper does
 * when it reaches the bottom — turning it into a validation error would make
 * the control lie about its own range.
 */
class CartQuantityRequest extends FormRequest
{
    use ResolvesAuthenticatedUser;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:0', 'max:'.CartService::MAX_LINE_QUANTITY],
        ];
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
