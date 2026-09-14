<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    /* The session list reads the sessions table, which only the database driver writes. */
    config(['session.driver' => 'database']);
});

/**
 * Put a browser session for an account into the session table.
 */
function seedSession(User $user, string $id, string $userAgent = 'Mozilla/5.0 (Linux; Android 13) Chrome/120'): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => $userAgent,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

it('lists the devices signed in to an account', function () {
    $user = User::factory()->create();
    seedSession($user, 'android-session');
    seedSession($user, 'laptop-session', 'Mozilla/5.0 (Macintosh) Safari/17');

    $this->actingAs($user)
        ->get(route('sessions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Sessions')
            ->has('sessions', 2)
            ->where('sessions.0.device', 'Chrome on Android')
            ->where('sessions.1.device', 'Safari on macOS'));
});

it('shows an account only its own devices', function () {
    $user = User::factory()->create();
    seedSession($user, 'my-session');
    seedSession(User::factory()->create(), 'somebody-elses-session');

    $this->actingAs($user)
        ->get(route('sessions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('sessions', 1));
});

it('signs one device out', function () {
    $user = User::factory()->create();
    seedSession($user, 'android-session');

    $this->actingAs($user)
        ->delete(route('sessions.destroy', 'android-session'))
        ->assertRedirect(route('sessions.index'));

    expect(DB::table('sessions')->where('id', 'android-session')->exists())->toBeFalse();
});

it('will not let one account sign another account\'s device out', function () {
    $stranger = User::factory()->create();
    seedSession($stranger, 'their-session');

    $this->actingAs(User::factory()->create())
        ->delete(route('sessions.destroy', 'their-session'));

    expect(DB::table('sessions')->where('id', 'their-session')->exists())->toBeTrue();
});

it('signs every other device out and drops the API tokens with them', function () {
    $user = User::factory()->create();
    seedSession($user, 'android-session');
    seedSession($user, 'laptop-session');
    $user->createToken('old phone');

    $this->actingAs($user)
        ->delete(route('sessions.destroy-others'))
        ->assertRedirect(route('sessions.index'));

    /* The session making the request is deliberately spared. */
    expect(DB::table('sessions')->whereIn('id', ['android-session', 'laptop-session'])->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);
});

it('keeps the device list behind a login', function () {
    $this->get(route('sessions.index'))->assertRedirect(route('login'));
});
