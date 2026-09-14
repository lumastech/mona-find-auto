<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Data;

/**
 * The lifecycle of a single collection or transfer at the gateway.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function isSettled(): bool
    {
        return $this === self::Successful;
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
