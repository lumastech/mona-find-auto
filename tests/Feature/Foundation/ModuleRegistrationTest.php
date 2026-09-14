<?php

declare(strict_types=1);

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Read the module list straight from the config file: Pest resolves datasets
 * before the application container is available.
 *
 * @return array<int, string>
 */
function enabledModules(): array
{
    /** @var array{enabled: array<int, string>} $config */
    $config = require dirname(__DIR__, 3).'/config/modules.php';

    return $config['enabled'];
}

it('registers a service provider for every enabled module', function (string $module) {
    $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

    expect(class_exists($provider))->toBeTrue()
        ->and(app()->getProviders($provider))->not->toBeEmpty();
})->with(fn () => enabledModules());

it('gives every module the standard directory layout', function (string $module) {
    $path = app_path("Modules/{$module}");

    foreach (['Models', 'Http/Controllers', 'Services', 'Actions', 'Events', 'Listeners', 'Jobs', 'Policies', 'routes'] as $directory) {
        expect(is_dir("{$path}/{$directory}"))->toBeTrue("{$module} is missing {$directory}");
    }
})->with(fn () => enabledModules());

it('exposes the module name and path from its provider', function () {
    $provider = array_values(app()->getProviders('App\\Modules\\Catalog\\CatalogServiceProvider'))[0];

    expect($provider)->toBeInstanceOf(ModuleServiceProvider::class)
        ->and($provider->moduleName())->toBe('Catalog')
        ->and($provider->modulePath('routes/web.php'))->toBe(app_path('Modules/Catalog/routes/web.php'));
});

it('mounts module storefront routes at the root', function () {
    expect(Route::has('search'))->toBeTrue()
        ->and(route('search', absolute: false))->toBe('/search');
});

it('mounts module seller routes under /seller with the seller prefix', function () {
    $group = config('modules.route_groups.seller');

    Route::middleware($group['middleware'])
        ->prefix($group['prefix'])
        ->name($group['name'])
        ->group(function (): void {
            Route::get('module-probe', fn () => 'ok')->name('module-probe');
        });

    Route::getRoutes()->refreshNameLookups();

    expect(route('seller.module-probe', absolute: false))->toBe('/seller/module-probe');
});

it('declares a route group for each area', function () {
    expect(array_keys(config('modules.route_groups')))->toBe(['web', 'seller', 'admin', 'api']);
});
