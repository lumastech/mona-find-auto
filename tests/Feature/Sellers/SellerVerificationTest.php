<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Events\SellerVerificationChanged;
use App\Modules\Sellers\Exceptions\InvalidVerificationTransition;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerVerificationService;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->verification = app(SellerVerificationService::class);
    /* Staff cannot reach the console without two-factor authentication. */
    $this->moderator = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create();
});

it('walks an application from submitted through to verified', function () {
    $seller = Seller::factory()->submitted()->create();

    $this->verification->beginReview($seller, $this->moderator, 'Documents look complete.');
    expect($seller->refresh()->verification_status)->toBe(VerificationStatus::UnderReview);

    $this->verification->scheduleInspection($seller, $this->moderator, now()->addDays(3));
    expect($seller->refresh()->verification_status)->toBe(VerificationStatus::InspectionScheduled)
        ->and($seller->inspection_scheduled_for)->not->toBeNull();

    $this->verification->verify($seller, $this->moderator, 'Premises seen, stock as described.');

    expect($seller->refresh()->verification_status)->toBe(VerificationStatus::Verified)
        ->and($seller->verified_at)->not->toBeNull()
        ->and($seller->verified_by)->toBe($this->moderator->id);
});

it('refuses to grant the badge without a registration number', function () {
    $seller = Seller::factory()->submitted()->withoutRegistrationNumber()->create();

    expect(fn () => $this->verification->verify($seller, $this->moderator))
        ->toThrow(InvalidVerificationTransition::class, 'Add the business registration number');

    expect($seller->refresh()->verification_status)->toBe(VerificationStatus::Submitted);
});

it('grants the badge once the registration number is added', function () {
    $seller = Seller::factory()->submitted()->withoutRegistrationNumber()->create();

    $seller->update(['registration_number' => '120210001234']);

    $this->verification->verify($seller, $this->moderator);

    expect($seller->refresh()->isVerified())->toBeTrue();
});

it('refuses a move the workflow does not allow', function () {
    $seller = Seller::factory()->draft()->create();

    expect(fn () => $this->verification->verify($seller, $this->moderator))
        ->toThrow(InvalidVerificationTransition::class, 'A seller cannot go from Draft to Verified.');
});

it('keeps the reason on a rejection and shows it to the seller', function () {
    $seller = Seller::factory()->submitted()->create();

    $this->verification->reject($seller, $this->moderator, 'The registration number does not match PACRA records.');

    expect($seller->refresh()->verification_status)->toBe(VerificationStatus::Rejected)
        ->and($seller->rejection_reason)->toBe('The registration number does not match PACRA records.')
        ->and($seller->verified_at)->toBeNull();
});

it('lets a rejected seller apply again', function () {
    $seller = Seller::factory()->rejected()->create();

    $this->verification->submit($seller);

    expect($seller->refresh()->verification_status)->toBe(VerificationStatus::Submitted)
        ->and($seller->rejection_reason)->toBeNull();
});

it('records every transition on the seller file', function () {
    $seller = Seller::factory()->submitted()->create();

    $this->verification->beginReview($seller, $this->moderator, 'Opened.');
    $this->verification->verify($seller, $this->moderator, 'All checks passed.', ['documents' => true, 'premises' => true]);

    $events = $seller->verificationEvents()->get();

    expect($events)->toHaveCount(2)
        ->and($events->first()->to_status)->toBe(VerificationStatus::Verified)
        ->and($events->first()->checklist)->toBe(['documents' => true, 'premises' => true])
        ->and($events->last()->summary())->toBe('Submitted → Under review');
});

it('audits every transition', function () {
    $seller = Seller::factory()->submitted()->create();

    $this->verification->reject($seller, $this->moderator, 'Documents were illegible; please re-scan them.');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'seller.verification.rejected',
        'subject_type' => $seller->getMorphClass(),
        'subject_id' => $seller->id,
        'reason' => 'Documents were illegible; please re-scan them.',
    ]);
});

it('announces a granted badge so other modules can react', function () {
    Event::fake([SellerVerificationChanged::class]);
    $seller = Seller::factory()->submitted()->create();

    $this->verification->verify($seller, $this->moderator);

    Event::assertDispatched(
        SellerVerificationChanged::class,
        fn (SellerVerificationChanged $event): bool => $event->grantedBadge() && $event->seller->is($seller),
    );
});

it('gives the applicant the seller role when they submit', function () {
    $seller = Seller::factory()->draft()->create();

    expect($seller->user->hasRole(Role::Seller->value))->toBeFalse();

    $this->verification->submit($seller);

    expect($seller->user->refresh()->hasRole(Role::Seller->value))->toBeTrue();
});

it('takes a verified seller down and puts them back', function () {
    $seller = Seller::factory()->create();

    $this->verification->suspend($seller, $this->moderator, 'Repeated undelivered orders under investigation.');
    expect($seller->refresh()->verification_status)->toBe(VerificationStatus::Suspended)
        ->and($seller->verification_status->isPubliclyVisible())->toBeFalse();

    $this->verification->reinstate($seller, $this->moderator, 'Investigation closed; orders were delivered late but delivered.');
    expect($seller->refresh()->isVerified())->toBeTrue();
});

it('lets a moderator verify from the admin console', function () {
    $seller = Seller::factory()->submitted()->create();

    $this->actingAs($this->moderator)
        ->post(route('admin.sellers.verify', $seller), ['note' => 'Everything checked out.', 'checklist' => ['documents' => true]])
        ->assertRedirect(route('admin.sellers.show', $seller));

    expect($seller->refresh()->isVerified())->toBeTrue();
});

it('turns a workflow refusal into a message rather than an error page', function () {
    $seller = Seller::factory()->submitted()->withoutRegistrationNumber()->create();

    $this->actingAs($this->moderator)
        ->post(route('admin.sellers.verify', $seller))
        ->assertSessionHasErrors('verification');

    expect($seller->refresh()->isVerified())->toBeFalse();
});

it('demands a reason a seller can act on before rejecting', function () {
    $seller = Seller::factory()->submitted()->create();

    $this->actingAs($this->moderator)
        ->post(route('admin.sellers.reject', $seller), ['reason' => 'no'])
        ->assertSessionHasErrors('reason');
});

it('keeps sellers out of each other\'s verification decisions', function () {
    $seller = Seller::factory()->submitted()->create();

    $this->actingAs($seller->user)
        ->post(route('admin.sellers.verify', $seller))
        ->assertForbidden();
});
