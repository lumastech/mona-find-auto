<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Mechanics\Enums\MechanicStatus;
use App\Modules\Mechanics\Exceptions\InvalidMechanicTransition;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicSpeciality;
use App\Modules\Mechanics\Notifications\MechanicApprovalDecided;
use App\Modules\Mechanics\Services\MechanicApprovalService;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Notification;

/*
 * Signing up, being reviewed, and what each of those grants. The rule that
 * matters here is the one that differs from a seller's: the mechanic role
 * arrives at approval, not at submission, because the only thing it unlocks
 * is asking shops to vouch for you.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->approval = app(MechanicApprovalService::class);
    $this->city = City::factory()->create();
    $this->specialities = MechanicSpeciality::factory()->count(2)->create();
    $this->buyer = User::factory()->create();
    /* Staff cannot reach the console without two-factor authentication. */
    $this->moderator = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function applicationPayload(array $overrides = []): array
{
    return [
        'display_name' => 'Chanda Mwale',
        'headline' => 'Gearbox and clutch specialist',
        'bio' => 'Fifteen years on Toyota and Nissan gearboxes.',
        'qualification' => 'TEVETA Craft Certificate — Automotive Mechanics',
        'qualification_institution' => 'Lusaka Trades Training Institute',
        'qualification_year' => 2011,
        'years_experience' => 15,
        'province_id' => test()->city->province_id,
        'city_id' => test()->city->id,
        'street' => 'Buyantanshi Road',
        'phone' => '0977123456',
        'email' => 'chanda@example.test',
        'is_mobile' => true,
        'accepting_work' => true,
        'speciality_ids' => test()->specialities->pluck('id')->all(),
        'work_history' => [[
            'employer' => 'Kabwata Motors',
            'role' => 'Senior mechanic',
            'started_on' => '2014-03-01',
            'is_current' => true,
        ]],
        'references' => [[
            'name' => 'Mutale Banda',
            'relationship' => 'Former employer',
            'phone' => '0966111222',
        ]],
        ...$overrides,
    ];
}

it('attaches a profile to an existing buyer account', function (): void {
    $this->actingAs($this->buyer)
        ->post(route('mechanics.apply.store'), applicationPayload())
        ->assertRedirect(route('mechanics.apply'));

    $profile = MechanicProfile::query()->firstOrFail();

    expect($profile->user_id)->toBe($this->buyer->getKey())
        ->and($profile->status)->toBe(MechanicStatus::Draft)
        ->and($profile->slug)->toBe('chanda-mwale')
        /* Stored E.164, whatever was typed. */
        ->and($profile->phone)->toBe('+260977123456')
        ->and($profile->specialities)->toHaveCount(2)
        ->and($profile->workHistory)->toHaveCount(1)
        ->and($profile->workHistory->first()->ended_on)->toBeNull()
        ->and($profile->references)->toHaveCount(1);

    /* No role yet: a draft profile unlocks nothing. */
    expect($this->buyer->fresh()->hasRole(Role::Mechanic->value))->toBeFalse();

    $this->assertDatabaseHas('audit_logs', ['action' => 'mechanic.profile.saved']);
});

it('requires at least one speciality', function (): void {
    $this->actingAs($this->buyer)
        ->post(route('mechanics.apply.store'), applicationPayload(['speciality_ids' => []]))
        ->assertSessionHasErrors('speciality_ids');

    expect(MechanicProfile::query()->count())->toBe(0);
});

it('drops a speciality staff have retired', function (): void {
    $retired = MechanicSpeciality::factory()->retired()->create();

    $this->actingAs($this->buyer)
        ->post(route('mechanics.apply.store'), applicationPayload([
            'speciality_ids' => [...$this->specialities->pluck('id')->all(), $retired->getKey()],
        ]))
        ->assertSessionHasErrors('speciality_ids.2');
});

it('will not be edited once it has been sent', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->submitted()->create();

    $this->actingAs($profile->user)
        ->post(route('mechanics.apply.store'), applicationPayload())
        ->assertForbidden();
});

it('reopens for editing after a rejection, and clears the old reason', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->rejected()->create();

    expect($profile->rejection_reason)->not->toBeNull();

    $this->actingAs($profile->user)
        ->post(route('mechanics.apply.store'), applicationPayload())
        ->assertRedirect();

    expect($profile->refresh()->rejection_reason)->toBeNull()
        ->and($profile->status)->toBe(MechanicStatus::Rejected);
});

it('refuses to be submitted without enough to review', function (): void {
    $profile = MechanicProfile::factory()->draft()->create();

    expect(fn () => $this->approval->submit($profile, $profile->user))
        ->toThrow(InvalidMechanicTransition::class);

    expect($profile->refresh()->status)->toBe(MechanicStatus::Draft);
});

it('submits a complete profile into the staff queue', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->draft()->create();

    $this->actingAs($profile->user)
        ->post(route('mechanics.apply.submit'))
        ->assertRedirect(route('mechanics.apply'));

    expect($profile->refresh()->status)->toBe(MechanicStatus::Submitted)
        ->and($profile->submitted_at)->not->toBeNull()
        ->and(MechanicProfile::query()->inApprovalQueue()->count())->toBe(1);
});

it('grants the mechanic role at approval, not at submission', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->draft()->create();
    $moderator = $this->moderator;
    $this->actingAs($moderator);

    $this->approval->submit($profile, $profile->user);
    expect($profile->user->fresh()->hasRole(Role::Mechanic->value))->toBeFalse();

    $this->approval->approve($profile->refresh(), $moderator);

    expect($profile->refresh()->status)->toBe(MechanicStatus::Approved)
        ->and($profile->approved_by)->toBe($moderator->getKey())
        ->and($profile->user->fresh()->hasRole(Role::Mechanic->value))->toBeTrue();

    Notification::assertSentTo($profile->user, MechanicApprovalDecided::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'mechanic.approved']);
});

it('takes the role away on suspension and gives it back on reinstatement', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->create();
    $moderator = $this->moderator;
    $this->actingAs($moderator);

    expect($profile->user->fresh()->hasRole(Role::Mechanic->value))->toBeTrue();

    $this->approval->suspend($profile, $moderator, 'Repeated complaints from buyers.');

    expect($profile->refresh()->status)->toBe(MechanicStatus::Suspended)
        ->and($profile->user->fresh()->hasRole(Role::Mechanic->value))->toBeFalse();

    $this->approval->reinstate($profile, $moderator, 'Complaints resolved.');

    expect($profile->refresh()->status)->toBe(MechanicStatus::Approved)
        ->and($profile->user->fresh()->hasRole(Role::Mechanic->value))->toBeTrue();
});

it('refuses an illegal move however the URL was reached', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->draft()->create();
    $moderator = $this->moderator;
    $this->actingAs($moderator);

    /* Draft → Approved is not a legal move; it has to be submitted first. */
    expect(fn () => $this->approval->approve($profile, $moderator))
        ->toThrow(InvalidMechanicTransition::class);

    $this->post(route('admin.mechanics.approve', $profile))->assertSessionHasErrors('status');

    expect($profile->refresh()->status)->toBe(MechanicStatus::Draft);
});

it('rejects with a reason the applicant can read', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->submitted()->create();
    $this->actingAs($this->moderator);

    $this->post(route('admin.mechanics.reject', $profile), [
        'reason' => 'We could not confirm the certificate with the college you named.',
    ])->assertRedirect(route('admin.mechanics.show', $profile));

    expect($profile->refresh()->status)->toBe(MechanicStatus::Rejected)
        ->and($profile->rejection_reason)->toContain('could not confirm');
});

it('requires a reason to reject', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->submitted()->create();
    $this->actingAs($this->moderator);

    $this->post(route('admin.mechanics.reject', $profile), ['reason' => 'no'])
        ->assertSessionHasErrors('reason');

    expect($profile->refresh()->status)->toBe(MechanicStatus::Submitted);
});

it('refuses approval to somebody who is not staff', function (): void {
    $profile = MechanicProfile::factory()->withSpecialities()->submitted()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('admin.mechanics.approve', $profile))
        ->assertForbidden();

    expect($profile->refresh()->status)->toBe(MechanicStatus::Submitted);
});
