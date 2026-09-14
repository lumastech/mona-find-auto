<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * Moderator and finance are separate jobs, and the console keeps them apart.
 *
 * The separation is asserted at both layers on purpose. A route test alone
 * would pass if somebody later added a second way in; a policy test alone
 * would pass if a controller forgot to ask. The pair is what makes the
 * statement "a moderator cannot reach finance" true rather than merely
 * currently true.
 */
beforeEach(function () {
    $this->seed(SettingsSeeder::class);
});

it('refuses every finance screen to a moderator', function (string $route) {
    actingAsStaff([Role::Moderator]);

    $this->get($route)->assertForbidden();
})->with([
    'the ledger' => fn () => route('admin.finance.ledger.index'),
    'monetisation policies' => fn () => route('admin.finance.policies.index'),
    'manual adjustments' => fn () => route('admin.finance.adjustments.index'),
    'payout batches' => fn () => route('admin.payouts.index'),
    'the refund queue' => fn () => route('admin.refunds.index'),
    'reconciliation' => fn () => route('admin.reconciliation.index'),
]);

it('refuses every moderation DECISION to finance', function (string $route) {
    actingAsStaff([Role::Finance]);

    $this->post($route, ['reason' => 'Finance has no business deciding this.'])
        ->assertForbidden();
})->with([
    'publishing a listing' => fn () => route('admin.listings.publish', Product::factory()->pendingReview()->create()),
    'verifying a seller' => fn () => route('admin.sellers.verify', Seller::factory()->create()),
    'approving a mechanic' => fn () => route('admin.mechanics.approve', MechanicProfile::factory()->submitted()->create()),
    'hiding a review' => fn () => route('admin.ratings.hide', Rating::factory()->create()),
]);

/*
 * Reading is a different question from deciding, and the platform answers it
 * differently on purpose. A finance staffer chasing a refund has to be able to
 * open the listing it was for and the shop that sold it; what they cannot do
 * is publish, verify or approve anything. The queue screens are therefore
 * readable by every staff role and their decision endpoints are not — see the
 * test above, which is the half that actually holds the line.
 */
it('still lets finance read the moderation queues', function (string $route) {
    actingAsStaff([Role::Finance]);

    $this->get($route)->assertOk();
})->with([
    'the listing queue' => fn () => route('admin.listings.index'),
    'the seller queue' => fn () => route('admin.sellers.index'),
    'the mechanic queue' => fn () => route('admin.mechanics.index'),
]);

/* The review queue is the exception: it is gated by `moderate` outright. */
it('keeps finance out of the review queue entirely', function () {
    actingAsStaff([Role::Finance]);

    $this->get(route('admin.ratings.index'))->assertForbidden();
    $this->get(route('admin.trust.index'))->assertForbidden();
});

it('refuses the platform screens to both of them', function (Role $role, string $route) {
    actingAsStaff([$role]);

    $this->get($route)->assertForbidden();
})->with([Role::Moderator, Role::Finance])->with([
    'settings' => fn () => route('admin.settings.index'),
    'staff' => fn () => route('admin.staff.index'),
]);

it('opens the dashboard and the audit trail to every staff role', function (Role $role) {
    actingAsStaff([$role]);

    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('admin.audit.index'))->assertOk();
})->with([Role::Moderator, Role::Finance, Role::PlatformAdmin]);

it('gates each ability at the role that owns it', function () {
    $moderator = actingAsStaff([Role::Moderator]);
    $finance = actingAsStaff([Role::Finance]);
    $admin = actingAsStaff([Role::PlatformAdmin]);

    /* Moderation. */
    expect(Gate::forUser($moderator)->allows('moderate'))->toBeTrue()
        ->and(Gate::forUser($finance)->allows('moderate'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('moderate'))->toBeTrue();

    /* Money. */
    expect(Gate::forUser($finance)->allows('finance'))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('finance'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('finance'))->toBeTrue();

    /* The platform itself. */
    expect(Gate::forUser($admin)->allows('admin-only'))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('admin-only'))->toBeFalse()
        ->and(Gate::forUser($finance)->allows('admin-only'))->toBeFalse();
});

it('lets a moderator curate reference data but never merge it', function () {
    $moderator = actingAsStaff([Role::Moderator]);
    $admin = actingAsStaff([Role::PlatformAdmin]);
    $finance = actingAsStaff([Role::Finance]);

    expect(Gate::forUser($moderator)->allows('curate-reference-data-manage'))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('merge-reference-data'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('merge-reference-data'))->toBeTrue()
        ->and(Gate::forUser($finance)->allows('curate-reference-data-manage'))->toBeFalse()
        /* Everybody on the console can READ the lists; only two may write. */
        ->and(Gate::forUser($finance)->allows('curate-reference-data'))->toBeTrue();
});

it('refuses a moderator the finance actions, not merely the screens', function () {
    actingAsStaff([Role::Moderator]);

    $batch = PayoutBatch::factory()->create();

    $this->post(route('admin.payouts.approve', $batch), ['reason' => 'Not my job.'])
        ->assertForbidden();
});

/*
 * Disputes are deliberately open to both. Resolving one releases escrow or
 * refunds a buyer, so it is as much a money decision as a moderation one, and
 * a platform where only moderators could settle them would have finance
 * watching money move on somebody else's judgement.
 */
it('leaves the dispute queue open to both jobs', function (Role $role) {
    actingAsStaff([$role]);

    $this->get(route('admin.disputes.index'))->assertOk();
})->with([Role::Moderator, Role::Finance]);
