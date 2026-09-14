<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming or retiring one reference row from the consolidated console.
 *
 * Only the two fields every list shares. Anything type-specific — a model's
 * body type, a town's coordinates, where a category sits in the tree — is
 * edited on the owning module's own screen, which the console links to.
 */
class UpdateReferenceRowRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function isActive(): ?bool
    {
        $value = $this->validated('is_active');

        return $value === null ? null : (bool) $value;
    }
}
