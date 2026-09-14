<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Where the staff dashboard's statistics come from.
 *
 * The same argument as ConsoleCounters, applied to the other half of the
 * screen. GMV is a sum of journal lines and only Finance may read those;
 * "listings published this week" is Catalog's definition of published and
 * changes when Catalog's does. Computing either from inside Admin would mean
 * Admin re-deriving another module's rules and then drifting from them.
 *
 * So the dashboard asks. Each module registers a provider from its own
 * service provider, phrases its own figure, and says which way is good news;
 * Admin renders whatever came back.
 *
 * Providers are resolved lazily and one failing provider costs its own tiles,
 * not the screen — the staff console has to open on the morning the metrics
 * query is the thing that broke.
 */
class ConsoleStatistics
{
    /** @var array<int, class-string<ProvidesConsoleStatistics>> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ProvidesConsoleStatistics>  $provider
     */
    public function register(string $provider): void
    {
        if (! in_array($provider, $this->providers, true)) {
            $this->providers[] = $provider;
        }
    }

    /**
     * Every statistic this user is allowed to see, in config/modules.php order.
     *
     * @return array<int, ConsoleStat>
     */
    public function statsFor(User $user, ConsoleWindow $window): array
    {
        $stats = [];

        foreach ($this->providers as $provider) {
            /** @var array<int, ConsoleStat> $contributed */
            $contributed = $this->collect(
                static fn (ProvidesConsoleStatistics $instance): array => $instance->stats($window),
                $provider,
            );

            foreach ($contributed as $stat) {
                if ($this->permits($user, $stat->ability)) {
                    $stats[] = $stat;
                }
            }
        }

        return $stats;
    }

    /**
     * Every chart this user is allowed to see, empty ones dropped.
     *
     * @return array<int, ConsoleChart>
     */
    public function chartsFor(User $user, ConsoleWindow $window): array
    {
        $charts = [];

        foreach ($this->providers as $provider) {
            /** @var array<int, ConsoleChart> $contributed */
            $contributed = $this->collect(
                static fn (ProvidesConsoleStatistics $instance): array => $instance->charts($window),
                $provider,
            );

            foreach ($contributed as $chart) {
                if ($this->permits($user, $chart->ability) && $chart->hasData()) {
                    $charts[] = $chart;
                }
            }
        }

        return $charts;
    }

    private function permits(User $user, ?string $ability): bool
    {
        return $ability === null || Gate::forUser($user)->allows($ability);
    }

    /**
     * @param  \Closure(ProvidesConsoleStatistics): array<int, mixed>  $read
     * @param  class-string<ProvidesConsoleStatistics>  $provider
     * @return array<int, mixed>
     */
    private function collect(\Closure $read, string $provider): array
    {
        try {
            return $read($this->container->make($provider));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
