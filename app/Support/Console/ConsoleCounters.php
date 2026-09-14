<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Where the staff dashboard's numbers come from.
 *
 * The dashboard has to show a count of pending seller verifications, held
 * listings, unapproved mechanics, open disputes, unconfirmed orders, stale
 * stock, flagged reviews, reconciliation exceptions and payout batches
 * awaiting approval — nine queues owned by eight different modules. Counting
 * them from inside Admin would mean Admin importing eight modules' models and
 * re-deriving eight modules' definitions of "pending", and every later change
 * to what pending means would have to be made twice.
 *
 * So the dashboard asks instead. Each module registers a provider in its own
 * service provider, phrases its own queue, and links to its own screen; Admin
 * renders whatever came back. Adding a tenth queue is a change inside the
 * module that owns it.
 *
 * Providers are resolved lazily and one failing provider costs one tile, not
 * the whole dashboard — a staff console that will not open because a distant
 * module's count threw is worse than a console with a hole in it.
 */
class ConsoleCounters
{
    /** @var array<int, class-string<ProvidesConsoleCounters>> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ProvidesConsoleCounters>  $provider
     */
    public function register(string $provider): void
    {
        if (! in_array($provider, $this->providers, true)) {
            $this->providers[] = $provider;
        }
    }

    /**
     * Every counter this user is allowed to see, in registration order.
     *
     * Registration order is config/modules.php order, which reads roughly as
     * the platform's own pipeline — accounts, sellers, listings, orders,
     * money — and is a more useful arrangement than anything sorted.
     *
     * @return array<int, ConsoleCounter>
     */
    public function for(User $user): array
    {
        $counters = [];

        foreach ($this->providers as $provider) {
            foreach ($this->resolve($provider) as $counter) {
                if ($counter->ability !== null && Gate::forUser($user)->denies($counter->ability)) {
                    continue;
                }

                $counters[] = $counter;
            }
        }

        return $counters;
    }

    /**
     * @param  class-string<ProvidesConsoleCounters>  $provider
     * @return array<int, ConsoleCounter>
     */
    private function resolve(string $provider): array
    {
        try {
            return $this->container->make($provider)->counters();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
