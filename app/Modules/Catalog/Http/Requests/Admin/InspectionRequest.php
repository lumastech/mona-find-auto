<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Admin;

use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Setting a listing's Inspected/Uninspected badge.
 *
 * The reason is required because the badge is MonaFind's own claim about a
 * part: the audit row has to say who inspected it and on what basis, or the
 * badge means nothing when it is later questioned.
 */
class InspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && $this->user()?->can('inspect', $product) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'inspection_status' => ['required', Rule::enum(InspectionStatus::class)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Record what was inspected. The badge is a claim MonaFind has to be able to stand behind.',
        ];
    }

    public function inspectionStatus(): InspectionStatus
    {
        return InspectionStatus::from($this->string('inspection_status')->toString());
    }
}
