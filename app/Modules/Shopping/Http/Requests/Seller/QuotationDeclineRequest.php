<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Requests\Seller;

use App\Concerns\ResolvesAuthenticatedUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A shop turning a request down.
 *
 * The reason is optional but asked for: "we do not carry this for the D-Max"
 * saves the buyer sending the same request to the same shop next week.
 */
class QuotationDeclineRequest extends FormRequest
{
    use ResolvesAuthenticatedUser;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
