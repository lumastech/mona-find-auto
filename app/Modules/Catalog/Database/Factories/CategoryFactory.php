<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Categories come out as roots.
 *
 * The derived columns are set here so a factory-made root is a valid tree
 * node on its own. Anything with a parent should go through
 * App\Modules\Catalog\Services\CategoryTree, which is the only thing that
 * knows how to place a node — see the childOf() state.
 *
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->sentence(),
            'depth' => 0,
            'path' => '',
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * The path contains the row's own id, so it can only be written once the
     * row exists.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Category $category): void {
            if ($category->path !== '') {
                return;
            }

            $parent = $category->parent_id === null
                ? null
                : Category::query()->whereKey($category->parent_id)->first();

            $category->forceFill([
                'path' => ($parent === null ? '/' : $parent->path).$category->getKey().'/',
                'depth' => $parent === null ? 0 : $parent->depth + 1,
            ])->save();
        });
    }

    public function childOf(Category $parent): static
    {
        return $this->state(['parent_id' => $parent->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
