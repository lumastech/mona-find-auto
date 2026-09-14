<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Support\Roles\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff console's view of the platform's accounts.
 */
class UserController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Search and filter accounts.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(AccountStatus::cases(), 'value'))],
        ]);

        $users = User::query()
            ->with('roles:id,name')
            ->search($filters['search'] ?? null)
            ->when(
                $filters['role'] ?? null,
                fn ($query, string $role) => $query->role($role),
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->withStatus(AccountStatus::from($status)),
            )
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => $this->summarise($user));

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => $filters,
            'statuses' => $this->statusOptions(),
            'roles' => $this->roleOptions(),
        ]);
    }

    /**
     * One account, with the trail of what staff have done to it.
     */
    public function show(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        return Inertia::render('admin/users/Show', [
            'user' => [
                ...$this->summarise($user),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
                'status_reason' => $user->status_reason,
                'status_changed_at' => $user->status_changed_at?->toIso8601String(),
                'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
                'two_factor_required' => $user->requiresTwoFactor(),
                'address' => $user->street === null ? null : [
                    'street' => $user->street,
                    'plot_number' => $user->plot_number,
                    'city' => $user->city?->name,
                    'province' => $user->province?->name,
                ],
                'addresses' => $user->addresses()->with(['province', 'city'])->get()
                    ->map(fn ($address): array => [
                        'id' => $address->id,
                        'label' => $address->label,
                        'recipient_name' => $address->recipient_name,
                        'single_line' => $address->singleLine(),
                        'is_default' => $address->is_default,
                    ])->all(),
                'social_accounts' => $user->socialAccounts()->get(['provider', 'email'])->all(),
            ],
            'activity' => $this->activityFor($user),
            'can' => [
                'moderate' => $this->currentUser($request)->can('moderate', $user),
                'assignRoles' => $this->currentUser($request)->can('assignRoles', $user),
            ],
        ]);
    }

    /**
     * Everything the audit trail holds about this account, whether staff
     * acted on it or it acted for itself.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityFor(User $user): array
    {
        return AuditLog::query()
            ->where(function ($query) use ($user): void {
                $query->where(fn ($q) => $q->where('subject_type', $user->getMorphClass())->where('subject_id', $user->getKey()))
                    ->orWhere(fn ($q) => $q->where('actor_type', $user->getMorphClass())->where('actor_id', $user->getKey()));
            })
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_label,
                'reason' => $log->reason,
                'before' => $log->before,
                'after' => $log->after,
                'created_at' => $log->created_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phoneNumber()?->national() ?? $user->phone,
            'status' => $user->status->value,
            'status_label' => $user->status->label(),
            'roles' => $user->getRoleNames()->values()->all(),
            'created_at' => $user->created_at?->toIso8601String(),
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            static fn (AccountStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            AccountStatus::cases(),
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return array_map(
            static fn (Role $role): array => ['value' => $role->value, 'label' => $role->label()],
            Role::cases(),
        );
    }
}
