<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Exceptions\LedgerException;
use App\Modules\Ledger\Services\LedgerService;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes LedgerService the only thing that can write a journal row.
 *
 * The append-only trait and the database triggers stop a row being changed
 * after the fact; nothing in them stops a row being INSERTED from anywhere.
 * That gap matters more than it sounds: an entry created straight through
 * Eloquent skips the balance check, skips the idempotency key and never
 * touches the materialised balances, and the damage is silent and permanent
 * because the row cannot afterwards be corrected.
 *
 * So the models refuse to be created unless a posting is in progress, and the
 * only thing that opens one is LedgerService::post().
 *
 * @see LedgerService::post()
 */
final class PostingGuard
{
    /**
     * A counter rather than a flag: postings nest when a recipe posts more
     * than one entry inside a single transaction, and the inner one closing
     * must not unlock the outer one.
     */
    private static int $depth = 0;

    /**
     * Run $callback with journal writes permitted.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function open(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isOpen(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Refuse a write made outside a posting. Called from the models' creating
     * hooks.
     */
    public static function assertOpen(Model $model): void
    {
        if (! self::isOpen()) {
            throw LedgerException::directWrite($model::class);
        }
    }
}
