<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Exceptions;

use App\Modules\Mechanics\Enums\MechanicStatus;
use RuntimeException;

/**
 * Something tried to move a mechanic's application somewhere it cannot go.
 */
class InvalidMechanicTransition extends RuntimeException
{
    public static function between(MechanicStatus $from, MechanicStatus $to): self
    {
        return new self(sprintf(
            'A mechanic profile cannot go from %s to %s.',
            $from->label(),
            $to->label(),
        ));
    }

    /**
     * Sent before there is anything to review. A reviewer needs a
     * qualification to check and at least one speciality, or the profile
     * cannot be found by anybody looking for the work it does.
     */
    public static function incomplete(): self
    {
        return new self('Add your qualification, a contact number and at least one speciality before sending your profile for approval.');
    }

    /**
     * Edited after it was sent. A reviewer has to be reading the same
     * application the mechanic submitted.
     */
    public static function notEditable(MechanicStatus $status): self
    {
        return new self(sprintf(
            'Your profile is %s and cannot be edited until MonaFind has finished with it.',
            mb_strtolower($status->label()),
        ));
    }
}
