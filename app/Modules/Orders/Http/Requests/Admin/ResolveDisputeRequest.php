<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Admin;

use App\Modules\Orders\Enums\DisputeResolution;
use App\Support\Money\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A moderator's decision, which is an instruction to the ledger.
 *
 * The amount is required exactly when the resolution is a partial refund, and
 * is read as kwacha because that is what a moderator types. Money::ofKwacha
 * parses the decimal string exactly rather than going anywhere near a float —
 * a refund is the last place to lose a ngwee.
 *
 * A reason is mandatory for every outcome, including releasing to the seller.
 * Both parties are told what was decided, and "your dispute was closed" with
 * no explanation is how a platform loses a buyer.
 */
class ResolveDisputeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(DisputeResolution::class)],
            'refund_amount' => [
                'required_if:resolution,'.DisputeResolution::PartialRefund->value,
                'nullable',
                'numeric',
                'min:0.01',
            ],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => __('Explain the decision. Both the buyer and the seller are shown this.'),
            'refund_amount.required_if' => __('Name the amount going back to the buyer.'),
        ];
    }

    public function resolution(): DisputeResolution
    {
        return DisputeResolution::from((string) $this->validated('resolution'));
    }

    /**
     * The refund figure, parsed exactly. Null unless a partial refund.
     */
    public function refundAmount(): ?Money
    {
        $amount = $this->validated('refund_amount');

        if (! $this->resolution()->needsAmount() || $amount === null) {
            return null;
        }

        return Money::ofKwacha((string) $amount);
    }
}
