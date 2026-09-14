<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding a row to a curated list.
 *
 * `parent_id` is required for a scoped list and forbidden for a flat one; the
 * controller knows which this is and passes the rule in, because the console
 * renders every list through the same form.
 */
class StoreReferenceRowRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'parent_id' => ['nullable', 'integer'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function parentId(): ?int
    {
        $value = $this->validated('parent_id');

        return $value === null ? null : (int) $value;
    }
}
