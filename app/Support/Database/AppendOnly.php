<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Model;

/**
 * Makes an Eloquent model refuse every write except an insert.
 *
 * The database enforces the same rule with triggers (see AppendOnlyTable), so
 * a raw query cannot slip past this; the trait exists to fail loudly and
 * early, with a readable message, inside application code.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (Model $model): void {
            throw ImmutableRecordException::cannotUpdate($model::class);
        });

        static::deleting(function (Model $model): void {
            throw ImmutableRecordException::cannotDelete($model::class);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw ImmutableRecordException::cannotUpdate(static::class);
    }

    public function delete(): bool
    {
        throw ImmutableRecordException::cannotDelete(static::class);
    }

    public function forceDelete(): bool
    {
        throw ImmutableRecordException::cannotDelete(static::class);
    }
}
