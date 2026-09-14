<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing a new version of a CMS page.
 *
 * The change note is required and becomes the version's own description in
 * the history — "corrected the refund window" beside version 4 is what makes
 * the history worth keeping. It also lands in the audit row, which is why
 * this does not extend ReasonedRequest: one sentence, recorded in two places,
 * not two boxes asking the same question.
 */
class PublishContentPageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'body' => ['required', 'string', 'min:20', 'max:60000'],
            'change_note' => ['required', 'string', 'min:5', 'max:255'],
            'publish' => ['nullable', 'boolean'],
        ];
    }

    public function shouldGoLive(): bool
    {
        return (bool) ($this->validated('publish') ?? true);
    }
}
