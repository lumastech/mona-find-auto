<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Seller;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ListingFieldRules;
use App\Support\Money\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or editing a listing in the seller portal.
 *
 * The rules come from ListingFieldRules so the API validates against exactly
 * the same ones. What this adds is the money conversion: prices arrive as the
 * decimal strings a seller typed and leave as Money, so nothing between the
 * form and the database has ever seen a float.
 */
class ProductRequest extends FormRequest
{
    use ListingFieldRules;

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            ? $this->user()?->can('update', $product) === true
            : $this->user()?->can('create', Product::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->listingRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->listingMessages();
    }

    /**
     * The listing's own columns, ready for ProductService.
     *
     * @return array<string, mixed>
     */
    public function listingAttributes(): array
    {
        return [
            ...$this->safe()->except(['variants', 'price', 'quantity', 'photos', 'video']),
            'delivery_available' => $this->boolean('delivery_available'),
            /* Only used when the seller never opened the options panel. */
            'price' => $this->filled('price') ? Money::ofKwacha($this->string('price')->toString()) : null,
            'quantity' => (int) $this->input('quantity', 0),
        ];
    }

    /**
     * The variants, with each price turned into Money.
     *
     * An empty array means "single price", and ProductService builds the one
     * variant from the top-level price instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public function variantRows(): array
    {
        /** @var array<int, array<string, mixed>> $variants */
        $variants = $this->input('variants', []);

        return array_map(static fn (array $variant): array => [
            'id' => isset($variant['id']) ? (int) $variant['id'] : null,
            'name' => $variant['name'] ?? null,
            'sku' => $variant['sku'] ?? null,
            'price' => Money::ofKwacha((string) $variant['price']),
            'quantity' => (int) ($variant['quantity'] ?? 0),
            'is_default' => (bool) ($variant['is_default'] ?? false),
        ], $variants);
    }
}
