<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Base class for every module's service provider.
 *
 * A module is self-contained: it owns its models, controllers, services,
 * actions, events, listeners, jobs, policies, migrations, factories and route
 * files, and it exposes itself to the rest of the application only through
 * domain events and the interfaces it binds here.
 *
 * Subclasses normally only need to implement registerModule()/bootModule();
 * routes and migrations are wired up automatically from the module directory.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Bind this module's services. Runs before any module has booted, so it
     * must not resolve anything from another module.
     */
    protected function registerModule(): void {}

    /**
     * Wire up listeners, policies and observers. Every module has registered
     * by the time this runs.
     */
    protected function bootModule(): void {}

    final public function register(): void
    {
        $this->registerModule();
    }

    final public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleMigrations();
        $this->bootModule();
    }

    /**
     * The module's short name, e.g. "Catalog".
     */
    public function moduleName(): string
    {
        return Str::of(static::class)->afterLast('\\')->before('ServiceProvider')->toString();
    }

    /**
     * An absolute path inside the module directory.
     */
    public function modulePath(string $path = ''): string
    {
        $base = app_path('Modules/'.$this->moduleName());

        return $path === '' ? $base : $base.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Load routes/{web,seller,admin,api}.php under the middleware, URI prefix
     * and route-name prefix declared in config/modules.php.
     */
    private function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        /** @var array<string, array{middleware: array<int, string>, prefix: string, name: string}> $groups */
        $groups = config('modules.route_groups', []);

        foreach ($groups as $group => $definition) {
            $file = $this->modulePath("routes/{$group}.php");

            if (! is_file($file)) {
                continue;
            }

            Route::middleware($definition['middleware'])
                ->prefix($definition['prefix'])
                ->name($definition['name'])
                ->group($file);
        }
    }

    private function loadModuleMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations');

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }
}
