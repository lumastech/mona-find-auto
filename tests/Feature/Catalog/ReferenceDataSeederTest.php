<?php

declare(strict_types=1);

use App\Modules\Catalog\Database\Seeders\PartCategorySeeder;
use App\Modules\Catalog\Database\Seeders\VehicleReferenceSeeder;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\VehicleModel;

/*
 * The starter lists are what a new deployment has on day one, so they are
 * worth asserting: an empty make list means no seller can list anything.
 */

it('seeds twenty makes with the models Zambian roads actually carry', function (): void {
    $this->seed(VehicleReferenceSeeder::class);

    expect(Make::query()->count())->toBe(20)
        ->and(Make::query()->where('is_popular', true)->count())->toBeGreaterThan(5)
        ->and(VehicleModel::query()->count())->toBeGreaterThan(60);

    $toyota = Make::query()->where('slug', 'toyota')->firstOrFail();

    expect($toyota->vehicleModels()->pluck('name'))->toContain('Hilux', 'Corolla', 'Land Cruiser');
});

it('seeds a three level category tree', function (): void {
    $this->seed(PartCategorySeeder::class);

    expect(Category::query()->where('depth', 0)->count())->toBeGreaterThan(5)
        ->and(Category::query()->where('depth', 1)->count())->toBeGreaterThan(15)
        ->and(Category::query()->where('depth', 2)->count())->toBeGreaterThan(60)
        ->and(Category::query()->where('depth', '>', 2)->count())->toBe(0);
});

it('gives every seeded category an honest path', function (): void {
    $this->seed(PartCategorySeeder::class);

    Category::query()->with('parent')->get()->each(function (Category $category): void {
        $expected = ($category->parent?->path ?? '/').$category->getKey().'/';

        expect($category->path)->toBe($expected)
            ->and($category->depth)->toBe(($category->parent?->depth ?? -1) + 1);
    });
});

it('can be re-run without disturbing what is already there', function (): void {
    $this->seed(VehicleReferenceSeeder::class);
    $this->seed(PartCategorySeeder::class);

    $makes = Make::query()->count();
    $categories = Category::query()->count();

    $this->seed(VehicleReferenceSeeder::class);
    $this->seed(PartCategorySeeder::class);

    expect(Make::query()->count())->toBe($makes)
        ->and(Category::query()->count())->toBe($categories);
});

it('finds the injectors category the listing seeder files parts under', function (): void {
    $this->seed(PartCategorySeeder::class);

    $injectors = Category::query()->where('slug', 'fuel-injectors')->firstOrFail();

    expect($injectors->depth)->toBe(2)
        ->and($injectors->parent->name)->toBe('Fuel system')
        ->and($injectors->parent->parent->name)->toBe('Engine');
});
