<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Admin\Enums\StaffInvitationStatus;
use App\Modules\Admin\Models\StaffInvitation;
use App\Modules\Admin\Notifications\StaffInvitationNotification;
use App\Modules\Admin\Services\StaffDirectory;
use App\Modules\Identity\Enums\AccountStatus;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    Notification::fake();
});

it('sends an invitation and stores only a hash of its token', function () {
    $admin = actingAsStaff([Role::PlatformAdmin]);

    $this->post(route('admin.staff.invite'), [
        'email' => 'Chanda@MonaFind.zm',
        'name' => 'Chanda Mwale',
        'role' => Role::Moderator->value,
        'reason' => 'Joining the listing moderation team on Monday.',
    ])->assertRedirect();

    $invitation = StaffInvitation::query()->sole();

    expect($invitation->email)->toBe('chanda@monafind.zm')
        ->and($invitation->role)->toBe(Role::Moderator->value)
        ->and($invitation->invited_by)->toBe($admin->id)
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->isOpen())->toBeTrue();

    Notification::assertSentOnDemand(StaffInvitationNotification::class);
});

it('supersedes an earlier open invitation to the same address', function () {
    actingAsStaff([Role::PlatformAdmin]);

    foreach (['First go.', 'They never got the first one.'] as $reason) {
        $this->post(route('admin.staff.invite'), [
            'email' => 'chanda@monafind.zm',
            'name' => 'Chanda Mwale',
            'role' => Role::Moderator->value,
            'reason' => $reason,
        ]);
    }

    expect(StaffInvitation::query()->count())->toBe(2)
        ->and(StaffInvitation::query()->open()->count())->toBe(1);
});

it('grants the role when the invited person accepts', function () {
    $staff = app(StaffDirectory::class);
    $admin = actingAsStaff([Role::PlatformAdmin]);

    ['token' => $token] = $staff->invite('new@monafind.zm', 'New Starter', Role::Finance, $admin);

    $invitee = User::factory()->create(['email' => 'new@monafind.zm']);

    $this->actingAs($invitee)
        ->post(route('staff-invitations.accept', $token))
        ->assertRedirect(route('admin.dashboard'));

    expect($invitee->refresh()->hasRole(Role::Finance->value))->toBeTrue()
        ->and(StaffInvitation::query()->sole()->status())->toBe(StaffInvitationStatus::Accepted);
});

it('refuses an invitation forwarded to somebody else', function () {
    $staff = app(StaffDirectory::class);
    $admin = actingAsStaff([Role::PlatformAdmin]);

    ['token' => $token] = $staff->invite('invited@monafind.zm', 'Invited', Role::Moderator, $admin);

    $stranger = User::factory()->create(['email' => 'stranger@example.test']);

    $this->actingAs($stranger)
        ->post(route('staff-invitations.accept', $token))
        ->assertSessionHasErrors('token');

    expect($stranger->refresh()->hasRole(Role::Moderator->value))->toBeFalse();
});

it('refuses an expired invitation', function () {
    $invitee = User::factory()->create(['email' => 'late@monafind.zm']);

    StaffInvitation::factory()->expired()->create([
        'email' => 'late@monafind.zm',
        'token_hash' => hash('sha256', 'the-token'),
    ]);

    $this->actingAs($invitee)
        ->post(route('staff-invitations.accept', 'the-token'))
        ->assertNotFound();

    expect($invitee->refresh()->hasRole(Role::Moderator->value))->toBeFalse();
});

it('revokes an invitation nobody accepted', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $invitation = StaffInvitation::factory()->create();

    $this->post(route('admin.staff.invitations.revoke', $invitation), [
        'reason' => 'They took another job.',
    ])->assertRedirect();

    expect($invitation->refresh()->status())->toBe(StaffInvitationStatus::Revoked);
});

it('sets exactly the staff roles chosen, leaving other roles alone', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $member = User::factory()->create();
    $member->assignRole([Role::Buyer->value, Role::Moderator->value]);

    $this->put(route('admin.staff.roles', $member), [
        'roles' => [Role::Finance->value],
        'reason' => 'Moved from moderation to the finance desk.',
    ])->assertRedirect();

    $member->refresh();

    expect($member->hasRole(Role::Finance->value))->toBeTrue()
        ->and($member->hasRole(Role::Moderator->value))->toBeFalse()
        /* They still buy parts. That is not a staff role. */
        ->and($member->hasRole(Role::Buyer->value))->toBeTrue();
});

it('takes somebody off the console without deactivating their account', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $member = User::factory()->create();
    $member->assignRole(Role::Moderator->value);

    $this->put(route('admin.staff.roles', $member), [
        'roles' => [],
        'reason' => 'Changed jobs inside MonaFind.',
    ])->assertRedirect();

    expect($member->refresh()->hasAnyRole(Role::staffConsole()))->toBeFalse()
        ->and($member->status)->toBe(AccountStatus::Active);
});

it('records who changed whose roles, and why', function () {
    $admin = actingAsStaff([Role::PlatformAdmin]);

    $member = User::factory()->create();
    $member->assignRole(Role::Moderator->value);

    $this->put(route('admin.staff.roles', $member), [
        'roles' => [Role::Moderator->value, Role::Finance->value],
        'reason' => 'Covering the finance desk over the holidays.',
    ]);

    $entry = AuditLog::query()->where('action', 'staff.roles.changed')->sole();

    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->before['roles'])->toBe([Role::Moderator->value])
        ->and($entry->after['roles'])->toContain(Role::Finance->value)
        ->and($entry->reason)->toBe('Covering the finance desk over the holidays.');
});

it('deactivates a staff account and signs it out everywhere', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $member = User::factory()->create();
    $member->assignRole(Role::Moderator->value);

    $this->post(route('admin.staff.deactivate', $member), [
        'reason' => 'Left the company on Friday.',
    ])->assertRedirect();

    expect($member->refresh()->status)->toBe(AccountStatus::Suspended);
});

it('will not let the last platform administrator be removed', function () {
    $admin = actingAsStaff([Role::PlatformAdmin]);

    $this->put(route('admin.staff.roles', $admin), [
        'roles' => [],
        'reason' => 'Trying to lock everybody out.',
    ])->assertSessionHasErrors('reason');

    expect($admin->refresh()->hasRole(Role::PlatformAdmin->value))->toBeTrue();
});

it('allows the removal once somebody else holds the role', function () {
    $admin = actingAsStaff([Role::PlatformAdmin]);

    $successor = User::factory()->create();
    $successor->assignRole(Role::PlatformAdmin->value);

    $this->put(route('admin.staff.roles', $admin), [
        'roles' => [Role::Finance->value],
        'reason' => 'Handing the platform over.',
    ])->assertSessionHasNoErrors();

    expect($admin->refresh()->hasRole(Role::PlatformAdmin->value))->toBeFalse();
});

it('counts staff who have not enrolled in two-factor authentication', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $unenrolled = User::factory()->create();
    $unenrolled->assignRole(Role::Moderator->value);

    expect(app(StaffDirectory::class)->awaitingTwoFactor())->toBe(1);

    $this->get(route('admin.staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('twoFactorOutstanding', 1));
});

it('keeps a moderator out of staff management entirely', function () {
    actingAsStaff([Role::Moderator]);

    $this->get(route('admin.staff.index'))->assertForbidden();

    $this->post(route('admin.staff.invite'), [
        'email' => 'friend@example.test',
        'name' => 'A Friend',
        'role' => Role::PlatformAdmin->value,
        'reason' => 'Promoting myself a colleague.',
    ])->assertForbidden();

    expect(StaffInvitation::query()->count())->toBe(0);
});
