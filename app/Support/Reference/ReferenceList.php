<?php

declare(strict_types=1);

namespace App\Support\Reference;

use Illuminate\Database\Eloquent\Model;

/**
 * One curated list staff maintain: makes, models, categories, specialities,
 * provinces, towns.
 *
 * The module that owns the list describes it here. Admin renders and merges
 * whatever was described, so it never needs to know that a make lives in
 * Catalog or a town in Identity.
 */
final readonly class ReferenceList
{
    /**
     * @param  string  $key  URL-safe identifier, e.g. "makes".
     * @param  string  $label  Plural, as staff say it: "Vehicle makes".
     * @param  string  $singular  "make" — used in confirmation copy.
     * @param  class-string<Model>  $model
     * @param  string  $owner  The module that owns the list, for the console.
     * @param  bool  $retirable  Whether rows carry an `is_active` flag.
     *                           Retiring is the usual answer to a bad row;
     *                           merging is for the case retiring cannot fix,
     *                           where two rows are the same thing. Provinces
     *                           are fixed by geography and only ever merge.
     * @param  string|null  $detailRoute  Route name of the owning module's own
     *                                    editor, when it has a richer one than
     *                                    the console's rename-and-retire form.
     * @param  string|null  $parentKey  Key of the list this one hangs off, for
     *                                  lists that are scoped (towns by
     *                                  province, models by make).
     * @param  string|null  $parentColumn  The column holding that parent.
     * @param  string|null  $note  One line explaining what the list is for.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $singular,
        public string $model,
        public string $owner,
        public bool $retirable = true,
        public ?string $detailRoute = null,
        public ?string $parentKey = null,
        public ?string $parentColumn = null,
        public ?string $note = null,
    ) {}

    public function newModel(): Model
    {
        return new $this->model;
    }

    public function table(): string
    {
        return $this->newModel()->getTable();
    }
}
