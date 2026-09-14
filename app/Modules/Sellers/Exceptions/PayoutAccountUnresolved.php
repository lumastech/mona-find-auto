<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Exceptions;

use RuntimeException;

/**
 * The gateway did not recognise the account a seller gave us.
 *
 * This blocks the save. An unresolved account is not a warning to be clicked
 * past: a payout addressed to a number nobody owns either bounces weeks later
 * or lands in a stranger's wallet.
 */
class PayoutAccountUnresolved extends RuntimeException
{
    public static function bankAccount(string $bankName): self
    {
        return new self(sprintf(
            'We could not find that account number at %s. Check the number and the bank, then try again.',
            $bankName,
        ));
    }

    public static function mobileMoney(string $network): self
    {
        return new self(sprintf(
            'We could not find that %s mobile-money number. Check the number and the network, then try again.',
            $network,
        ));
    }

    public static function unknownBank(): self
    {
        return new self('That bank is not one we can pay out to. Choose one from the list.');
    }
}
