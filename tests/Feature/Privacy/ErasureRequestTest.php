<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Privacy\Enums\ErasureStatus;
use App\Modules\Privacy\Jobs\ProcessDueErasures;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Notifications\ErasureScheduledNotification;
use App\Modules\Privacy\Services\AccountEraser;
use App\Modules\Privacy\Services\ErasureGuard;
use App\Modules\Privacy\Services\ErasureRequestService;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
});

/**
 * The payload the settings form posts. The password is re-checked because
 * this is the one irreversible action on the platform.
 *
 * @return array<string, mixed>
 */
function erasurePayload(array $overrides = []): array
{
    return ['password' => 'password', 'confirm' => true, ...$overrides];
}

it('schedules the erasure for the end of the grace period and says so', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => 'password']);

    $this->actingAs($user)
        ->post(route('privacy.erasure.store'), erasurePayload(['reason' => 'Moving abroad.']))
        ->assertSessionHasNoErrors();

    $request = ErasureRequest::query()->sole();

    expect($request->status)->toBe(ErasureStatus::Pending)
        ->and($request->reason)->toBe('Moving abroad.')
        ->and($request->erase_after->isAfter(now()->addDays(13)))->toBeTrue();

    Notification::assertSentTo($user, ErasureScheduledNotification::class);
});

it('re-checks the password, because this cannot be undone', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    $this->actingAs($user)
        ->post(route('privacy.erasure.store'), erasurePayload(['password' => 'not-the-password']))
        ->assertSessionHasErrors('password');

    expect(ErasureRequest::query()->count())->toBe(0);
});

it('will not proceed without an explicit confirmation', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    $this->actingAs($user)
        ->post(route('privacy.erasure.store'), erasurePayload(['confirm' => false]))
        ->assertSessionHasErrors('confirm');

    expect(ErasureRequest::query()->count())->toBe(0);
});

it('does not open a second request for somebody who asks twice', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => 'password']);

    app(ErasureRequestService::class)->request($user);
    app(ErasureRequestService::class)->request($user);

    expect(ErasureRequest::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('lets the account holder call it off inside the grace period', function (): void {
    $user = User::factory()->create();
    $request = ErasureRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('privacy.erasure.cancel', $request))
        ->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(ErasureStatus::Cancelled);
});

it('does not let anybody else call it off, staff included', function (): void {
    $owner = User::factory()->create();
    $request = ErasureRequest::factory()->create(['user_id' => $owner->id]);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)
        ->delete(route('privacy.erasure.cancel', $request))
        ->assertForbidden();

    /*
     * The asymmetry the module turns on: staff may hold a request while an
     * order settles, but an erasure staff could cancel would not be a right.
     */
    $moderator = actingAsStaff([Role::Moderator]);
    $this->actingAs($moderator)
        ->delete(route('privacy.erasure.cancel', $request))
        ->assertForbidden();

    expect($request->refresh()->status)->toBe(ErasureStatus::Pending);
});

it('holds an erasure while an order is still in flight, then lets it through', function (): void {
    Notification::fake();

    $order = Order::factory()->paid()->create(['completed_at' => null, 'cancelled_at' => null, 'closed_at' => null]);
    $user = $order->buyer;

    $request = ErasureRequest::factory()->due()->create(['user_id' => $user->id]);

    (new ProcessDueErasures)->handle(
        app(AccountEraser::class),
        app(ErasureGuard::class),
    );

    $request->refresh();

    expect($request->status)->toBe(ErasureStatus::Blocked)
        ->and($request->blocked_reason)->toContain('still in progress')
        ->and($user->refresh()->email)->not->toContain('erased-');
});

it('erases an account whose grace period has run out and nothing is pending', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $user->assignRole(Role::Buyer->value);

    $request = ErasureRequest::factory()->due()->create(['user_id' => $user->id]);

    (new ProcessDueErasures)->handle(
        app(AccountEraser::class),
        app(ErasureGuard::class),
    );

    expect($request->refresh()->status)->toBe(ErasureStatus::Completed)
        ->and($user->refresh()->email)->toBe("erased-{$user->id}@erased.invalid");
});

it('leaves a request alone until its grace period is over', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $request = ErasureRequest::factory()->create(['user_id' => $user->id]);

    (new ProcessDueErasures)->handle(
        app(AccountEraser::class),
        app(ErasureGuard::class),
    );

    expect($request->refresh()->status)->toBe(ErasureStatus::Pending)
        ->and($user->refresh()->email)->not->toContain('erased-');
});

it('shows staff what is coming and lets them hold one', function (): void {
    $moderator = actingAsStaff([Role::Moderator]);
    $request = ErasureRequest::factory()->create();

    $this->actingAs($moderator)
        ->get(route('admin.privacy.erasure-requests.index'))
        ->assertOk();

    $this->actingAs($moderator)
        ->post(route('admin.privacy.erasure-requests.block', $request), [
            'reason' => 'Open dispute on order MF-7QK4ZP2A.',
        ])
        ->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(ErasureStatus::Blocked)
        ->and($request->blocked_by)->toBe($moderator->id);
});

it('warns a person up front about what is holding their deletion up', function (): void {
    $order = Order::factory()->paid()->create(['completed_at' => null, 'cancelled_at' => null, 'closed_at' => null]);

    $this->actingAs($order->buyer)
        ->get(route('privacy.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Privacy')
            ->where('blockers.0', fn (string $reason): bool => str_contains($reason, 'still in progress')));
});
