<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mechanics\Enums\MechanicStatus;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Inertia\Testing\AssertableInertia;

/*
 * The rule under test: a profile that MonaFind has not approved is not
 * visible anywhere. Not "shown greyed out", not "listed without a badge" —
 * absent.
 *
 * Asserted on every surface separately, because each one could leak it on its
 * own: the directory page, the profile page, the JSON list, the JSON profile,
 * and the endorsement badge that would name a shop on an unapproved page.
 */

/**
 * @param  array<string, mixed>  $attributes
 */
function mechanicInStatus(MechanicStatus $status, array $attributes = []): MechanicProfile
{
    return MechanicProfile::factory()
        ->withSpecialities()
        ->create(['status' => $status, ...$attributes]);
}

it('lists only approved mechanics in the directory', function (): void {
    $approved = mechanicInStatus(MechanicStatus::Approved, ['display_name' => 'Chanda Mwale']);

    foreach ([MechanicStatus::Draft, MechanicStatus::Submitted, MechanicStatus::UnderReview, MechanicStatus::Rejected, MechanicStatus::Suspended] as $status) {
        mechanicInStatus($status, ['display_name' => 'Hidden '.$status->value]);
    }

    $this->get(route('mechanics.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('mechanics.data', 1)
            ->where('mechanics.data.0.display_name', 'Chanda Mwale')
        );

    expect(MechanicProfile::query()->count())->toBe(6);
});

it('404s an unapproved profile page, even for its own author', function (MechanicStatus $status): void {
    $profile = mechanicInStatus($status);

    $this->get(route('mechanics.show', $profile))->assertNotFound();

    /* Its author reads it on the application page, not the public one. */
    $this->actingAs($profile->user)
        ->get(route('mechanics.show', $profile))
        ->assertNotFound();
})->with([
    'draft' => MechanicStatus::Draft,
    'submitted' => MechanicStatus::Submitted,
    'under review' => MechanicStatus::UnderReview,
    'rejected' => MechanicStatus::Rejected,
    'suspended' => MechanicStatus::Suspended,
]);

it('shows an approved profile to a guest', function (): void {
    $profile = mechanicInStatus(MechanicStatus::Approved);

    $this->get(route('mechanics.show', $profile))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mechanic.display_name', $profile->display_name)
            ->where('mechanic.approved', true)
        );
});

it('keeps unapproved mechanics out of the API, list and profile alike', function (): void {
    $hidden = mechanicInStatus(MechanicStatus::Submitted);
    $approved = mechanicInStatus(MechanicStatus::Approved);

    $this->getJson(route('api.v1.mechanics.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $approved->getKey());

    $this->getJson(route('api.v1.mechanics.show', $hidden))->assertNotFound();
    $this->getJson(route('api.v1.mechanics.show', $approved))->assertOk();
});

it('cannot be asked for unapproved profiles through the query string', function (): void {
    mechanicInStatus(MechanicStatus::Submitted);

    /*
     * The directory takes no status filter. This asserts the scope rather
     * than the absence of a parameter: a filter somebody adds later must not
     * be able to widen what comes back.
     */
    $this->getJson(route('api.v1.mechanics.index', ['status' => 'submitted']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('takes a suspended mechanic and their endorsements off the directory', function (): void {
    $profile = mechanicInStatus(MechanicStatus::Approved);
    $seller = Seller::factory()->create(['business_name' => 'Kabwata Motors']);
    MechanicEndorsement::factory()->between($profile, $seller)->create();

    $this->getJson(route('api.v1.mechanics.index'))
        ->assertOk()
        ->assertJsonPath('data.0.endorsements.0.label', 'Endorsed by Kabwata Motors');

    $profile->forceFill(['status' => MechanicStatus::Suspended])->save();

    $this->getJson(route('api.v1.mechanics.index'))->assertJsonCount(0, 'data');
    $this->getJson(route('api.v1.mechanics.show', $profile))->assertNotFound();

    /* The endorsement row survives, so reinstating restores the badge. */
    expect(MechanicEndorsement::query()->count())->toBe(1);
});

it('shows staff every application through the console', function (): void {
    $hidden = mechanicInStatus(MechanicStatus::Submitted);

    $this->actingAs(User::factory()->withTwoFactor()->withRole(Role::Moderator)->create());

    $this->get(route('admin.mechanics.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('mechanics.data', 1));

    $this->get(route('admin.mechanics.show', $hidden))->assertOk();
});

it('refuses the staff console to an ordinary account', function (): void {
    $profile = mechanicInStatus(MechanicStatus::Submitted);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.mechanics.show', $profile))
        ->assertForbidden();
});
