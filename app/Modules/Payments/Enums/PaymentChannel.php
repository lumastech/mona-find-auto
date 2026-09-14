<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * How the buyer's money actually moved.
 *
 * Matters after the fact more than during: a refund has to go back the way it
 * came, and card is the one channel MonaFind cannot reverse by API.
 */
enum PaymentChannel: string
{
    case Card = 'card';
    case MobileMoney = 'mobile_money';
    case BankAccount = 'bank_account';
    case Unknown = 'unknown';

    /**
     * Lenco's `type` field to ours. Null on a collection Lenco has not yet
     * decided the type of, which is normal while the widget is open.
     */
    public static function fromLenco(?string $type): self
    {
        return match ($type) {
            'card' => self::Card,
            'mobile-money' => self::MobileMoney,
            'bank-account' => self::BankAccount,
            default => self::Unknown,
        };
    }

    /**
     * Whether a refund on this channel can be sent as a Lenco transfer.
     *
     * False for card, which is why card refunds queue for Finance instead.
     */
    public function isRefundableByTransfer(): bool
    {
        return $this === self::MobileMoney || $this === self::BankAccount;
    }

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::MobileMoney => 'Mobile money',
            self::BankAccount => 'Bank transfer',
            self::Unknown => 'Unknown',
        };
    }
}
