<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Exceptions;

use App\Modules\Mechanics\Enums\EndorsementStatus;
use RuntimeException;

/**
 * Somebody tried to do something to an endorsement they may not do.
 *
 * Authorisation refusals and workflow refusals are both here because both
 * come back to the person as a sentence on the page rather than an error
 * screen — a shop with a stale tab open answering a request the mechanic
 * already withdrew is a thing to explain, not a crash.
 */
class EndorsementNotAllowed extends RuntimeException
{
    /**
     * Answered by a shop the request was not addressed to.
     */
    public static function notAddressee(): self
    {
        return new self('Only the shop this request was sent to can answer it.');
    }

    public static function alreadyDecided(EndorsementStatus $status): self
    {
        return new self(sprintf('This request has already been answered: %s.', mb_strtolower($status->label())));
    }

    public static function notEndorsed(): self
    {
        return new self('This endorsement is not currently in force, so there is nothing to withdraw.');
    }

    public static function alreadyPending(): self
    {
        return new self('You have already asked this shop, and they have not answered yet.');
    }

    /**
     * Asked of a shop that is not on the platform as a public business.
     */
    public static function sellerNotListed(): self
    {
        return new self('You can only ask a shop that is listed on MonaFind.');
    }

    public static function between(EndorsementStatus $from, EndorsementStatus $to): self
    {
        return new self(sprintf(
            'An endorsement cannot go from %s to %s.',
            mb_strtolower($from->label()),
            mb_strtolower($to->label()),
        ));
    }
}
