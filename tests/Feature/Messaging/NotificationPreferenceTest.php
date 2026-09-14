<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Models\NotificationPreference;
use App\Modules\Messaging\Services\NotificationMatrix;
use App\Modules\Messaging\Services\NotificationPreferenceService;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->user = User::factory()->create(['phone_verified_at' => now()]);
});

/** A staff account that has actually confirmed two-factor, as the area requires. */
function staffWithTwoFactor(Role $role): User
{
    SpatieRole::findOrCreate($role->value, 'web');

    $staff = User::factory()->withTwoFactor()->create();
    $staff->assignRole($role->value);

    return $staff;
}

/*
|--------------------------------------------------------------------------
| The preference screen
|--------------------------------------------------------------------------
*/

it('shows a person what they will and will not be told about', function (): void {
    $this->actingAs($this->user)
        ->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Notifications')
            ->where('reachable.sms', true)
            ->has('groups'));
});

it('shows a security event as fixed rather than as a switch', function (): void {
    $screen = app(NotificationPreferenceService::class)
        ->screenFor($this->user);

    $security = collect($screen)->firstWhere('group', 'Security');
    $otp = collect($security['events'])->firstWhere('event', NotificationEvent::Otp->value);

    expect($otp['mandatory'])->toBeTrue()
        ->and(collect($otp['channels'])->every(fn (array $channel): bool => $channel['locked']))
        ->toBeTrue();
});

it('shows a channel the matrix has switched off as unavailable', function (): void {
    app(NotificationMatrix::class)->replace([
        NotificationEvent::OrderStateChanged->value => [NotificationChannel::Mail->value],
    ], null, 'Testing.');

    $screen = app(NotificationPreferenceService::class)
        ->screenFor($this->user);

    $event = collect($screen)
        ->flatMap(fn (array $group): array => $group['events'])
        ->firstWhere('event', NotificationEvent::OrderStateChanged->value);

    $sms = collect($event['channels'])->firstWhere('channel', NotificationChannel::Sms->value);

    expect($sms['available'])->toBeFalse()
        ->and($sms['enabled'])->toBeFalse()
        ->and($sms['locked'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Saving
|--------------------------------------------------------------------------
*/

/*
 * OrderPlaced rather than OrderStateChanged throughout: "new order" is one of
 * the handful of events the default matrix spends an SMS on, so there is a
 * text switch to turn off. On an event the matrix never sends by SMS the
 * service correctly writes nothing, which would make this test pass for the
 * wrong reason.
 */
it('stores what a person switched off', function (): void {
    $this->actingAs($this->user)
        ->from(route('settings.notifications.edit'))
        ->put(route('settings.notifications.update'), [
            'preferences' => [
                NotificationEvent::OrderPlaced->value => [
                    NotificationChannel::Sms->value => false,
                    NotificationChannel::Mail->value => true,
                ],
            ],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $this->user->id,
        'event' => NotificationEvent::OrderPlaced->value,
        'channel' => NotificationChannel::Sms->value,
        'enabled' => 0,
    ]);

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $this->user->id,
        'channel' => NotificationChannel::Mail->value,
        'enabled' => 1,
    ]);
});

it('writes no row for a mandatory event, whatever is posted', function (): void {
    $this->actingAs($this->user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [
                NotificationEvent::Otp->value => [
                    NotificationChannel::Sms->value => false,
                ],
            ],
        ]);

    $this->assertDatabaseMissing('notification_preferences', [
        'user_id' => $this->user->id,
        'event' => NotificationEvent::Otp->value,
    ]);
});

it('writes no row for a channel the matrix does not allow', function (): void {
    app(NotificationMatrix::class)->replace([
        NotificationEvent::OrderStateChanged->value => [NotificationChannel::Mail->value],
    ], null, 'Testing.');

    $this->actingAs($this->user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [
                NotificationEvent::OrderStateChanged->value => [
                    NotificationChannel::Sms->value => false,
                ],
            ],
        ]);

    $this->assertDatabaseMissing('notification_preferences', [
        'user_id' => $this->user->id,
        'channel' => NotificationChannel::Sms->value,
    ]);
});

it('lets somebody switch a channel back on', function (): void {
    NotificationPreference::factory()->muted()->create([
        'user_id' => $this->user->id,
        'event' => NotificationEvent::OrderPlaced,
        'channel' => NotificationChannel::Sms,
    ]);

    $this->actingAs($this->user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [
                NotificationEvent::OrderPlaced->value => [
                    NotificationChannel::Sms->value => true,
                ],
            ],
        ]);

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $this->user->id,
        'channel' => NotificationChannel::Sms->value,
        'enabled' => 1,
    ]);
});

it('keeps one person\'s choices out of another\'s', function (): void {
    $other = User::factory()->create();

    NotificationPreference::factory()->muted()->create([
        'user_id' => $other->id,
        'event' => NotificationEvent::OrderStateChanged,
        'channel' => NotificationChannel::Sms,
    ]);

    expect(NotificationPreference::mutedKeysFor($this->user))->toBeEmpty()
        ->and(NotificationPreference::mutedKeysFor($other))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| The admin matrix screen
|--------------------------------------------------------------------------
*/

it('lets an administrator open the routing grid', function (): void {
    $this->actingAs(staffWithTwoFactor(Role::PlatformAdmin));

    $this->get(route('admin.notifications.matrix.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/notifications/Matrix')
            ->has('matrix')
            ->has('groups'));
});

it('keeps a moderator out of the routing grid', function (): void {
    $this->actingAs(staffWithTwoFactor(Role::Moderator));

    $this->get(route('admin.notifications.matrix.edit'))->assertForbidden();
});

it('will not save a routing change without a reason', function (): void {
    $this->actingAs(staffWithTwoFactor(Role::PlatformAdmin));

    $this->from(route('admin.notifications.matrix.edit'))
        ->put(route('admin.notifications.matrix.update'), [
            'matrix' => [
                NotificationEvent::OrderPlaced->value => [NotificationChannel::Mail->value],
            ],
        ])
        ->assertSessionHasErrors('reason');
});

it('saves a routing change and takes the channel out of circulation', function (): void {
    $this->actingAs(staffWithTwoFactor(Role::PlatformAdmin));

    $this->put(route('admin.notifications.matrix.update'), [
        'matrix' => [
            NotificationEvent::OrderPlaced->value => [NotificationChannel::Mail->value],
        ],
        'reason' => 'SMS spend is over budget this month.',
    ])->assertRedirect();

    expect(app(NotificationMatrix::class)->allows(
        NotificationEvent::OrderPlaced,
        NotificationChannel::Sms,
    ))->toBeFalse();
});
