<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Exceptions\InvalidCategoryPlacement;
use App\Modules\Catalog\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one thing that writes a category's place in the tree.
 *
 * `depth` and `path` on Category are derived from the parent chain. Nothing
 * else sets them: a row that says it is two deep while its parent is a root
 * would break every subtree query silently, and a tree that lies about its
 * own shape is worse than no tree.
 *
 * Moving a node rewrites the paths of everything beneath it in one statement,
 * which is the whole reason the path is materialised — browsing "Engine" has
 * to return injectors without walking the tree.
 */
class CategoryTree
{
    /**
     * Create a category under an optional parent.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidCategoryPlacement when the parent is already at the deepest level
     */
    public function create(array $attributes, ?Category $parent = null): Category
    {
        $this->guardDepth($parent);

        return DB::transaction(function () use ($attributes, $parent): Category {
            $category = new Category([
                ...$attributes,
                'parent_id' => $parent?->getKey(),
                'slug' => $attributes['slug'] ?? $this->uniqueSlugFor((string) $attributes['name']),
                'position' => $attributes['position'] ?? $this->nextPositionUnder($parent),
            ]);

            /* Saved once to get an id, then placed: the path has to contain it. */
            $category->save();

            return $this->place($category, $parent);
        });
    }

    /**
     * Rename or re-describe a node without moving it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        $category->fill($attributes)->save();

        return $category;
    }

    /**
     * Move a node — and everything under it — to a new parent.
     *
     * @throws InvalidCategoryPlacement when the move would nest a node inside itself or overflow the tree
     */
    public function move(Category $category, ?Category $parent): Category
    {
        if ($parent !== null && $this->isSelfOrDescendant($category, $parent)) {
            throw InvalidCategoryPlacement::intoOwnSubtree($category);
        }

        $this->guardDepth($parent, $this->subtreeHeight($category));

        return DB::transaction(function () use ($category, $parent): Category {
            /*
             * Descendants are found by the path they still carry, so they are
             * collected before the node itself is re-placed.
             */
            $descendants = Category::query()
                ->where('path', 'like', $category->path.'_%')
                ->orderBy('depth')
                ->pluck('id');

            $category->parent_id = $parent?->getKey();
            $category->position = $this->nextPositionUnder($parent);

            $moved = $this->place($category, $parent);

            $this->replaceAll($descendants->all());

            return $moved;
        });
    }

    /**
     * The whole tree in draw order, eager-loaded for a picker or an admin list.
     *
     * @return Collection<int, Category>
     */
    public function all(bool $activeOnly = false): Collection
    {
        return Category::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->inTreeOrder()
            ->get();
    }

    /**
     * The tree as nested arrays, which is what a Vue picker wants.
     *
     * Built from one query rather than one per level — a three-level tree is
     * still a few hundred rows, and N+1 on a category picker is felt on every
     * listing form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nested(bool $activeOnly = false): array
    {
        $byParent = $this->all($activeOnly)->groupBy(
            static fn (Category $category): string => (string) ($category->parent_id ?? 0),
        );

        $build = function (int $parentId) use (&$build, $byParent): array {
            return $byParent->get((string) $parentId, collect())
                ->map(static fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'depth' => $category->depth,
                    'is_active' => $category->is_active,
                    /* A node with children is a heading; only leaves take listings. */
                    'selectable' => $category->depth > 0,
                    'children' => $build($category->id),
                ])
                ->values()
                ->all();
        };

        return $build(0);
    }

    /**
     * A node's ancestors, root first, for a breadcrumb.
     *
     * The ids are read off the materialised path, so this is one query
     * whatever the depth.
     *
     * @return Collection<int, Category>
     */
    public function ancestorsOf(Category $category): Collection
    {
        $ids = $category->ancestorIds();

        if ($ids === []) {
            return collect();
        }

        return Category::query()
            ->whereIn('id', $ids)
            ->orderBy('depth')
            ->get();
    }

    /**
     * The breadcrumb for a category page: ancestors, then the node itself.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function breadcrumbFor(Category $category): array
    {
        return $this->ancestorsOf($category)
            ->push($category)
            ->map(static fn (Category $node): array => [
                'id' => $node->id,
                'name' => $node->name,
                'slug' => $node->slug,
            ])
            ->values()
            ->all();
    }

    /**
     * Everything under a node, the node included.
     *
     * @return Collection<int, Category>
     */
    public function subtreeOf(Category $category): Collection
    {
        return Category::query()->inSubtreeOf($category)->inTreeOrder()->get();
    }

    /**
     * Write the derived columns from the parent chain.
     */
    private function place(Category $category, ?Category $parent): Category
    {
        $category->depth = $parent === null ? 0 : $parent->depth + 1;
        $category->path = ($parent === null ? '/' : $parent->path).$category->getKey().'/';
        $category->save();

        return $category;
    }

    /**
     * Re-place nodes whose ancestor has moved, shallowest first.
     *
     * Order matters: each node is placed against a parent that has already
     * been placed, so the new path is built on a correct one. The tree is
     * three levels deep by design, so a moved subtree is small — small enough
     * that walking it is clearer than a raw string-splicing UPDATE, and it
     * behaves identically on MySQL and SQLite rather than needing a dialect
     * branch.
     *
     * @param  array<int, int>  $ids
     */
    private function replaceAll(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Category::query()
            ->whereIn('id', $ids)
            ->orderBy('depth')
            ->get()
            ->each(function (Category $node): void {
                $parent = $node->parent_id === null
                    ? null
                    : Category::query()->whereKey($node->parent_id)->first();

                $this->place($node, $parent);
            });
    }

    /**
     * @throws InvalidCategoryPlacement
     */
    private function guardDepth(?Category $parent, int $subtreeHeight = 0): void
    {
        $resultingDepth = ($parent === null ? 0 : $parent->depth + 1) + $subtreeHeight;

        if ($resultingDepth > Category::MAX_DEPTH) {
            throw InvalidCategoryPlacement::tooDeep(Category::MAX_DEPTH);
        }
    }

    /**
     * How many levels hang below a node, so a move can be checked before it
     * pushes its own children past the maximum depth.
     */
    private function subtreeHeight(Category $category): int
    {
        $deepest = Category::query()->inSubtreeOf($category)->max('depth');

        return (int) $deepest - $category->depth;
    }

    private function isSelfOrDescendant(Category $category, Category $candidate): bool
    {
        return $candidate->is($category) || str_starts_with($candidate->path, $category->path);
    }

    private function nextPositionUnder(?Category $parent): int
    {
        return (int) Category::query()
            ->where('parent_id', $parent?->getKey())
            ->max('position') + 1;
    }

    private function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 1;

        while (Category::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }
}
