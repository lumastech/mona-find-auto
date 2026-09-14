<?php

declare(strict_types=1);

namespace App\Support\Reference;

/**
 * One column, anywhere in the database, that points at a reference row.
 *
 * Merging "Toyata" into "Toyota" means finding every such column and moving
 * it across. The columns belong to the modules that own those tables — a town
 * is pointed at by users, addresses, sellers and mechanic profiles — so each
 * module declares its own rather than Identity keeping a list of everybody
 * who ever referenced a town. Miss one and the merge leaves orphans; that is
 * exactly the list a module is best placed to keep honest about itself.
 */
final readonly class ReferenceLink
{
    /**
     * @param  string  $listKey  The reference list pointed at, e.g. "cities".
     * @param  string  $table  The table doing the pointing.
     * @param  string  $column  The column holding the reference id.
     * @param  string|null  $uniqueWith  For a pivot table, the column that
     *                                   together with $column is unique. Rows
     *                                   whose owner already points at the
     *                                   winner are dropped rather than moved,
     *                                   because moving them would collide.
     */
    public function __construct(
        public string $listKey,
        public string $table,
        public string $column,
        public ?string $uniqueWith = null,
    ) {}
}
