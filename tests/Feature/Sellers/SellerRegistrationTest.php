<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Enums\RegistrationStep;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerRegistrationDraft;
use App\Support\Roles\Role;

/**
 * A complete business-details step, pointing at a real city.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function businessStepPayload(array $overrides = []): array
{
    $city = City::factory()->create();

    return [
        'business_name' => 'Kabwata Motor Spares',
        'registration_number' => '120210001234',
        'province_id' => $city->province_id,
        'city_id' => $city->id,
        'street' => 'Burma Road',
        'plot_number' => '17',
        'phone' => '0977123456',
        'email' => 'sales@kabwata.test',
        'contact_person' => 'Chanda Mwale',
        ...$overrides,
    ];
}

/**
 * Walk an applicant through step one and two so later steps have a seller.
 */
function startRegistration(User $user, SellerType $type = SellerType::SparePartsShop, array $business = []): Seller
{
    test()->actingAs($user)
        ->post(route('sellers.register.store', ['step' => RegistrationStep::Type->value]), ['type' => $type->value]);

    test()->actingAs($user)
        ->post(route('sellers.register.store', ['step' => RegistrationStep::Business->value]), businessStepPayload($business));

    return $user->refresh()->seller;
}

it('saves each step and remembers where the applicant got to', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('sellers.register.store', ['step' => 'type']), ['type' => SellerType::Garage->value])
        ->assertRedirect(route('sellers.register.step', ['step' => 'business']));

    $draft = SellerRegistrationDraft::query()->where('user_id', $user->id)->sole();

    expect($draft->sellerType())->toBe(SellerType::Garage)
        ->and($draft->current_step)->toBe(RegistrationStep::Business)
        ->and($draft->furthest_step)->toBe(RegistrationStep::Type);
});

it('turns the business step into a draft seller nobody can see yet', function () {
    $user = User::factory()->create();

    $seller = startRegistration($user);

    expect($seller)->not->toBeNull()
        ->and($seller->verification_status)->toBe(VerificationStatus::Draft)
        ->and($seller->business_name)->toBe('Kabwata Motor Spares')
        /* Typed as 0977…, stored as E.164 like every other number on the platform. */
        ->and($seller->phone)->toBe('+260977123456');

    $this->get(route('sellers.show', $seller))->assertNotFound();
});

it('asks a garage how many bays it has and a parts shop nothing of the kind', function () {
    $garageOwner = User::factory()->create();

    $this->actingAs($garageOwner)
        ->post(route('sellers.register.store', ['step' => 'type']), ['type' => SellerType::Garage->value]);

    $this->actingAs($garageOwner)
        ->post(route('sellers.register.store', ['step' => 'business']), businessStepPayload())
        ->assertSessionHasErrors('bay_count');

    $shopOwner = User::factory()->create();

    $this->actingAs($shopOwner)
        ->post(route('sellers.register.store', ['step' => 'type']), ['type' => SellerType::SparePartsShop->value]);

    $this->actingAs($shopOwner)
        ->post(route('sellers.register.store', ['step' => 'business']), businessStepPayload())
        ->assertSessionHasNoErrors();
});

it('requires a business name from every type of seller', function (SellerType $type) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('sellers.register.store', ['step' => 'type']), ['type' => $type->value]);

    $this->actingAs($user)
        ->post(route('sellers.register.store', ['step' => 'business']), businessStepPayload([
            'business_name' => '',
            'bay_count' => 4,
        ]))
        ->assertSessionHasErrors('business_name');
})->with(SellerType::cases());

it('lets a business sign up without a registration number', function () {
    $seller = startRegistration(User::factory()->create(), business: ['registration_number' => null]);

    expect($seller->registration_number)->toBeNull()
        ->and($seller->mayBeVerified())->toBeFalse();
});

it('refuses a registration number another seller already claims', function () {
    Seller::factory()->create(['registration_number' => '120210001234']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('sellers.register.store', ['step' => 'type']), ['type' => SellerType::SparePartsShop->value]);

    $this->actingAs($user)
        ->post(route('sellers.register.store', ['step' => 'business']), businessStepPayload())
        ->assertSessionHasErrors('registration_number');
});

it('resumes a half-finished application where it was left', function () {
    $user = User::factory()->create();
    startRegistration($user);

    $this->actingAs($user)
        ->get(route('sellers.register'))
        ->assertInertia(fn ($page) => $page
            ->component('storefront/sellers/Register')
            ->where('step', RegistrationStep::Policies->value)
            ->where('draft.seller.business_name', 'Kabwata Motor Spares'));
});

it('will not let an applicant skip ahead to a step they have not reached', function () {
    $user = User::factory()->create();
    SellerRegistrationDraft::factory()->for($user)->untouched()->create();

    $this->actingAs($user)
        ->get(route('sellers.register.step', ['step' => RegistrationStep::Documents->value]))
        ->assertRedirect(route('sellers.register.step', ['step' => RegistrationStep::Type->value]));
});

it('publishes the policies typed into the wizard as version one', function () {
    $user = User::factory()->create();
    $seller = startRegistration($user);

    $this->actingAs($user)->post(route('sellers.register.store', ['step' => 'policies']), [
        'policies' => [
            PolicyType::Delivery->value => 'We deliver anywhere in Lusaka within two working days.',
            PolicyType::Refund->value => 'Wrong or damaged parts come back within seven days for a full refund.',
            PolicyType::Warranty->value => 'Every new part carries a three month warranty against defects.',
        ],
    ])->assertSessionHasNoErrors();

    $policies = $seller->refresh()->currentPolicies()->get();

    expect($policies)->toHaveCount(3)
        ->and($policies->every(fn ($policy): bool => $policy->version === 1))->toBeTrue()
        ->and($seller->missingPolicies())->toBe([]);
});

it('blocks submission until every step is finished', function () {
    $user = User::factory()->create();
    startRegistration($user);

    $this->actingAs($user)
        ->post(route('sellers.register.submit'))
        ->assertSessionHasErrors('submit');

    expect($user->refresh()->hasRole(Role::Seller->value))->toBeFalse();
});
