<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A node in the part category tree: Engine → Fuel system → Injectors.
 *
 * The tree is a parent pointer plus two derived columns — `depth` and a
 * materialised `path` of ancestor ids. Reading a subtree is one indexed LIKE
 * and a breadcrumb needs no queries at all beyond the ancestors themselves.
 *
 * Both derived columns are owned by App\Modules\Catalog\Services\CategoryTree.
 * Set `parent_id` and let it place the node; writing `depth` or `path` by
 * hand is how a tree ends up lying about its own shape.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $depth
 * @property string $path
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, Product> $products
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** The tree the brief asks for: root, group, part. */
    public const MAX_DEPTH = 2;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Whether this node can take children at all.
     */
    public function acceptsChildren(): bool
    {
        return $this->depth < self::MAX_DEPTH;
    }

    /**
     * The path segment matching this node and everything beneath it.
     *
     * Used as `where('path', 'like', $category->subtreePattern())`, which the
     * index on `path` can serve because the wildcard is only at the end.
     */
    public function subtreePattern(): string
    {
        return $this->path.'%';
    }

    /**
     * The ancestor ids read straight off the row, root first.
     *
     * @return array<int, int>
     */
    public function ancestorIds(): array
    {
        $ids = array_values(array_filter(array_map('intval', explode('/', trim($this->path, '/')))));

        /* The path ends with this node; a breadcrumb wants everything above it. */
        return array_slice($ids, 0, -1);
    }

    /**
     * Everything under this node, this node included.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInSubtreeOf(Builder $query, self $category): void
    {
        $query->where('path', 'like', $category->subtreePattern());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id')->orderBy('position')->orderBy('name');
    }

    /**
     * Ordered the way the tree is drawn: siblings by position, then name.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInTreeOrder(Builder $query): void
    {
        $query->orderBy('path')->orderBy('position')->orderBy('name');
    }
}
