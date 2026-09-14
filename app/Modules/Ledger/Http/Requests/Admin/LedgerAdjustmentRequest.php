<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Requests\Admin;

use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Support\Money\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A drafted correction.
 *
 * Two lines minimum, because one line is not a double entry, and the reason
 * is mandatory: an adjustment without a stated reason is indistinguishable
 * from a mistake, and being append-only, it cannot be quietly taken back.
 *
 * The accounts a line may name are restricted to those the enum marks
 * adjustable — cash is not among them. Money does not enter or leave Lenco
 * because somebody typed a journal entry, and an adjustment claiming it did
 * puts the ledger permanently out of step with the bank.
 *
 * Balance is checked by the service rather than here: Posting is the one
 * place that decides what balanced means, and a validation rule restating it
 * is a second implementation waiting to disagree.
 */
class LedgerAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $adjustable = array_values(array_map(
            static fn (LedgerAccountCode $code): string => $code->value,
            array_filter(LedgerAccountCode::cases(), static fn (LedgerAccountCode $code): bool => $code->isManuallyAdjustable()),
        ));

        return [
            'description' => ['required', 'string', 'max:200'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'lines' => ['required', 'array', 'min:2', 'max:20'],
            'lines.*.account' => ['required', 'string', Rule::in($adjustable)],
            'lines.*.direction' => ['required', Rule::enum(EntryDirection::class)],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.subject_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.memo' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => __('An adjustment needs at least two lines — one on each side.'),
            'reason.required' => __('Say why. An administrator will read this before approving it.'),
            'lines.*.account.in' => __('That account cannot be adjusted by hand.'),
        ];
    }

    /**
     * The lines in the shape the service takes, with kwacha read as exact
     * ngwee rather than through a float.
     *
     * @return array<int, array{account: string, direction: string, amount_ngwee: int, subject_id: int|null, memo: string|null}>
     */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->validated('lines');

        return array_map(static fn (array $line): array => [
            'account' => (string) $line['account'],
            'direction' => (string) $line['direction'],
            'amount_ngwee' => Money::ofKwacha((string) $line['amount'])->ngwee,
            'subject_id' => isset($line['subject_id']) ? (int) $line['subject_id'] : null,
            'memo' => isset($line['memo']) ? (string) $line['memo'] : null,
        ], $lines);
    }
}
