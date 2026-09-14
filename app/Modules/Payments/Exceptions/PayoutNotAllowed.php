<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Modules\Payments\Enums\PayoutBatchStatus;
use RuntimeException;

/**
 * A payout run was asked to do something it must not.
 *
 * Each of these is a guard rail rather than a bug: the UI hides most of them,
 * and the service refuses anyway, because a payout is the one place where a
 * stale browser tab could otherwise move real money twice.
 */
class PayoutNotAllowed extends RuntimeException
{
    public static function notApprovable(PayoutBatchStatus $status): self
    {
        return new self(__('A :status batch cannot be approved.', ['status' => $status->label()]));
    }

    /**
     * Dual control. The single most important rule in this module.
     */
    public static function selfApproval(): self
    {
        return new self(__('A payout batch must be approved by someone other than the person who prepared it.'));
    }

    public static function empty(): self
    {
        return new self(__('There is nothing to pay out in this batch.'));
    }

    public static function notApproved(PayoutBatchStatus $status): self
    {
        return new self(__('A :status batch cannot be executed; it must be approved first.', [
            'status' => $status->label(),
        ]));
    }

    public static function alreadyStarted(): self
    {
        return new self(__('This batch has already started paying out and can no longer be changed.'));
    }
}
