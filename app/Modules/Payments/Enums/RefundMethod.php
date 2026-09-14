<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * How a refund reaches the buyer.
 *
 * `CardManual` is the awkward one and the reason this enum exists. Lenco
 * handles card reversals outside the API, so a card refund cannot be executed
 * by code — it is raised, posted to the ledger, and queued for a Finance
 * human. Modelling it as "a transfer that keeps failing" would be a lie the
 * buyer pays for in waiting.
 *
 * `Goodwill` is different again: MonaFind absorbs it and the seller keeps
 * their money, so it posts to refunds_expense rather than clawing anything
 * back.
 */
enum RefundMethod: string
{
    case MobileMoney = 'mobile_money';
    case Bank = 'bank';
    case CardManual = 'card_manual';
    case Goodwill = 'goodwill';

    /** Whether Payments can execute this itself, as a Lenco transfer. */
    public function isAutomatic(): bool
    {
        return $this === self::MobileMoney || $this === self::Bank;
    }

    /** Whether this refund waits on a Finance human. */
    public function needsManualProcessing(): bool
    {
        return $this === self::CardManual;
    }

    /**
     * The method to use for a refund of a payment taken on a given channel.
     *
     * Card payments become manual refunds; everything else goes back the way
     * it came.
     */
    public static function forChannel(PaymentChannel $channel): self
    {
        return match ($channel) {
            PaymentChannel::MobileMoney => self::MobileMoney,
            PaymentChannel::BankAccount => self::Bank,
            default => self::CardManual,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MobileMoney => 'Mobile money',
            self::Bank => 'Bank transfer',
            self::CardManual => 'Card (manual)',
            self::Goodwill => 'Goodwill',
        };
    }
}
