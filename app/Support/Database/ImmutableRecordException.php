<?php

declare(strict_types=1);

namespace App\Support\Database;

use RuntimeException;

/**
 * Raised when something attempts to change or remove a row on an append-only
 * table (audit logs, journal entries, journal lines, terms acceptances,
 * payments). These tables are the platform's evidence; they only grow.
 */
class ImmutableRecordException extends RuntimeException
{
    public static function cannotUpdate(string $model): self
    {
        return new self("[{$model}] records are append-only and cannot be updated.");
    }

    public static function cannotDelete(string $model): self
    {
        return new self("[{$model}] records are append-only and cannot be deleted.");
    }
}
