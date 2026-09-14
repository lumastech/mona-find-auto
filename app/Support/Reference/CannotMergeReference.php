<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * A merge that was asked for and must not happen.
 */
class CannotMergeReference extends RuntimeException
{
    public static function intoItself(string $singular): self
    {
        return new self("A {$singular} cannot be merged into itself.");
    }

    public static function acrossLists(string $singular): self
    {
        return new self("Both rows must be the same kind of {$singular}.");
    }

    public static function intoADescendant(string $singular): self
    {
        return new self("A {$singular} cannot be merged into one of its own children.");
    }
}
