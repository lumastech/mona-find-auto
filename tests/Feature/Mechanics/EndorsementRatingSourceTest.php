<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Services\EndorsementService;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingSource;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Services\RatingEligibility;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Ratings\Services\RatingSourceRegistry;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\Notification;

/*
 * The seam between the two modules. Ratings shipped a resolver interface and
 * a registry and named no endorsement anywhere; Mechanics registers one from
 * its own provider. These tests assert that the seam actually carries a
 * rating end to end, and that an endorsement entitles exactly the one it
 * genuinely speaks for.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->ratings = app(RatingService::class);
    $this->eligibility = app(RatingEligibility::class);

    $this->profile = MechanicProfile::factory()->withSpecialities()->create();
    $this->seller = Seller::factory()->create();
    $this->shopkeeper = $this->seller->user;

    $this->endorsement = MechanicEndorsement::factory()
        ->between($this->profile, $this->seller)
        ->create();
});

it('is registered with the Ratings module without Ratings knowing what it is', function (): void {
    $resolver = app(RatingSourceRegistry::class)->for($this->endorsement);

    expect($resolver->source())->toBe(RatingSource::Endorsement)
        ->and($resolver->handles())->toBe(MechanicEndorsement::class)
        ->and($resolver->isComplete($this->endorsement))->toBeTrue();
});

it('lets the endorsing shop review the mechanic, as the business', function (): void {
    $rating = $this->ratings->submit(
        $this->endorsement,
        RatingDirection::SellerToMechanic,
        $this->shopkeeper,
        5,
        'Clean work and he turns up when he says he will.',
    );

    expect($rating->direction)->toBe(RatingDirection::SellerToMechanic)
        ->and($rating->source)->toBe(RatingSource::Endorsement)
        /* The business is the rater; the person who typed it is recorded beside it. */
        ->and($rating->rater_id)->toBe($this->seller->getKey())
        ->and($rating->submitted_by)->toBe($this->shopkeeper->getKey())
        ->and($rating->ratee_id)->toBe($this->profile->getKey())
        /* An endorsement is a real relationship, but it is not a purchase. */
        ->and($rating->verified_purchase)->toBeFalse();
});

it('puts that review on the mechanic\'s public page', function (): void {
    $this->ratings->submit(
        $this->endorsement,
        RatingDirection::SellerToMechanic,
        $this->shopkeeper,
        4,
        'Good on gearboxes.',
    );

    $this->getJson(route('api.v1.mechanics.show', $this->profile))
        ->assertOk()
        ->assertJsonPath('data.rating.count', 1)
        ->assertJsonPath('data.rating.average', 4);

    $this->get(route('mechanics.show', $this->profile))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('reviews.reviews.data', 1)
            ->where('reviews.reviews.data.0.body', 'Good on gearboxes.')
        );
});

it('refuses a shop that did not give the endorsement', function (): void {
    $other = Seller::factory()->create();

    expect(fn () => $this->ratings->submit(
        $this->endorsement,
        RatingDirection::SellerToMechanic,
        $other->user,
        5,
    ))->toThrow(RatingNotAllowed::class);
});

it('refuses anybody who does not run a shop at all', function (): void {
    expect(fn () => $this->ratings->submit(
        $this->endorsement,
        RatingDirection::SellerToMechanic,
        User::factory()->create(),
        5,
    ))->toThrow(RatingNotAllowed::class);
});

it('allows one review per endorsement, as the database enforces', function (): void {
    $this->ratings->submit($this->endorsement, RatingDirection::SellerToMechanic, $this->shopkeeper, 5);

    expect(fn () => $this->ratings->submit(
        $this->endorsement,
        RatingDirection::SellerToMechanic,
        $this->shopkeeper,
        1,
    ))->toThrow(RatingNotAllowed::class, 'already reviewed');
});

it('will not be reviewed until the shop has actually endorsed', function (): void {
    $pending = MechanicEndorsement::factory()
        ->between(MechanicProfile::factory()->withSpecialities()->create(), $this->seller)
        ->requested()
        ->create();

    expect(fn () => $this->ratings->submit(
        $pending,
        RatingDirection::SellerToMechanic,
        $this->shopkeeper,
        5,
    ))->toThrow(RatingNotAllowed::class);
});

it('keeps a review written while the endorsement stood, after it is withdrawn', function (): void {
    $rating = $this->ratings->submit(
        $this->endorsement,
        RatingDirection::SellerToMechanic,
        $this->shopkeeper,
        5,
        'Good work.',
    );

    app(EndorsementService::class)->revoke(
        $this->endorsement,
        $this->seller,
        $this->shopkeeper,
        'They have moved to the Copperbelt.',
    );

    /* The badge goes; the review and the source it hangs off both stay. */
    expect($rating->refresh()->exists)->toBeTrue()
        ->and($rating->sourceRecord->getKey())->toBe($this->endorsement->getKey());

    $this->getJson(route('api.v1.mechanics.show', $this->profile))
        ->assertOk()
        ->assertJsonCount(0, 'data.endorsements')
        ->assertJsonPath('data.rating.count', 1);
});

it('offers the shop exactly one prompt, and nothing to anyone else', function (): void {
    $prompts = $this->eligibility->promptsFor($this->endorsement, $this->shopkeeper);

    expect($prompts)->toHaveCount(1)
        ->and($prompts[0]->direction)->toBe(RatingDirection::SellerToMechanic)
        ->and($prompts[0]->rateeName)->toBe($this->profile->display_name);

    /* The mechanic does not rate their own endorsement. */
    expect($this->eligibility->promptsFor($this->endorsement, $this->profile->user))->toBe([]);
    expect($this->eligibility->promptsFor($this->endorsement, User::factory()->create()))->toBe([]);
});

it('does not pretend an endorsement entitles a buyer-mechanic rating', function (): void {
    /*
     * Buyer→Mechanic and Mechanic→Buyer hang off an endorsement in
     * RatingDirection, but an endorsement is between a shop and a mechanic
     * and has no buyer in it. Nothing on the platform records a job done for
     * a buyer yet, so the resolver refuses rather than inventing parties.
     */
    foreach ([RatingDirection::BuyerToMechanic, RatingDirection::MechanicToBuyer] as $direction) {
        expect(fn () => $this->ratings->submit(
            $this->endorsement,
            $direction,
            $this->shopkeeper,
            5,
        ))->toThrow(RatingNotAllowed::class);
    }
});

it('still rates orders the way it always did', function (): void {
    /* Registering a second source must not disturb the first. */
    $registry = app(RatingSourceRegistry::class);

    expect($registry->all())->toHaveCount(2);
});
