<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Meilisearch\Client as MeilisearchClient;
use Throwable;

/**
 * Asks every dependency whether it is actually working.
 *
 * `/up` answers whether PHP is running. That is enough for a process
 * supervisor and not nearly enough for a deployment gate: an instance that
 * boots, answers 200, and cannot reach MySQL is worse than one that is
 * plainly down, because the load balancer will send it traffic.
 *
 * ## Every check does real work
 *
 * A check that only asserts a config value is present proves nothing. Each of
 * these performs the cheapest operation that would fail if the dependency
 * were unreachable — a `select 1`, a Redis `ping`, a cache round-trip.
 *
 * ## Critical and non-critical
 *
 * Only a failing critical check makes the endpoint answer 503. MySQL and
 * Redis are critical: without them the platform cannot take an order.
 * Meilisearch and Lenco are not, and deliberately so — search degrading to
 * nothing is bad, but pulling every instance out of the load balancer
 * because a third party is having an afternoon would turn their outage into
 * ours.
 *
 * ## Nothing here talks to Lenco over the network
 *
 * The gateway check asserts that a live gateway is configured, which
 * environment it points at, and that its credentials are present. It does
 * not call Lenco. A health endpoint that made an outbound API call on every
 * probe would spend real quota on a request a load balancer makes every few
 * seconds, and would turn a slow third party into a slow health check.
 */
class HealthReport
{
    /**
     * @return array<int, HealthCheck>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->redis(),
            $this->cache(),
            $this->queue(),
            $this->search(),
            $this->paymentGateway(),
        ];
    }

    /**
     * Is anything critical broken?
     *
     * @param  array<int, HealthCheck>  $checks
     */
    public function isHealthy(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->critical && ! $check->healthy) {
                return false;
            }
        }

        return true;
    }

    /**
     * The whole report, ready to serialise.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $checks = $this->checks();

        return [
            'status' => $this->isHealthy($checks) ? 'ok' : 'unhealthy',
            'environment' => app()->environment(),
            'version' => config('app.version'),
            'checked_at' => now()->toIso8601String(),
            'checks' => array_reduce(
                $checks,
                static function (array $carry, HealthCheck $check): array {
                    $carry[$check->name] = $check->toArray();

                    return $carry;
                },
                [],
            ),
        ];
    }

    private function database(): HealthCheck
    {
        return $this->measure('database', true, static function (): array {
            DB::connection()->select('select 1');

            return ['driver' => DB::connection()->getDriverName()];
        });
    }

    /**
     * Redis, but only where Redis is actually load-bearing.
     *
     * A deployment whose cache, queue and sessions are all elsewhere does not
     * depend on Redis, and reporting it unhealthy would be false — the test
     * suite is exactly that deployment. Where any of the three DO run on it,
     * Redis is critical: losing it takes sessions, rate limiting and every
     * queued job at once.
     */
    private function redis(): HealthCheck
    {
        $users = $this->redisConsumers();

        if ($users === []) {
            return HealthCheck::skipped('redis', 'Nothing in this environment runs on Redis.');
        }

        return $this->measure('redis', true, static function () use ($users): array {
            Redis::connection()->ping();

            return ['used_for' => implode(', ', $users)];
        });
    }

    /**
     * Which subsystems are pointed at Redis in this environment.
     *
     * @return array<int, string>
     */
    private function redisConsumers(): array
    {
        $consumers = [];

        if (config('cache.default') === 'redis') {
            $consumers[] = 'cache';
        }

        if (config('queue.default') === 'redis') {
            $consumers[] = 'queue';
        }

        if (config('session.driver') === 'redis') {
            $consumers[] = 'sessions';
        }

        return $consumers;
    }

    /**
     * A full round-trip, not just a connection.
     *
     * A cache that accepts writes and returns nothing is a failure mode that
     * a connection check would miss entirely, and it breaks sessions, the
     * settings repository and every rate limiter at once.
     */
    private function cache(): HealthCheck
    {
        return $this->measure('cache', config('cache.default') !== 'array', static function (): array {
            $key = 'health:'.bin2hex(random_bytes(8));

            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== 'ok') {
                throw new \RuntimeException('The cache accepted a write and did not return it.');
            }

            return ['store' => config('cache.default')];
        });
    }

    /**
     * Horizon's master supervisor, which is what actually runs the queues.
     *
     * Every side effect on this platform — webhooks, payouts, notifications,
     * reconciliation — is a queued job, so a dead Horizon means money stops
     * moving while the site carries on looking fine. Critical for that reason.
     */
    private function queue(): HealthCheck
    {
        /*
         * interface_exists, not class_exists: MasterSupervisorRepository is
         * an interface, and class_exists() returns false for one. The earlier
         * version of this check reported "Horizon is not installed" on a
         * deployment where Horizon was running perfectly well.
         */
        if (! interface_exists(MasterSupervisorRepository::class)) {
            return HealthCheck::skipped('queue', 'Horizon is not installed.');
        }

        /*
         * Horizon only supervises Redis queues. Under the `sync` driver — the
         * test suite, and some local installs — jobs run inline and there is
         * no supervisor to look for.
         */
        if (config('queue.default') !== 'redis') {
            return HealthCheck::skipped(
                'queue',
                sprintf('Jobs run on the "%s" driver, which Horizon does not supervise.', config('queue.default')),
            );
        }

        return $this->measure('queue', true, static function (): array {
            $masters = app(MasterSupervisorRepository::class)->all();

            if ($masters === []) {
                throw new \RuntimeException('No Horizon supervisor is running.');
            }

            $statuses = array_map(static fn ($master): string => (string) $master->status, $masters);

            if (! in_array('running', $statuses, true)) {
                throw new \RuntimeException('Horizon is installed but paused or inactive: '.implode(', ', $statuses));
            }

            return ['supervisors' => count($masters)];
        });
    }

    /**
     * Meilisearch. Not critical: the storefront survives a dead index badly,
     * but it survives.
     */
    private function search(): HealthCheck
    {
        if (config('scout.driver') !== 'meilisearch') {
            return HealthCheck::skipped('search', 'Scout is not using Meilisearch in this environment.');
        }

        return $this->measure('search', false, static function (): array {
            /*
             * Built from config rather than pulled out of Scout's engine:
             * MeilisearchEngine keeps its client in a protected property with
             * no accessor, and a health check has no business reaching past
             * that. Two constructor arguments is the whole cost.
             */
            $client = new MeilisearchClient(
                (string) config('scout.meilisearch.host'),
                config('scout.meilisearch.key'),
            );

            $health = $client->health();

            if (($health['status'] ?? null) !== 'available') {
                throw new \RuntimeException('Meilisearch reported status: '.json_encode($health));
            }

            return ['status' => 'available'];
        });
    }

    /**
     * The payment gateway's configuration, not its availability.
     *
     * See the class docblock for why this deliberately makes no network call.
     */
    private function paymentGateway(): HealthCheck
    {
        /*
         * Asked of the configuration rather than by `instanceof` on the
         * resolved gateway. Which driver is selected is the thing being
         * checked, and resolving the gateway to find out would build an HTTP
         * client on a health probe that runs every few seconds.
         */
        $driver = config('integrations.payment_gateway.driver');

        if ($driver !== 'lenco') {
            return HealthCheck::skipped(
                'payments',
                sprintf('This environment uses the "%s" payment gateway, not Lenco.', is_string($driver) ? $driver : 'unknown'),
            );
        }

        $environment = (string) config('lenco.environment', 'sandbox');
        $missing = [];

        foreach (['lenco.secret_key' => 'secret key', 'lenco.public_key' => 'public key'] as $key => $label) {
            if (blank(config($key))) {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            return HealthCheck::failing(
                'payments',
                false,
                'Lenco is selected but missing its '.implode(' and ', $missing).'.',
                context: ['environment' => $environment],
            );
        }

        /*
         * Worth surfacing rather than merely passing: a production
         * deployment still pointed at the sandbox takes real orders and
         * collects no real money.
         */
        return HealthCheck::passing('payments', false, context: [
            'environment' => $environment,
            'live' => $environment === 'live',
        ]);
    }

    /**
     * Run one check, time it, and turn any exception into a failing result.
     *
     * A health endpoint that throws is a health endpoint that reports nothing
     * — the one moment its answer matters most.
     *
     * @param  callable(): array<string, scalar|null>  $probe
     */
    private function measure(string $name, bool $critical, callable $probe): HealthCheck
    {
        $started = microtime(true);

        try {
            $context = $probe();
        } catch (Throwable $exception) {
            return HealthCheck::failing(
                $name,
                $critical,
                $exception->getMessage(),
                $this->elapsed($started),
            );
        }

        return HealthCheck::passing($name, $critical, $this->elapsed($started), $context);
    }

    private function elapsed(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
