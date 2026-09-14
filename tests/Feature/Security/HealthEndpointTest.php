<?php

declare(strict_types=1);

use App\Support\Health\HealthReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('answers /up without touching a dependency', function (): void {
    /* Laravel's own liveness probe: is PHP running at all. */
    $this->get('/up')->assertOk();
});

it('reports every dependency it checks', function (): void {
    $this->getJson(route('health'))
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure([
            'status',
            'environment',
            'version',
            'checked_at',
            'checks' => ['database', 'redis', 'cache', 'queue', 'search', 'payments'],
        ]);
});

it('does the real work rather than reading config', function (): void {
    $report = (new HealthReport)->toArray();

    /* Each passing check reports how long its probe actually took. */
    expect($report['checks']['database'])->toHaveKey('duration_ms')
        ->and($report['checks']['database']['status'])->toBe('ok');
});

it('answers 503 when something critical is broken', function (): void {
    /*
     * The status code is the part infrastructure reads. A database that
     * cannot be reached has to take the instance out of rotation.
     */
    DB::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

    $this->getJson(route('health'))
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('checks.database.status', 'failing');
});

it('stays 200 when something non-critical is broken', function (): void {
    config()->set('scout.driver', 'meilisearch');
    config()->set('scout.meilisearch.host', 'http://127.0.0.1:1');

    /*
     * A search outage degrades the storefront; it does not stop MonaFind
     * taking an order. Emptying the load balancer over somebody else's
     * afternoon would turn their outage into ours.
     */
    $this->getJson(route('health'))
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.search.status', 'failing')
        ->assertJsonPath('checks.search.critical', false);
});

it('reports an unconfigured dependency as skipped rather than broken', function (): void {
    /* A local install with the fake gateway is not an unhealthy install. */
    $this->getJson(route('health'))
        ->assertJsonPath('checks.payments.status', 'ok')
        ->assertJsonPath('checks.payments.message', 'This environment uses the "fake" payment gateway, not Lenco.');
});

it('is never cached', function (): void {
    $response = $this->getJson(route('health'));

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('hides itself behind a token when one is configured', function (): void {
    config()->set('security.health_token', 'a-long-shared-secret');

    /*
     * 404 rather than 401: the endpoint's existence is itself part of what
     * the token is protecting.
     */
    $this->getJson(route('health'))->assertNotFound();

    $this->withToken('a-long-shared-secret')
        ->getJson(route('health'))
        ->assertOk();

    $this->withHeader('X-Health-Token', 'a-long-shared-secret')
        ->getJson(route('health'))
        ->assertOk();
});

it('rejects a wrong token', function (): void {
    config()->set('security.health_token', 'a-long-shared-secret');

    $this->withToken('not-the-secret')->getJson(route('health'))->assertNotFound();
});

it('is open when no token is configured, which is right for a local install', function (): void {
    config()->set('security.health_token', null);

    $this->getJson(route('health'))->assertOk();
});

it('checks Redis only where something actually runs on it', function (): void {
    /*
     * The suite runs on array cache, sync queues and array sessions, so
     * Redis is not a dependency and reporting it unhealthy would be false.
     */
    $this->getJson(route('health'))
        ->assertOk()
        ->assertJsonPath('checks.redis.message', 'Nothing in this environment runs on Redis.');

    /*
     * Point sessions at Redis and it becomes a dependency — and a critical
     * one, because losing it would sign everybody out. Whether the probe then
     * passes depends on whether a Redis is running, which is not this test's
     * business; that it is now being asked at all is.
     */
    /*
     * Point sessions at Redis and it becomes a dependency — and a critical
     * one, because losing it would sign everybody out. The connection is
     * faked so the assertion is about which checks run, not about whether a
     * Redis happens to be listening on the machine running the suite.
     */
    config()->set('session.driver', 'redis');

    Redis::shouldReceive('connection->ping')->andReturn(true);

    $this->getJson(route('health'))
        ->assertOk()
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonPath('checks.redis.critical', true)
        ->assertJsonPath('checks.redis.used_for', 'sessions');
});

it('does not look for a Horizon supervisor that could not exist', function (): void {
    /* Horizon supervises Redis queues; under `sync`, jobs run inline. */
    $this->getJson(route('health'))
        ->assertOk()
        ->assertJsonPath(
            'checks.queue.message',
            'Jobs run on the "sync" driver, which Horizon does not supervise.',
        );
});

it('never throws, whatever a probe does', function (): void {
    config()->set('session.driver', 'redis');

    Redis::shouldReceive('connection')->andThrow(new Error('Something catastrophic'));

    /*
     * A health endpoint that throws reports nothing at the one moment its
     * answer matters most — an Error, not just an Exception.
     */
    $this->getJson(route('health'))
        ->assertStatus(503)
        ->assertJsonPath('checks.redis.status', 'failing');
});
