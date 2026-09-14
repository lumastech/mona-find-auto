<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * How the platform came to believe a payment was in a given state.
 *
 * Kept because a status we read back from the gateway ourselves and a status
 * a webhook asserted are not equally trustworthy, and when the two disagree
 * the first question is always which was which.
 */
enum PaymentSource: string
{
    case Initiate = 'initiate';
    case Verify = 'verify';
    case Webhook = 'webhook';
    case Poll = 'poll';

    public function label(): string
    {
        return match ($this) {
            self::Initiate => 'Payment started',
            self::Verify => 'Verified server-side',
            self::Webhook => 'Gateway webhook',
            self::Poll => 'Status poll',
        };
    }
}
