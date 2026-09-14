<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The limiters are defined in AppServiceProvider and FortifyServiceProvider;
 * this asserts they are actually attached to the routes that need them, which
 * is the half that silently goes missing.
 */

/**
 * Every named limiter the application defines, and the routes it guards.
 *
 * @return array<string, array{0: string, 1: int}>
 */
dataset('named limiters', [
    'checkout' => ['checkout', 10],
    'payments' => ['payments', 30],
    'webhooks' => ['webhooks', 240],
    'search' => ['search', 90],
    'register' => ['register', 10],
    'uploads' => ['uploads', 30],
    'api' => ['api', 60],
]);

it('defines every named limiter the routes reference', function (string $name): void {
    expect(RateLimiter::limiter($name))->not->toBeNull();
})->with('named limiters');

it('counts an authenticated client against the account, not the address', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    /*
     * Zambian mobile networks put many subscribers behind few egress
     * addresses. Keying on the IP alone would mean one buyer's retry loop
     * throttling a whole network.
     */
    $limit = RateLimiter::limiter('checkout')(request()->setUserResolver(fn () => $user));

    expect($limit->key)->toBe('user:'.$user->id);
});

it('falls back to the address for somebody who has not signed in', function (): void {
    $limit = RateLimiter::limiter('search')(request());

    expect($limit->key)->toStartWith('ip:');
});

it('never lets a user id and an address collide into one bucket', function (): void {
    $user = User::factory()->create(['id' => 5]);

    /*
     * Two distinct requests: one Request instance would carry the user
     * resolver set on it into the second lookup and the assertion would
     * compare a key with itself.
     */
    $signedIn = Request::create('/api/v1/platform')->setUserResolver(fn () => $user);
    $guest = Request::create('/api/v1/platform', server: ['REMOTE_ADDR' => '5']);

    /* User 5 and the address "5" are not the same client. */
    expect(RateLimiter::limiter('api')($signedIn)->key)->toBe('user:5')
        ->and(RateLimiter::limiter('api')($guest)->key)->toBe('ip:5');
});

it('throttles the checkout POST', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    foreach (range(1, 10) as $ignored) {
        $this->post(route('checkout.store'), []);
    }

    $this->post(route('checkout.store'), [])->assertStatus(429);
});

it('throttles storefront search, the busiest guest surface on the platform', function (): void {
    foreach (range(1, 90) as $ignored) {
        $this->get(route('search', ['q' => 'brake pads']));
    }

    $this->get(route('search', ['q' => 'brake pads']))->assertStatus(429);
});

it('throttles the gateway webhook without ever throttling Lenco', function (): void {
    /*
     * 240 a minute is generous enough to survive a redelivery burst after an
     * outage and tight enough that an unsigned flood cannot cost a database
     * write per request. The limiter is keyed on the address because a
     * webhook has no session and never will.
     */
    $limit = RateLimiter::limiter('webhooks')(request());

    expect($limit->maxAttempts)->toBe(240)
        ->and($limit->key)->not->toStartWith('user:');
});

it('throttles a payment verification without getting in a retrying buyer\'s way', function (): void {
    $order = Order::factory()->paid()->create();

    $this->actingAs($order->buyer);

    /*
     * Looser than checkout on purpose: a buyer whose mobile-money PIN times
     * out will legitimately try again, and the status page polls itself.
     */
    expect(RateLimiter::limiter('payments')(request())->maxAttempts)->toBe(30)
        ->and(RateLimiter::limiter('checkout')(request())->maxAttempts)->toBe(10);
});
