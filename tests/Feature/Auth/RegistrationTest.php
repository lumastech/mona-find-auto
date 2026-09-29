<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), registrationPayload());

    $this->assertAuthenticated();

    /* No SMS gateway yet, so the email notice is what registration hands over to. */
    $response->assertRedirect(route('verification.notice', absolute: false));

    expect(Auth::user()->email)->toBe('chanda@example.test')
        ->and(Auth::user()->name)->toBe('Chanda Mwale');
});
