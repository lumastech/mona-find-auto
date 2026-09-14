<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Seller;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A seller correcting one option's quantity from the stock screen.
 *
 * The threshold is nullable rather than defaulted, and that nullability is
 * meaningful: clearing it puts the variant back on the platform default
 * rather than pinning it at whatever the default happens to be today.
 */
class StockUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            && $this->user()?->can('update', $product) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'variant_id' => [
                'required',
                'integer',
                /* Scoped to this listing: an id from another shop must not resolve. */
                Rule::exists('product_variants', 'id')->where(
                    'product_id',
                    $product instanceof Product ? $product->getKey() : 0,
                ),
            ],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variant_id.exists' => 'That option does not belong to this listing.',
            'quantity.min' => 'A quantity cannot be negative. Enter 0 if you have sold out.',
        ];
    }
}
