<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Requests\Seller;

use App\Concerns\ResolvesAuthenticatedUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A shop's answer to a quote request.
 *
 * `valid_until` is required and must be today or later. A price with no end
 * date is not something a parts shop can honour — costs move — and one that
 * expired before it was sent is a slip of the calendar, caught here rather
 * than by the nightly sweep after the buyer has already seen it.
 *
 * The price arrives as a kwacha string and is read by Money, which rejects a
 * float outright. Nothing in this path ever holds 1099.99 as a number.
 */
class QuotationResponseRequest extends FormRequest
{
    use ResolvesAuthenticatedUser;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'unit_price' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'delivery_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_price.regex' => 'Enter a price in kwacha, such as 1250 or 1250.50.',
            'valid_until.after_or_equal' => 'A quote has to be valid until today at the earliest.',
        ];
    }
}
