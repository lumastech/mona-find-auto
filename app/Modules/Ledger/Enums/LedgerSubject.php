<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Model;

/**
 * What an account's balance is broken down by.
 *
 * "Escrow held" is meaningless as a single figure — the question is always
 * how much is held for THIS order, because that is what may be released or
 * refunded. Likewise a payable is owed to one seller. The subject dimension
 * is what makes those questions answerable from the lines themselves rather
 * than from a summary table somebody has to keep in step.
 */
enum LedgerSubject: string
{
    /** A platform-wide account: cash, revenue, expenses. */
    case None = 'none';

    /** Broken down per order — escrow. */
    case Order = 'order';

    /** Broken down per seller — payables and reserves. */
    case Seller = 'seller';

    public function isRequired(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Platform',
            self::Order => 'Per order',
            self::Seller => 'Per seller',
        };
    }

    /**
     * The model a subject of this kind is.
     *
     * The subject is decided by the ACCOUNT rather than chosen alongside it —
     * escrow is always held for an order, a payable is always owed to a
     * seller — so anything taking a subject from a form only needs to ask
     * which one, never which kind.
     *
     * @return class-string<Model>|null
     */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::None => null,
            self::Order => Order::class,
            self::Seller => Seller::class,
        };
    }

    /**
     * Find the subject an id refers to, or null if there is no such thing.
     */
    public function resolve(int|string|null $id): ?Model
    {
        $class = $this->modelClass();

        if ($class === null || $id === null) {
            return null;
        }

        return $class::query()->find($id);
    }
}
