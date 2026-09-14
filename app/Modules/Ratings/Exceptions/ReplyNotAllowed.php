<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Exceptions;

use RuntimeException;

/**
 * The right of reply, and its limits.
 *
 * One reply per review, from the party the review is about, on reviews the
 * public can actually read. The one-reply rule is the important one: a reply
 * is an answer, and a seller who can post ten of them turns every bad review
 * into a thread they get the last word in.
 */
class ReplyNotAllowed extends RuntimeException
{
    public static function alreadyReplied(): self
    {
        return new self('You have already replied to this review. Contact support if the reply needs changing.');
    }

    public static function notYours(): self
    {
        return new self('Only the seller or mechanic a review is about may reply to it.');
    }

    public static function notPublic(): self
    {
        return new self('This rating is not public, so there is nothing to reply to.');
    }

    public static function notPublished(): self
    {
        return new self('This review is not on the site at the moment.');
    }
}
