<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Requests\Storefront;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Modules\Shopping\Models\SellerEnquiry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Contact seller."
 *
 * The listing is optional because a buyer may also write from a shop's own
 * page, where there is no particular part in question.
 */
class SellerEnquiryRequest extends FormRequest
{
    use ResolvesAuthenticatedUser;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:'.SellerEnquiry::MAX_LENGTH],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ];
    }
}
