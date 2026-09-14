<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\InvalidCategoryPlacement;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CategoryTree;

/*
 * The tree's derived columns are what make "everything under Engine" one
 * indexed query instead of a recursive walk, so the tests here are mostly
 * about depth and path staying honest as nodes are created and moved.
 */

beforeEach(function (): void {
    $this->tree = app(CategoryTree::class);
});

it('places a root at depth zero with its own id in the path', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);

    expect($engine->depth)->toBe(0)
        ->and($engine->path)->toBe('/'.$engine->id.'/')
        ->and($engine->slug)->toBe('engine');
});

it('builds a three level tree with each level one deeper than its parent', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);
    $fuel = $this->tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $this->tree->create(['name' => 'Fuel injectors'], $fuel);

    expect($fuel->depth)->toBe(1)
        ->and($injectors->depth)->toBe(2)
        ->and($injectors->path)->toBe("/{$engine->id}/{$fuel->id}/{$injectors->id}/");
});

it('refuses to nest deeper than the tree allows', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);
    $fuel = $this->tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $this->tree->create(['name' => 'Fuel injectors'], $fuel);

    $this->tree->create(['name' => 'Nozzles'], $injectors);
})->throws(InvalidCategoryPlacement::class);

it('finds every descendant of a node from the path alone', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);
    $fuel = $this->tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $this->tree->create(['name' => 'Fuel injectors'], $fuel);
    $brakes = $this->tree->create(['name' => 'Brakes']);

    $subtree = $this->tree->subtreeOf($engine)->pluck('id');

    expect($subtree)->toContain($engine->id, $fuel->id, $injectors->id)
        ->and($subtree)->not->toContain($brakes->id);
});

it('rewrites descendant paths and depths when a node moves', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);
    $fuel = $this->tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $this->tree->create(['name' => 'Fuel injectors'], $fuel);
    $service = $this->tree->create(['name' => 'Service parts']);

    $this->tree->move($fuel, $service);

    $fuel->refresh();
    $injectors->refresh();

    expect($fuel->depth)->toBe(1)
        ->and($fuel->path)->toBe("/{$service->id}/{$fuel->id}/")
        ->and($injectors->depth)->toBe(2)
        ->and($injectors->path)->toBe("/{$service->id}/{$fuel->id}/{$injectors->id}/");
});

it('promotes a subtree to the root and shifts its depths down', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);
    $fuel = $this->tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $this->tree->create(['name' => 'Fuel injectors'], $fuel);

    $this->tree->move($fuel, null);

    expect($fuel->refresh()->depth)->toBe(0)
        ->and($injectors->refresh()->depth)->toBe(1)
        ->and($injectors->path)->toBe("/{$fuel->id}/{$injectors->id}/");
});

it('refuses to move a node inside its own subtree', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);
    $fuel = $this->tree->create(['name' => 'Fuel system'], $engine);

    $this->tree->move($engine, $fuel);
})->throws(InvalidCategoryPlacement::class);

it('reads a breadcrumb off the path without walking the tree', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);
    $fuel = $this->tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $this->tree->create(['name' => 'Fuel injectors'], $fuel);

    expect(array_column($this->tree->breadcrumbFor($injectors), 'name'))
        ->toBe(['Engine', 'Fuel system', 'Fuel injectors']);
});

it('browsing a heading returns the listings filed beneath it', function (): void {
    $engine = $this->tree->create(['name' => 'Engine']);
    $fuel = $this->tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $this->tree->create(['name' => 'Fuel injectors'], $fuel);

    $listing = Product::factory()->inCategory($injectors)->create();
    Product::factory()->inCategory(Category::factory()->create())->create();

    expect(Product::query()->inCategory($engine)->pluck('id')->all())->toBe([$listing->id]);
});

it('gives two categories with the same name different slugs', function (): void {
    $first = $this->tree->create(['name' => 'Filters']);
    $second = $this->tree->create(['name' => 'Filters']);

    expect($first->slug)->toBe('filters')
        ->and($second->slug)->toBe('filters-2');
});
