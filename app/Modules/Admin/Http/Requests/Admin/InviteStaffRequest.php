<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

use App\Support\Roles\Role;
use Illuminate\Validation\Rule;

/**
 * Inviting somebody onto the staff console.
 *
 * Only the three console roles may be invited. Seller and mechanic roles are
 * earned through their own verification workflows, and handing one out from
 * here would put an unverified business on the storefront wearing a badge.
 */
class InviteStaffRequest extends ReasonedRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function actionRules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'role' => ['required', Rule::in(Role::staffConsole())],
        ];
    }

    public function role(): Role
    {
        return Role::from((string) $this->validated('role'));
    }
}
