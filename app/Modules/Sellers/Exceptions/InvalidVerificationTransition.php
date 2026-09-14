<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Exceptions;

use App\Modules\Sellers\Enums\VerificationStatus;
use RuntimeException;

/**
 * Something tried to move an application somewhere it cannot go.
 */
class InvalidVerificationTransition extends RuntimeException
{
    public static function between(VerificationStatus $from, VerificationStatus $to): self
    {
        return new self(sprintf(
            'A seller cannot go from %s to %s.',
            $from->label(),
            $to->label(),
        ));
    }

    /**
     * The one guard that is about the seller rather than the workflow: the
     * badge says MonaFind checked a registered business, so there has to be
     * a registration number to have checked.
     */
    public static function withoutRegistrationNumber(): self
    {
        return new self('Add the business registration number before granting the Verified badge.');
    }
}
