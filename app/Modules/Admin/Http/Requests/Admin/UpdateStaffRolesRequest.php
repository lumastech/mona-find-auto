<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

use App\Support\Roles\Role;
use Illuminate\Validation\Rule;

/**
 * Setting exactly which console roles somebody holds.
 *
 * An empty list is legal and means "take them off the console entirely",
 * which is a different thing from deactivating the account — somebody
 * changing jobs inside MonaFind rather than leaving.
 */
class UpdateStaffRolesRequest extends ReasonedRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function actionRules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::in(Role::staffConsole())],
        ];
    }

    /**
     * @return array<int, Role>
     */
    public function roles(): array
    {
        /** @var array<int, string> $roles */
        $roles = $this->validated('roles') ?? [];

        return array_map(static fn (string $role): Role => Role::from($role), $roles);
    }
}
