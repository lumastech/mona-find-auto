<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Events\AccountStatusChanged;
use App\Modules\Identity\Events\AccountWarned;
use App\Modules\Identity\Notifications\AccountStatusChangedNotification;
use App\Modules\Identity\Notifications\AccountWarnedNotification;
use Illuminate\Support\Facades\DB;

/**
 * The four things staff can do to an account: warn, suspend, reinstate, close.
 *
 * Every one of them demands a reason, writes an audit row and tells the
 * account holder. Suspension and closure additionally tear down live sessions
 * and API tokens so the block takes effect immediately rather than whenever
 * the person next logs in.
 */
class AccountModerationService
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    /**
     * Record a formal warning. The account keeps its status.
     */
    public function warn(User $user, string $reason, ?User $actor = null): User
    {
        audit($actor, 'user.warned', $user, null, null, $reason);

        AccountWarned::dispatch($user, $reason, $actor);

        $user->notify(new AccountWarnedNotification($reason));

        return $user;
    }

    /**
     * Block an account and end every session it has open.
     */
    public function suspend(User $user, string $reason, ?User $actor = null): User
    {
        return $this->transitionTo($user, AccountStatus::Suspended, $reason, $actor, 'user.suspended');
    }

    /**
     * Lift a suspension. The reason explains why the block was lifted, and is
     * kept for the same reason the suspension's was.
     */
    public function reinstate(User $user, string $reason, ?User $actor = null): User
    {
        return $this->transitionTo($user, AccountStatus::Active, $reason, $actor, 'user.reinstated');
    }

    /**
     * Close an account for good. Data is kept — orders, ledger entries and
     * the audit trail all still have to reconcile — but the person can no
     * longer sign in.
     */
    public function close(User $user, string $reason, ?User $actor = null): User
    {
        return $this->transitionTo($user, AccountStatus::Closed, $reason, $actor, 'user.closed');
    }

    /**
     * Move an account out of Pending once its phone number is verified.
     *
     * A suspended or closed account never quietly becomes active this way —
     * only staff can undo those.
     */
    public function activateAfterVerification(User $user): User
    {
        if ($user->status !== AccountStatus::Pending) {
            return $user;
        }

        return $this->transitionTo($user, AccountStatus::Active, 'Phone number verified.', null, 'user.activated');
    }

    /**
     * Apply a status change: persist it, audit it, announce it, and cut off
     * access when the new status says access is over.
     */
    private function transitionTo(
        User $user,
        AccountStatus $status,
        string $reason,
        ?User $actor,
        string $action,
    ): User {
        $from = $user->status;

        DB::transaction(function () use ($user, $status, $reason): void {
            $user->forceFill([
                'status' => $status,
                'status_reason' => $reason,
                'status_changed_at' => now(),
            ])->save();
        });

        audit(
            $actor,
            $action,
            $user,
            ['status' => $from->value, 'reason' => $user->getOriginal('status_reason')],
            ['status' => $status->value, 'reason' => $reason],
            $reason,
        );

        if ($status->forcesLogout()) {
            $this->sessions->revokeEverything($user);
        }

        AccountStatusChanged::dispatch($user, $from, $status, $reason, $actor);

        /* The account holder is told what changed and why, except when nothing did. */
        if ($from !== $status) {
            $user->notify(new AccountStatusChangedNotification($status, $reason));
        }

        return $user;
    }
}
