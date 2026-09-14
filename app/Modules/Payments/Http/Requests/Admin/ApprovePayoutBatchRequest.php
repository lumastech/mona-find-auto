<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Releasing a payout batch.
 *
 * Authorisation is left to the controller's policy check so that the dual
 * control rule lives in exactly one place — PayoutBatchPolicy::approve — and
 * cannot be satisfied here by accident.
 */
class ApprovePayoutBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
