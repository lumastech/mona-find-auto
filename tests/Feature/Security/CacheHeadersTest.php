<?php

declare(strict_types=1);

use App\Models\User;

it('never lets an authenticated page be stored', function (): void {
    $user = User::factory()->create();

    /*
     * A signed-in page carries the buyer's name, their cart count and a
     * seller's phone number. None of that may sit in any cache.
     */
    $header = (string) $this->actingAs($user)
        ->get(route('dashboard'))
        ->headers->get('Cache-Control');

    /*
     * Asserted by content rather than by string: Symfony normalises and
     * re-orders the directives, and the order is not what is being promised.
     */
    expect($header)->toContain('private')->toContain('no-store');
});

it('gives a guest page a short browser-level window', function (): void {
    $response = $this->get(route('home'));

    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('max-age=30')
        /* Off unless a deployment has promised its CDN strips session cookies. */
        ->not->toContain('s-maxage');
});

it('only goes public when a deployment opts in', function (): void {
    config()->set('security.cache.shared_seconds', 60);

    expect((string) $this->get(route('home'))->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('s-maxage=60')
        ->toContain('stale-while-revalidate=300');
});

it('varies on the header that separates a page from an Inertia partial', function (): void {
    $vary = (string) $this->get(route('home'))->headers->get('Vary');

    expect($vary)->toContain('X-Inertia')->toContain('Accept');
});

it('does not cache a response carrying validation errors', function (): void {
    config()->set('security.cache.shared_seconds', 60);

    $this->from(route('home'))
        ->post(route('login'), ['email' => 'nobody@example.test', 'password' => 'wrong']);

    /* The redirect target now carries flash data about one visitor. */
    expect((string) $this->get(route('home'))->headers->get('Cache-Control'))
        ->toContain('no-store');
});

it('does not cache a POST', function (): void {
    $header = (string) $this->post(route('login'), [])->headers->get('Cache-Control');

    expect($header)->toContain('private')->toContain('no-store');
});
