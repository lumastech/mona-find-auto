<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Http\Requests\Admin\ModerateUserRequest;
use App\Modules\Identity\Services\AccountModerationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Warn, suspend, reinstate, close.
 *
 * Each action needs a reason, each writes an audit row, and the two that stop
 * an account trading end its sessions immediately — all of which is the
 * moderation service's job, not this controller's.
 */
class UserModerationController extends Controller
{
    public function __construct(private readonly AccountModerationService $accounts) {}

    public function warn(ModerateUserRequest $request, User $user): RedirectResponse
    {
        $this->accounts->warn($user, $request->reason(), $request->user());

        return $this->done('Warning recorded.', $user);
    }

    public function suspend(ModerateUserRequest $request, User $user): RedirectResponse
    {
        $this->accounts->suspend($user, $request->reason(), $request->user());

        return $this->done('Account suspended and signed out everywhere.', $user);
    }

    public function reinstate(ModerateUserRequest $request, User $user): RedirectResponse
    {
        $this->accounts->reinstate($user, $request->reason(), $request->user());

        return $this->done('Account reinstated.', $user);
    }

    public function close(ModerateUserRequest $request, User $user): RedirectResponse
    {
        $this->accounts->close($user, $request->reason(), $request->user());

        return $this->done('Account closed.', $user);
    }

    private function done(string $message, User $user): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return to_route('admin.users.show', $user);
    }
}
