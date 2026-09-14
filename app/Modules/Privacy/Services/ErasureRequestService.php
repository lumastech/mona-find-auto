<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Privacy\Enums\ErasureStatus;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Notifications\ErasureScheduledNotification;
use RuntimeException;

/**
 * The lifecycle around an erasure: asking, holding, calling it off.
 *
 * ## The grace period
 *
 * A request does not erase anything on the spot. It schedules erasure for
 * `privacy.erasure_grace_days` from now and the person is told exactly when.
 * Two reasons: somebody who clicks Delete in a temper can undo it, and
 * somebody whose session was stolen gets an email while the account is still
 * recoverable.
 *
 * ## Why staff can block but not refuse
 *
 * `block()` holds a request when something has to settle first — an order in
 * flight, an open dispute, a payout not yet sent. It does not cancel it. The
 * request stays open with a reason attached, and erasure resumes once the
 * hold is lifted. Only the account holder can cancel their own request, which
 * is the point: an erasure staff could quietly refuse is not a right.
 */
class ErasureRequestService
{
    /**
     * Open a request, or return the one already open.
     *
     * Idempotent on purpose: a person pressing Delete twice should not end up
     * with two requests, two emails and two erasure dates.
     */
    public function request(User $user, ?string $reason = null): ErasureRequest
    {
        $existing = $this->openRequestFor($user);

        if ($existing !== null) {
            return $existing;
        }

        $request = ErasureRequest::create([
            'user_id' => $user->getKey(),
            'status' => ErasureStatus::Pending,
            'reason' => $reason,
            'requested_at' => now(),
            'erase_after' => now()->addDays($this->graceDays()),
        ]);

        audit($user, 'privacy.erasure.requested', $request, null, [
            'erase_after' => $request->erase_after->toIso8601String(),
        ], $reason);

        $user->notify(new ErasureScheduledNotification($request));

        return $request;
    }

    /**
     * The account holder changes their mind.
     */
    public function cancel(ErasureRequest $request, ?User $actor = null): ErasureRequest
    {
        if (! $request->status->isCancellable()) {
            throw new RuntimeException('Only a scheduled erasure request can be cancelled.');
        }

        $request->forceFill([
            'status' => ErasureStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        audit($actor ?? $request->user, 'privacy.erasure.cancelled', $request, null, null);

        return $request;
    }

    /**
     * Staff hold the request until something settles.
     */
    public function block(ErasureRequest $request, string $reason, User $actor): ErasureRequest
    {
        $request->forceFill([
            'status' => ErasureStatus::Blocked,
            'blocked_reason' => $reason,
            'blocked_by' => $actor->getKey(),
        ])->save();

        audit($actor, 'privacy.erasure.blocked', $request, null, ['reason' => $reason], $reason);

        return $request;
    }

    /**
     * Staff lift the hold. The grace period is not restarted — it has
     * already run; the person has been waiting longer than they were
     * promised, not less.
     */
    public function release(ErasureRequest $request, User $actor): ErasureRequest
    {
        $request->forceFill([
            'status' => ErasureStatus::Pending,
            'blocked_reason' => null,
            'blocked_by' => null,
        ])->save();

        audit($actor, 'privacy.erasure.released', $request, null, null);

        return $request;
    }

    public function openRequestFor(User $user): ?ErasureRequest
    {
        return ErasureRequest::query()
            ->where('user_id', $user->getKey())
            ->open()
            ->latest('requested_at')
            ->first();
    }

    private function graceDays(): int
    {
        return max(0, (int) settings('privacy.erasure_grace_days', 14));
    }
}
