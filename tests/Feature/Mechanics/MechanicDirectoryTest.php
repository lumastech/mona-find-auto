<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicSpeciality;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Sellers\Models\Seller;

/*
 * The directory's filters. Each is asserted on the API, because that is the
 * surface where a wrong filter is a wrong list rather than a wrong-looking
 * page — and the web page goes through exactly the same service.
 */

beforeEach(function (): void {
    $this->gearboxes = MechanicSpeciality::factory()->create(['name' => 'Gearbox & transmission']);
    $this->electrics = MechanicSpeciality::factory()->create(['name' => 'Auto electrics']);

    $lusaka = Province::factory()->create(['name' => 'Lusaka']);
    $copperbelt = Province::factory()->create(['name' => 'Copperbelt']);

    $this->lusakaCity = City::factory()->for($lusaka, 'province')->create(['name' => 'Lusaka']);
    $this->chilanga = City::factory()->for($lusaka, 'province')->create(['name' => 'Chilanga']);
    $this->kitwe = City::factory()->for($copperbelt, 'province')->create(['name' => 'Kitwe']);

    $this->gearboxMechanic = MechanicProfile::factory()
        ->withSpecialities([$this->gearboxes])
        ->create([
            'display_name' => 'Chanda Mwale',
            'province_id' => $this->lusakaCity->province_id,
            'city_id' => $this->lusakaCity->id,
        ]);

    $this->electricianInKitwe = MechanicProfile::factory()
        ->withSpecialities([$this->electrics])
        ->create([
            'display_name' => 'Mutale Banda',
            'province_id' => $this->kitwe->province_id,
            'city_id' => $this->kitwe->id,
            'accepting_work' => false,
        ]);
});

/**
 * @param  array<string, mixed>  $filters
 * @return array<int, string>
 */
function directoryNames(array $filters = []): array
{
    $response = test()->getJson(route('api.v1.mechanics.index', $filters))->assertOk();

    return array_map(
        static fn (array $row): string => $row['display_name'],
        $response->json('data'),
    );
}

it('filters by speciality', function (): void {
    expect(directoryNames(['speciality_id' => $this->gearboxes->getKey()]))
        ->toBe(['Chanda Mwale']);

    expect(directoryNames(['speciality_id' => $this->electrics->getKey()]))
        ->toBe(['Mutale Banda']);
});

it('filters by province and by town', function (): void {
    expect(directoryNames(['province_id' => $this->lusakaCity->province_id]))
        ->toBe(['Chanda Mwale']);

    expect(directoryNames(['city_id' => $this->kitwe->id]))
        ->toBe(['Mutale Banda']);

    /* A town nobody works in is an empty list, not everybody. */
    expect(directoryNames(['city_id' => $this->chilanga->id]))->toBe([]);
});

it('filters by minimum rating and keeps unrated mechanics out of a rating filter', function (): void {
    rateMechanic($this->gearboxMechanic, 5);
    rateMechanic($this->gearboxMechanic, 3);

    /* Average 4.0. */
    expect(directoryNames(['min_rating' => 4]))->toBe(['Chanda Mwale']);
    expect(directoryNames(['min_rating' => 4.5]))->toBe([]);

    /*
     * Somebody with no reviews has no average, so they do not clear a
     * threshold — but they are still in the unfiltered directory, which is
     * the distinction that matters on the day it opens.
     */
    expect(directoryNames(['min_rating' => 1]))->toBe(['Chanda Mwale']);
    expect(directoryNames())->toHaveCount(2);
});

it('filters to mechanics taking work', function (): void {
    expect(directoryNames(['accepting_work' => true]))->toBe(['Chanda Mwale']);
});

it('filters to endorsed mechanics, and drops them again when withdrawn', function (): void {
    $seller = Seller::factory()->create();
    $endorsement = MechanicEndorsement::factory()->between($this->electricianInKitwe, $seller)->create();

    expect(directoryNames(['endorsed_only' => true]))->toBe(['Mutale Banda']);

    $endorsement->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

    expect(directoryNames(['endorsed_only' => true]))->toBe([]);
});

it('searches names and qualifications', function (): void {
    expect(directoryNames(['search' => 'Chanda']))->toBe(['Chanda Mwale']);
    expect(directoryNames(['search' => 'nobody by that name']))->toBe([]);
});

it('combines filters rather than widening on each one', function (): void {
    expect(directoryNames([
        'speciality_id' => $this->gearboxes->getKey(),
        'province_id' => $this->kitwe->province_id,
    ]))->toBe([]);
});

it('rejects a filter that points at nothing', function (): void {
    $this->getJson(route('api.v1.mechanics.index', ['speciality_id' => 999999]))
        ->assertStatus(422);
});

it('orders by rating, then review count, then experience', function (): void {
    $best = MechanicProfile::factory()->withSpecialities([$this->gearboxes])->create([
        'display_name' => 'Best Rated',
        'years_experience' => 2,
    ]);

    rateMechanic($best, 5);
    rateMechanic($this->gearboxMechanic, 3);

    expect(directoryNames(['speciality_id' => $this->gearboxes->getKey()]))
        ->toBe(['Best Rated', 'Chanda Mwale']);
});

it('counts only public, published ratings towards the directory average', function (): void {
    rateMechanic($this->gearboxMechanic, 5);
    /* A mechanic's private rating of a buyer is not about the mechanic at all. */
    rateMechanic($this->gearboxMechanic, 1, RatingDirection::MechanicToBuyer);
    /* One in the moderation queue is not on the page yet. */
    rateMechanic($this->gearboxMechanic, 1, RatingDirection::SellerToMechanic, RatingStatus::PendingReview);

    $row = collect($this->getJson(route('api.v1.mechanics.index'))->json('data'))
        ->firstWhere('display_name', 'Chanda Mwale');

    expect((float) $row['rating']['average'])->toBe(5.0)
        ->and($row['rating']['count'])->toBe(1);
});

it('blurs a mechanic\'s contact details for a guest and shows them to a buyer', function (): void {
    $profile = $this->gearboxMechanic;

    $guest = $this->getJson(route('api.v1.mechanics.show', $profile))->assertOk();

    expect($guest->json('data.contact.visible'))->toBeFalse()
        ->and($guest->json('data.contact.prompt'))->toBe('Log in to view')
        ->and($guest->json('data.contact.fields.0.label'))->toBe('Phone')
        ->and($guest->json('data.contact.fields.0.value'))->not->toContain(substr($profile->phone, -6));

    $buyer = $this->actingAs(User::factory()->create())
        ->getJson(route('api.v1.mechanics.show', $profile))
        ->assertOk();

    expect($buyer->json('data.contact.visible'))->toBeTrue()
        ->and($buyer->json('data.contact.fields.0.value'))->toBe($profile->phone);
});

it('never puts a mechanic\'s references in a public payload', function (): void {
    $this->gearboxMechanic->references()->create([
        'name' => 'Mutale Banda',
        'phone' => '+260966111222',
        'position' => 0,
    ]);

    $response = $this->getJson(route('api.v1.mechanics.show', $this->gearboxMechanic))->assertOk();

    expect($response->json('data'))->not->toHaveKey('references');
    $response->assertDontSee('0966111222')->assertDontSee('+260966111222');
});

/**
 * A published public rating about a mechanic.
 */
function rateMechanic(
    MechanicProfile $profile,
    int $stars,
    RatingDirection $direction = RatingDirection::SellerToMechanic,
    RatingStatus $status = RatingStatus::Published,
): Rating {
    $endorsement = MechanicEndorsement::factory()
        ->between($profile, Seller::factory()->create())
        ->create();

    return Rating::query()->create([
        'direction' => $direction,
        'source' => $direction->source(),
        'source_type' => $endorsement->getMorphClass(),
        'source_id' => $endorsement->getKey(),
        'rater_type' => $endorsement->seller->getMorphClass(),
        'rater_id' => $endorsement->seller_id,
        'ratee_type' => $direction === RatingDirection::MechanicToBuyer
            ? $endorsement->seller->user->getMorphClass()
            : $profile->getMorphClass(),
        'ratee_id' => $direction === RatingDirection::MechanicToBuyer
            ? $endorsement->seller->user_id
            : $profile->getKey(),
        'submitted_by' => $endorsement->seller->user_id,
        'stars' => $stars,
        'status' => $status,
        'published_at' => $status === RatingStatus::Published ? now() : null,
    ]);
}
