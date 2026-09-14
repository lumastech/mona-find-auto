<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Requests\Storefront;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Modules\Shopping\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A buyer asking a shop for a price.
 *
 * The message is optional. "How much for 40?" is a complete request, and
 * making somebody write a paragraph before they can ask is how a feature goes
 * unused.
 */
class QuotationRequestRequest extends FormRequest
{
    use ResolvesAuthenticatedUser;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Quotation::MAX_QUANTITY],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
