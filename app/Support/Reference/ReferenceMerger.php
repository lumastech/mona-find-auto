<?php

declare(strict_types=1);

namespace App\Support\Reference;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Folds one reference row into another and deletes the loser.
 *
 * This is the one destructive operation the reference screens offer, and it
 * exists because retiring cannot fix the problem it solves. A retired
 * "Toyata" disappears from the pickers but the listings filed under it stay
 * filed under it — invisible to anybody filtering on Toyota, and still
 * counted separately in the facets. The rows have to move.
 *
 * Everything happens in one transaction against the links every module
 * declared (see ReferenceRegistry), so either every pointer moved or none
 * did. A half-merged catalogue is worse than an unmerged one.
 */
class ReferenceMerger
{
    public function __construct(private readonly ReferenceRegistry $registry) {}

    /**
     * Move everything pointing at $source onto $target, then delete $source.
     *
     * @throws CannotMergeReference
     */
    public function merge(
        string $listKey,
        Model $source,
        Model $target,
        ?Model $actor = null,
        ?string $reason = null,
    ): MergeReport {
        $list = $this->registry->find($listKey);

        $this->guard($list, $source, $target);

        $sourceLabel = $this->labelFor($source);
        $targetLabel = $this->labelFor($target);

        [$moved, $dropped] = DB::transaction(function () use ($list, $source, $target): array {
            $moved = [];
            $dropped = [];

            foreach ($this->registry->linksFor($list->key) as $link) {
                if ($link->uniqueWith !== null) {
                    $discarded = $this->dropCollidingPivotRows($link, $source, $target);

                    if ($discarded > 0) {
                        $dropped[$link->table.'.'.$link->column] = $discarded;
                    }
                }

                $count = DB::table($link->table)
                    ->where($link->column, $source->getKey())
                    ->update([$link->column => $target->getKey()]);

                if ($count > 0) {
                    $moved[$link->table.'.'.$link->column] = $count;
                }
            }

            /*
             * Delete on the model rather than the query builder: a list with
             * a tree behind it (categories) keeps derived columns in order
             * through its own model events, and a raw delete would leave the
             * materialised paths of the rows we just moved pointing at an id
             * that no longer exists.
             */
            $source->delete();

            return [$moved, $dropped];
        });

        $report = new MergeReport($list->key, $sourceLabel, $targetLabel, $moved, $dropped);

        audit(
            $actor,
            'reference.merged',
            $target,
            ['id' => $source->getKey(), 'label' => $sourceLabel],
            $report->toArray(),
            $reason,
        );

        return $report;
    }

    /**
     * Refuse the three merges that corrupt rather than tidy.
     *
     * @throws CannotMergeReference
     */
    private function guard(ReferenceList $list, Model $source, Model $target): void
    {
        if (! $source instanceof $list->model || ! $target instanceof $list->model) {
            throw CannotMergeReference::acrossLists($list->singular);
        }

        if ($source->is($target)) {
            throw CannotMergeReference::intoItself($list->singular);
        }

        /*
         * A self-referencing list can be asked to merge a parent into its own
         * child, which would leave the child as its own ancestor and every
         * tree walk over it non-terminating.
         */
        if ($list->parentKey === $list->key && $list->parentColumn !== null
            && $this->isDescendant($list, $target, $source)) {
            throw CannotMergeReference::intoADescendant($list->singular);
        }
    }

    /**
     * Whether $candidate sits somewhere under $ancestor.
     */
    private function isDescendant(ReferenceList $list, Model $candidate, Model $ancestor): bool
    {
        $column = (string) $list->parentColumn;
        $seen = [];
        $parentId = $candidate->getAttribute($column);

        while ($parentId !== null && ! in_array($parentId, $seen, true)) {
            if ($parentId === $ancestor->getKey()) {
                return true;
            }

            $seen[] = $parentId;
            $parentId = DB::table($list->table())->where('id', $parentId)->value($column);
        }

        return false;
    }

    /**
     * Drop the pivot rows whose owner already points at the winner.
     *
     * A mechanic listed under both "Auto electrics" and "Auto electricals"
     * would otherwise end up with the same speciality twice, which is a
     * unique-key violation on a good schema and a duplicated badge on a bad
     * one.
     */
    private function dropCollidingPivotRows(ReferenceLink $link, Model $source, Model $target): int
    {
        $owners = DB::table($link->table)
            ->where($link->column, $target->getKey())
            ->pluck((string) $link->uniqueWith);

        if ($owners->isEmpty()) {
            return 0;
        }

        return DB::table($link->table)
            ->where($link->column, $source->getKey())
            ->whereIn((string) $link->uniqueWith, $owners)
            ->delete();
    }

    private function labelFor(Model $row): string
    {
        $name = $row->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : $row::class.'#'.$row->getKey();
    }
}
