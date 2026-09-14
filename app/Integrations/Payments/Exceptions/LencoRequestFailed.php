<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Exceptions;

use RuntimeException;

/**
 * Lenco refused a call, or could not be reached at all.
 *
 * Thrown only from the paths where carrying on would be worse than stopping:
 * starting a collection, moving money out, registering a recipient. Reads
 * never throw — an unanswered status check means "ask again later", which the
 * pollers do.
 */
class LencoRequestFailed extends RuntimeException
{
    public static function collection(string $reference, string $message, int $status): self
    {
        return new self(sprintf(
            'Lenco refused collection [%s]: %s (HTTP %d).',
            $reference,
            $message !== '' ? $message : 'no reason given',
            $status,
        ));
    }

    public static function transfer(string $reference, string $message, int $status): self
    {
        return new self(sprintf(
            'Lenco refused transfer [%s]: %s (HTTP %d).',
            $reference,
            $message !== '' ? $message : 'no reason given',
            $status,
        ));
    }

    public static function recipient(string $accountNumber, string $message, int $status): self
    {
        return new self(sprintf(
            'Lenco would not register a recipient for account ending %s: %s (HTTP %d).',
            substr($accountNumber, -4),
            $message !== '' ? $message : 'no reason given',
            $status,
        ));
    }

    /**
     * The gateway is down or unreachable.
     *
     * Distinct from a refusal on purpose: a refused write did not happen, an
     * unreachable one may have. Callers that move money treat this as
     * "unknown" and leave the line for a human rather than reverting it.
     */
    public static function unreachable(string $path, int $status): self
    {
        return new self(sprintf('Lenco is unreachable at [%s] (HTTP %d).', $path, $status));
    }
}
