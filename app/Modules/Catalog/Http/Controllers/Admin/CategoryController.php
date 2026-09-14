<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Exceptions\InvalidCategoryPlacement;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Curating the part category tree.
 *
 * Placement always goes through CategoryTree, which owns the derived depth
 * and path columns. A controller writing them itself is how a tree ends up
 * lying about its own shape — and a tree that lies makes "everything under
 * Engine" quietly return the wrong listings.
 */
class CategoryController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * @throws ValidationException when the parent is already at the deepest level
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Category::class);

        $validated = $this->validated($request);
        $parent = $this->parentFrom($validated);

        try {
            $category = $this->tree->create($validated, $parent);
        } catch (InvalidCategoryPlacement $exception) {
            throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
        }

        audit($this->currentUser($request), 'catalog.category.created', $category, null, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $category->name])]);

        return back();
    }

    /**
     * Rename a category, and move it if the parent changed.
     *
     * @throws ValidationException when the move is one the tree cannot represent
     */
    public function update(Request $request, Category $category): RedirectResponse
    {
        Gate::authorize('manage', Category::class);

        $before = $category->only(['name', 'description', 'parent_id', 'position', 'is_active']);
        $validated = $this->validated($request, $category);

        $this->tree->update($category, [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'position' => $validated['position'] ?? $category->position,
            'is_active' => $validated['is_active'] ?? $category->is_active,
        ]);

        $newParentId = $validated['parent_id'] ?? null;

        if ($newParentId !== $category->parent_id) {
            try {
                $this->tree->move($category, $this->parentFrom($validated));
            } catch (InvalidCategoryPlacement $exception) {
                throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
            }
        }

        audit($this->currentUser($request), 'catalog.category.updated', $category->refresh(), $before, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $category->name])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id'),
                /* Moving a node inside itself is caught by the tree too, but saying so here is kinder. */
                Rule::notIn($category === null ? [] : [$category->getKey()]),
            ],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ], [
            'parent_id.not_in' => 'A category cannot be its own parent.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function parentFrom(array $validated): ?Category
    {
        $parentId = $validated['parent_id'] ?? null;

        return $parentId === null ? null : Category::query()->whereKey($parentId)->firstOrFail();
    }
}
