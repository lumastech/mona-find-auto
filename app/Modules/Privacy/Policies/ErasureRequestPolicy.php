<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Policies;

use App\Models\User;
use App\Modules\Privacy\Models\ErasureRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and act on an erasure request.
 *
 * The asymmetry here is deliberate and is the module's central rule: staff
 * may look, and may hold a request while something settles, but only the
 * account holder may call one off. An erasure that staff could cancel is not
 * a right, it is a request.
 */
class ErasureRequestPolicy
{
    public function view(User $user, ErasureRequest $request): bool
    {
        return $request->user_id === $user->getKey() || Gate::forUser($user)->allows('staff');
    }

    /**
     * Only the person being erased. Not staff, at any role.
     */
    public function cancel(User $user, ErasureRequest $request): bool
    {
        return $request->user_id === $user->getKey() && $request->status->isCancellable();
    }

    /**
     * Staff hold a request when an order or a dispute has to settle first.
     */
    public function block(User $user, ErasureRequest $request): bool
    {
        return Gate::forUser($user)->allows('moderate') && $request->status->isOpen();
    }

    public function release(User $user, ErasureRequest $request): bool
    {
        return Gate::forUser($user)->allows('moderate') && $request->status->isOpen();
    }
}
