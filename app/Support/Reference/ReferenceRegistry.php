<?php

declare(strict_types=1);

namespace App\Support\Reference;

use InvalidArgumentException;

/**
 * The catalogue of curated lists, and everything that points at them.
 *
 * Two halves, registered independently and in any order: a module describes
 * the list it OWNS, and every module — including that one — declares the
 * columns of its OWN tables that reference it. Sellers may register
 * `sellers.city_id` before Identity has described the towns list; the
 * registry holds links against a key rather than against an object, so boot
 * order never decides whether a merge finds all its rows.
 */
class ReferenceRegistry
{
    /** @var array<string, ReferenceList> */
    private array $lists = [];

    /** @var array<string, array<int, ReferenceLink>> */
    private array $links = [];

    public function register(ReferenceList $list): void
    {
        $this->lists[$list->key] = $list;
    }

    /**
     * Declare that one of this module's columns points at a reference list.
     */
    public function link(string $listKey, string $table, string $column, ?string $uniqueWith = null): void
    {
        $this->links[$listKey][] = new ReferenceLink($listKey, $table, $column, $uniqueWith);
    }

    /**
     * @return array<string, ReferenceList>
     */
    public function all(): array
    {
        return $this->lists;
    }

    public function has(string $key): bool
    {
        return isset($this->lists[$key]);
    }

    public function find(string $key): ReferenceList
    {
        return $this->lists[$key] ?? throw new InvalidArgumentException("No reference list is registered under [{$key}].");
    }

    /**
     * @return array<int, ReferenceLink>
     */
    public function linksFor(string $key): array
    {
        return $this->links[$key] ?? [];
    }
}
