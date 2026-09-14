<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\UserAddress;
use Laravel\Sanctum\Sanctum;

/**
 * A complete delivery address payload, pointing at a real city.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addressPayload(array $overrides = []): array
{
    $city = City::factory()->create();

    return [
        'label' => 'Home',
        'recipient_name' => 'Chanda Mwale',
        'recipient_phone' => '0977123456',
        'province_id' => $city->province_id,
        'city_id' => $city->id,
        'street' => 'Great East Road',
        'plot_number' => '42',
        ...$overrides,
    ];
}

it('saves an address and normalises the recipient number', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('addresses.store'), addressPayload())
        ->assertRedirect(route('addresses.index'));

    $address = $user->addresses()->sole();

    expect($address->recipient_phone)->toBe('+260977123456')
        ->and($address->label)->toBe('Home');
});

it('makes the first address the default whatever was ticked', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('addresses.store'), addressPayload(['is_default' => false]));

    expect($user->addresses()->sole()->is_default)->toBeTrue();
});

it('keeps exactly one address as the default', function () {
    $user = User::factory()->create();
    $first = UserAddress::factory()->for($user)->default()->create();

    $this->actingAs($user)->post(route('addresses.store'), addressPayload(['is_default' => true]));

    $addresses = $user->addresses()->get();

    expect($addresses)->toHaveCount(2)
        ->and($addresses->where('is_default', true))->toHaveCount(1)
        ->and($first->refresh()->is_default)->toBeFalse();
});

it('hands the default on when the default address is removed', function () {
    $user = User::factory()->create();
    $default = UserAddress::factory()->for($user)->default()->create();
    $other = UserAddress::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('addresses.destroy', $default));

    expect($user->addresses()->count())->toBe(1)
        ->and($other->refresh()->is_default)->toBeTrue();
});

it('promotes an address to the default on request', function () {
    $user = User::factory()->create();
    $default = UserAddress::factory()->for($user)->default()->create();
    $other = UserAddress::factory()->for($user)->create();

    $this->actingAs($user)->put(route('addresses.default', $other));

    expect($other->refresh()->is_default)->toBeTrue()
        ->and($default->refresh()->is_default)->toBeFalse();
});

it('caps how many addresses one buyer may keep', function () {
    $user = User::factory()->create();
    UserAddress::factory()->for($user)->count(10)->create();

    $this->actingAs($user)
        ->post(route('addresses.store'), addressPayload())
        ->assertSessionHasErrors(['label' => 'You can save up to 10 delivery addresses. Remove one to add another.']);

    expect($user->addresses()->count())->toBe(10);
});

it('resolves a written address for a dropped map pin', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('addresses.store'), addressPayload([
        'latitude' => -15.4167,
        'longitude' => 28.2833,
    ]));

    expect($user->addresses()->sole()->formatted_address)->toContain('Lusaka');
});

it('validates the address it is given', function (array $payload, string $field) {
    $this->actingAs(User::factory()->create())
        ->post(route('addresses.store'), addressPayload($payload))
        ->assertSessionHasErrors($field);
})->with([
    'no label' => [['label' => ''], 'label'],
    'no recipient' => [['recipient_name' => ''], 'recipient_name'],
    'foreign phone' => [['recipient_phone' => '+27821234567'], 'recipient_phone'],
    'no street' => [['street' => ''], 'street'],
    'unknown city' => [['city_id' => 9999], 'city_id'],
    'latitude out of range' => [['latitude' => 120, 'longitude' => 28.2], 'latitude'],
]);

it('will not let one buyer touch another buyer\'s address', function () {
    $address = UserAddress::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('addresses.destroy', $address))
        ->assertForbidden();

    expect(UserAddress::query()->whereKey($address->getKey())->exists())->toBeTrue();
});

it('mirrors the address book on the API', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $created = $this->postJson(route('api.v1.addresses.store'), addressPayload())
        ->assertCreated()
        ->assertJsonPath('data.recipient_phone', '+260977123456')
        ->json('data.id');

    $this->getJson(route('api.v1.addresses.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->patchJson(route('api.v1.addresses.update', $created), addressPayload(['label' => 'Workshop']))
        ->assertOk()
        ->assertJsonPath('data.label', 'Workshop');

    $this->deleteJson(route('api.v1.addresses.destroy', $created))->assertNoContent();

    expect($user->addresses()->count())->toBe(0);
});

it('hides another buyer\'s address from the API', function () {
    $address = UserAddress::factory()->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.addresses.show', $address))->assertForbidden();
});
