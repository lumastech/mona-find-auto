<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\Admin\MergeReferenceRequest;
use App\Modules\Admin\Http\Requests\Admin\StoreReferenceRowRequest;
use App\Modules\Admin\Http\Requests\Admin\UpdateReferenceRowRequest;
use App\Support\Reference\CannotMergeReference;
use App\Support\Reference\ReferenceList;
use App\Support\Reference\ReferenceMerger;
use App\Support\Reference\ReferenceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One place to find every list staff curate, and the merge tool.
 *
 * The lists themselves belong to three different modules and are registered
 * rather than enumerated here — Admin does not import a Make or a City. What
 * it adds is the two operations every list needs and none of them had: a
 * usage count, so nobody retires the make half the catalogue is filed under,
 * and a merge, which is the only thing that actually fixes a duplicate.
 *
 * Renaming and retiring are offered for every list. Type-specific editing —
 * a model's body type, a town's coordinates, where a category hangs in the
 * tree — is not duplicated here; each list links to its owning module's
 * editor for that.
 */
class ReferenceDataController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly ReferenceRegistry $registry,
        private readonly ReferenceMerger $merger,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('curate-reference-data');

        $lists = $this->registry->all();
        $selected = $this->selectedList($request, $lists);

        return Inertia::render('admin/reference-data/Index', [
            'lists' => array_values(array_map(fn (ReferenceList $list): array => [
                'key' => $list->key,
                'label' => $list->label,
                'singular' => $list->singular,
                'owner' => $list->owner,
                'note' => $list->note,
                'retirable' => $list->retirable,
                'scoped' => $list->parentKey !== null && $list->parentKey !== $list->key,
                'count' => $list->newModel()->newQuery()->count(),
            ], $lists)),
            'selected' => $selected?->key,
            'rows' => $selected instanceof ReferenceList ? $this->rowsFor($selected) : [],
            'parents' => $selected instanceof ReferenceList ? $this->parentOptionsFor($selected) : [],
            'detailHref' => $selected?->detailRoute !== null ? route($selected->detailRoute) : null,
            'can' => [
                'manage' => Gate::allows('curate-reference-data-manage'),
                'merge' => Gate::allows('merge-reference-data'),
            ],
        ]);
    }

    public function store(StoreReferenceRowRequest $request, string $list): RedirectResponse
    {
        Gate::authorize('curate-reference-data-manage');

        $definition = $this->registry->find($list);
        $attributes = ['name' => $request->name()];

        if ($definition->parentColumn !== null) {
            $attributes[$definition->parentColumn] = $request->parentId();
        }

        $row = $definition->newModel()->newQuery()->create($attributes);

        audit($this->currentUser($request), 'reference.created', $row, null, $attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $request->name()])]);

        return back();
    }

    public function update(UpdateReferenceRowRequest $request, string $list, int $id): RedirectResponse
    {
        Gate::authorize('curate-reference-data-manage');

        $definition = $this->registry->find($list);
        $row = $this->row($definition, $id);

        $before = $row->only(array_filter(['name', $definition->retirable ? 'is_active' : null]));
        $attributes = ['name' => $request->name()];

        if ($definition->retirable && $request->isActive() !== null) {
            $attributes['is_active'] = $request->isActive();
        }

        $row->forceFill($attributes)->save();

        audit($this->currentUser($request), 'reference.updated', $row, $before, $attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    /**
     * Fold one row into another and delete the loser.
     *
     * The count of what moved comes back on the toast rather than only into
     * the audit row: "2 rows moved" is a typo being cleaned up and "1,840" is
     * somebody who picked the wrong way round, and they should find that out
     * on the screen where they pressed the button.
     */
    public function merge(MergeReferenceRequest $request, string $list): RedirectResponse
    {
        Gate::authorize('merge-reference-data');

        $definition = $this->registry->find($list);

        try {
            $report = $this->merger->merge(
                $list,
                $this->row($definition, $request->sourceId()),
                $this->row($definition, $request->targetId()),
                $this->currentUser($request),
                $request->reason(),
            );
        } catch (CannotMergeReference $exception) {
            throw ValidationException::withMessages(['target_id' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':from merged into :into. :rows rows moved.', [
                'from' => $report->sourceLabel,
                'into' => $report->targetLabel,
                'rows' => $report->rowsMoved(),
            ]),
        ]);

        return back();
    }

    /**
     * @param  array<string, ReferenceList>  $lists
     */
    private function selectedList(Request $request, array $lists): ?ReferenceList
    {
        $key = (string) $request->query('list', '');

        if ($this->registry->has($key)) {
            return $this->registry->find($key);
        }

        return $lists === [] ? null : reset($lists);
    }

    /**
     * Every row in the list, with how much of the platform points at it.
     *
     * One aggregate query per registered link rather than a count per row:
     * the towns list is short but the categories tree is not, and a count
     * subquery per row is the shape that makes a staff screen slow.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(ReferenceList $list): array
    {
        $usage = $this->usageCounts($list);

        return $list->newModel()->newQuery()
            ->orderBy('name')
            ->get()
            ->map(fn (Model $row): array => [
                'id' => $row->getKey(),
                'name' => (string) $row->getAttribute('name'),
                'is_active' => $list->retirable ? (bool) $row->getAttribute('is_active') : null,
                'parent_id' => $list->parentColumn !== null ? $row->getAttribute($list->parentColumn) : null,
                'usage' => $usage[$row->getKey()] ?? 0,
            ])
            ->all();
    }

    /**
     * How many rows point at each id of this list, across every module.
     *
     * @return array<int|string, int>
     */
    private function usageCounts(ReferenceList $list): array
    {
        $totals = [];

        foreach ($this->registry->linksFor($list->key) as $link) {
            $counts = DB::table($link->table)
                ->select($link->column, DB::raw('count(*) as total'))
                ->whereNotNull($link->column)
                ->groupBy($link->column)
                ->pluck('total', $link->column);

            foreach ($counts as $id => $total) {
                $totals[$id] = ($totals[$id] ?? 0) + (int) $total;
            }
        }

        return $totals;
    }

    /**
     * The rows of the parent list, for a scoped list's picker.
     *
     * @return array<int, array{value: int|string, label: string}>
     */
    private function parentOptionsFor(ReferenceList $list): array
    {
        if ($list->parentKey === null || ! $this->registry->has($list->parentKey)) {
            return [];
        }

        return $this->registry->find($list->parentKey)->newModel()->newQuery()
            ->orderBy('name')
            ->get()
            ->map(static fn (Model $row): array => [
                'value' => $row->getKey(),
                'label' => (string) $row->getAttribute('name'),
            ])
            ->all();
    }

    private function row(ReferenceList $list, int $id): Model
    {
        return $list->newModel()->newQuery()->findOrFail($id);
    }
}
