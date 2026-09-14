<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;

it('keeps buyers out of the seller portal', function (string $route) {
    $buyer = User::factory()->withRole(Role::Buyer)->create();

    $this->actingAs($buyer)->get(route($route))->assertForbidden();
})->with([
    'seller.profile.edit',
    'seller.policies.index',
    'seller.payout-accounts.index',
    'seller.documents.index',
    'seller.verification.show',
]);

it('lets a seller manage their own shop', function (string $route) {
    $seller = Seller::factory()->create();

    $this->actingAs($seller->user)->get(route($route))->assertOk();
})->with([
    'seller.profile.edit',
    'seller.policies.index',
    'seller.payout-accounts.index',
    'seller.documents.index',
    'seller.verification.show',
]);

it('404s a seller-role account with no shop rather than looping it back to sign-up', function () {
    $orphan = User::factory()->withRole(Role::Seller)->create();

    $this->actingAs($orphan)->get(route('seller.profile.edit'))->assertNotFound();
});

it('shows a seller their payment mode without letting them change it', function () {
    $seller = Seller::factory()->direct()->create();

    $this->actingAs($seller->user)
        ->get(route('seller.profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('commercialTerms.payment_mode', PaymentMode::Direct->value)
            ->where('commercialTerms.carries_reserve', true)
            ->where('commercialTerms.editable', false));
});

it('ignores a payment mode posted into the profile form', function () {
    /* Pinned: a garage would also be asked for its bay count, which is not what this tests. */
    $seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();

    $this->actingAs($seller->user)->patch(route('seller.profile.update'), [
        'business_name' => $seller->business_name,
        'province_id' => $seller->province_id,
        'city_id' => $seller->city_id,
        'street' => $seller->street,
        'phone' => $seller->phone,
        'email' => $seller->email,
        'contact_person' => $seller->contact_person,
        'payment_mode' => PaymentMode::Direct->value,
        'monetisation_policy_id' => 99,
    ])->assertSessionHasNoErrors();

    expect($seller->refresh()->payment_mode)->toBe(PaymentMode::Escrow)
        ->and($seller->monetisation_policy_id)->toBeNull();
});

it('starts every new seller on escrow with no monetisation override', function () {
    $seller = Seller::factory()->draft()->create();

    expect($seller->payment_mode)->toBe(PaymentMode::Escrow)
        ->and($seller->monetisation_policy_id)->toBeNull();
});

it('sends a finished seller to their verification card rather than back into the wizard', function () {
    $seller = Seller::factory()->submitted()->create();

    $this->actingAs($seller->user)
        ->get(route('sellers.register'))
        ->assertRedirect(route('seller.verification.show'));
});

it('keeps sellers out of the staff console', function () {
    $seller = Seller::factory()->create();

    $this->actingAs($seller->user)->get(route('admin.sellers.index'))->assertForbidden();
});

it('lets a moderator work the verification queue', function () {
    Seller::factory()->count(2)->submitted()->create();
    Seller::factory()->create();

    $moderator = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create();

    $this->actingAs($moderator)
        ->get(route('admin.sellers.index', ['queue' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('queueCount', 2)
            ->has('sellers.data', 2));
});
