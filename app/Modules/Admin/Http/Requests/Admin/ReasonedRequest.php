<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The base every destructive or financial console action extends.
 *
 * The brief asks that all of them carry a reason. Putting it in one place
 * means a new action gets the rule by inheriting rather than by the author
 * remembering, and the audit viewer's reason column is never empty for the
 * actions that matter.
 *
 * Five characters minimum. It will not stop "asdfg", but it does stop the
 * single full stop somebody types to get past a required field, and a
 * moderator who has to write a sentence takes fewer actions they merely
 * disagree with.
 */
abstract class ReasonedRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->actionRules(),
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    /**
     * Whatever else this particular action needs.
     *
     * @return array<string, mixed>
     */
    protected function actionRules(): array
    {
        return [];
    }
}
