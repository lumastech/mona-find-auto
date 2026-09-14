<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Events\AccountStatusChanged;
use App\Modules\Identity\Notifications\AccountStatusChangedNotification;
use App\Modules\Identity\Notifications\AccountWarnedNotification;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

function staffMember(Role $role = Role::Moderator): User
{
    return User::factory()->withTwoFactor()->withRole($role)->create();
}

it('records a warning without changing the account status', function () {
    Notification::fake();

    $staff = staffMember();
    $subject = User::factory()->create();

    $this->actingAs($staff)
        ->post(route('admin.users.warn', $subject), ['reason' => 'Listing photos taken from another shop.'])
        ->assertRedirect(route('admin.users.show', $subject));

    expect($subject->refresh()->status)->toBe(AccountStatus::Active);

    Notification::assertSentTo($subject, AccountWarnedNotification::class);

    expect(AuditLog::query()->where('action', 'user.warned')->sole())
        ->reason->toBe('Listing photos taken from another shop.');
});

it('suspends an account, records why, and tells the account holder', function () {
    Notification::fake();

    $staff = staffMember();
    $subject = User::factory()->create();

    $this->actingAs($staff)
        ->post(route('admin.users.suspend', $subject), ['reason' => 'Selling counterfeit brake pads.'])
        ->assertRedirect(route('admin.users.show', $subject));

    $subject->refresh();

    expect($subject->status)->toBe(AccountStatus::Suspended)
        ->and($subject->status_reason)->toBe('Selling counterfeit brake pads.')
        ->and($subject->status_changed_at)->not->toBeNull();

    Notification::assertSentTo($subject, AccountStatusChangedNotification::class);

    $entry = AuditLog::query()->where('action', 'user.suspended')->sole();

    expect($entry->actor_id)->toBe($staff->id)
        ->and($entry->before['status'])->toBe('active')
        ->and($entry->after['status'])->toBe('suspended');
});

it('reinstates a suspended account', function () {
    Notification::fake();

    $subject = User::factory()->suspended()->create();

    $this->actingAs(staffMember())
        ->post(route('admin.users.reinstate', $subject), ['reason' => 'Appeal upheld; listings corrected.']);

    expect($subject->refresh()->status)->toBe(AccountStatus::Active);
    expect(AuditLog::query()->where('action', 'user.reinstated')->exists())->toBeTrue();
});

it('closes an account for good', function () {
    Notification::fake();

    $subject = User::factory()->create();

    $this->actingAs(staffMember())
        ->post(route('admin.users.close', $subject), ['reason' => 'Closed at the account holder request.']);

    expect($subject->refresh()->status)->toBe(AccountStatus::Closed);
});

it('announces a status change so other modules can react', function () {
    Notification::fake();

    $subject = User::factory()->create();
    $staff = staffMember();

    Event::fake([AccountStatusChanged::class]);

    $this->actingAs($staff)
        ->post(route('admin.users.suspend', $subject), ['reason' => 'Selling counterfeit brake pads.']);

    Event::assertDispatched(
        AccountStatusChanged::class,
        fn (AccountStatusChanged $event): bool => $event->user->is($subject)
            && $event->from === AccountStatus::Active
            && $event->to === AccountStatus::Suspended
            && $event->actor->is($staff),
    );
});

it('insists on a reason staff can be held to', function (array $payload, string $expected) {
    $subject = User::factory()->create();

    $this->actingAs(staffMember())
        ->post(route('admin.users.suspend', $subject), $payload)
        ->assertSessionHasErrors(['reason' => $expected]);

    expect($subject->refresh()->status)->toBe(AccountStatus::Active);
})->with([
    'missing' => [[], 'Record why you are taking this action.'],
    'too vague' => [['reason' => 'spam'], 'Give a reason somebody reviewing this later can act on.'],
]);

it('refuses moderation to an account that is not staff', function () {
    $subject = User::factory()->create();

    $this->actingAs(User::factory()->withRole(Role::Seller)->create())
        ->post(route('admin.users.suspend', $subject), ['reason' => 'Selling counterfeit brake pads.'])
        ->assertForbidden();
});

it('sends a guest to the login screen rather than moderating', function () {
    $this->post(route('admin.users.suspend', User::factory()->create()), ['reason' => 'Selling counterfeit brake pads.'])
        ->assertRedirect(route('login'));
});
