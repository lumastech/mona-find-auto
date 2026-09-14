<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Registers every module listed in config/modules.php, in order.
 *
 * This is the only place modules are wired into the framework; adding a
 * module means creating app/Modules/{Name}/{Name}ServiceProvider.php and
 * adding its name to config/modules.php.
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->resolveModuleFactories();

        foreach ($this->moduleNames() as $module) {
            $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

            if (! class_exists($provider)) {
                throw new RuntimeException("Module [{$module}] is enabled in config/modules.php but [{$provider}] does not exist.");
            }

            $this->app->register($provider);
        }
    }

    /**
     * Teach Eloquent that a module's models are paired with factories inside
     * that same module: App\Modules\Catalog\Models\Product resolves to
     * App\Modules\Catalog\Database\Factories\ProductFactory.
     */
    private function resolveModuleFactories(): void
    {
        Factory::guessFactoryNamesUsing(self::factoryFor(...));
        Factory::guessModelNamesUsing(self::modelFor(...));
    }

    /**
     * @param  class-string<Model>  $model
     * @return class-string<Factory<Model>>
     */
    private static function factoryFor(string $model): string
    {
        $name = Str::afterLast($model, '\\');

        $candidates = [];

        if (Str::startsWith($model, 'App\\Modules\\')) {
            $module = Str::between($model, 'App\\Modules\\', '\\Models\\');
            $candidates[] = "App\\Modules\\{$module}\\Database\\Factories\\{$name}Factory";
        }

        $candidates[] = "Database\\Factories\\{$name}Factory";

        foreach ($candidates as $candidate) {
            if (is_subclass_of($candidate, Factory::class)) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf(
            'No factory found for [%s]. Expected one of: %s.',
            $model,
            implode(', ', $candidates),
        ));
    }

    /**
     * @param  Factory<Model>  $factory
     * @return class-string<Model>
     */
    private static function modelFor(Factory $factory): string
    {
        $factoryClass = $factory::class;
        $name = Str::of(Str::afterLast($factoryClass, '\\'))->before('Factory')->toString();

        $candidates = [];

        if (Str::startsWith($factoryClass, 'App\\Modules\\')) {
            $module = Str::between($factoryClass, 'App\\Modules\\', '\\Database\\Factories\\');
            $candidates[] = "App\\Modules\\{$module}\\Models\\{$name}";
        }

        $candidates[] = "App\\Models\\{$name}";

        foreach ($candidates as $candidate) {
            if (is_subclass_of($candidate, Model::class)) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf(
            'No model found for [%s]. Expected one of: %s.',
            $factoryClass,
            implode(', ', $candidates),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function moduleNames(): array
    {
        /** @var array<int, string> $modules */
        $modules = config('modules.enabled', []);

        return $modules;
    }
}
