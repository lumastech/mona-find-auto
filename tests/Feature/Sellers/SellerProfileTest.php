<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerPolicy;
use App\Modules\Sellers\Support\SellerContact;
use App\Support\Roles\Role;

beforeEach(function () {
    $this->seller = Seller::factory()->create([
        'business_name' => 'Kabwata Motor Spares',
        'phone' => '+260977123456',
        'email' => 'sales@kabwata.co.zm',
        'contact_person' => 'Chanda Mwale',
    ]);
});

it('shows a guest the contact labels but none of the values', function () {
    $response = $this->get(route('sellers.show', $this->seller));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('storefront/sellers/Show')
        ->where('seller.contact.visible', false)
        ->where('seller.contact.prompt', 'Log in to view')
        ->where('seller.contact.fields.0.label', 'Phone')
        ->where('seller.contact.fields.1.label', 'Email')
        ->where('seller.contact.fields.2.label', 'Contact person'));

    /* Not merely hidden by CSS: the values are not in the response at all. */
    $response->assertDontSee('+260977123456')
        ->assertDontSee('sales@kabwata.co.zm')
        ->assertDontSee('Chanda Mwale');
});

it('shows a logged-in buyer the real contact details', function () {
    $buyer = User::factory()->withRole(Role::Buyer)->create();

    $this->actingAs($buyer)
        ->get(route('sellers.show', $this->seller))
        ->assertInertia(fn ($page) => $page
            ->where('seller.contact.visible', true)
            ->where('seller.contact.fields.0.value', '+260977123456')
            ->where('seller.contact.fields.1.value', 'sales@kabwata.co.zm')
            ->where('seller.contact.fields.2.value', 'Chanda Mwale'));
});

it('applies the same blur rule to the JSON API', function () {
    $response = $this->getJson(route('api.v1.sellers.show', $this->seller->id));

    $response->assertOk()
        ->assertJsonPath('data.contact.visible', false)
        ->assertJsonPath('data.contact.prompt', 'Log in to view')
        ->assertJsonPath('data.contact.fields.0.label', 'Phone');

    expect($response->getContent())
        ->not->toContain('+260977123456')
        ->not->toContain('sales@kabwata.co.zm')
        ->not->toContain('Chanda Mwale');
});

it('unblurs the API for an authenticated buyer', function () {
    $buyer = User::factory()->withRole(Role::Buyer)->create();

    $this->actingAs($buyer)
        ->getJson(route('api.v1.sellers.show', $this->seller->id))
        ->assertJsonPath('data.contact.visible', true)
        ->assertJsonPath('data.contact.fields.0.value', '+260977123456')
        ->assertJsonPath('data.contact.fields.2.value', 'Chanda Mwale');
});

it('masks a phone number without leaking any of its digits', function () {
    $masked = SellerContact::for($this->seller, null);

    expect($masked->fields[0]['value'])->toBe('+260 ••• ••• •••')
        ->and($masked->fields[1]['value'])->toBe('••••••@••••••.zm')
        /* Two words in, two masked words out — the block does not jump when it unblurs. */
        ->and($masked->fields[2]['value'])->toBe('•••••• •••••');
});

it('carries the verified badge and its unverified counterpart', function () {
    $unverified = Seller::factory()->submitted()->create();

    $this->getJson(route('api.v1.sellers.show', $this->seller->id))
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.verification_label', 'Verified');

    $this->getJson(route('api.v1.sellers.show', $unverified->id))
        ->assertJsonPath('data.verified', false)
        ->assertJsonPath('data.verification_label', 'Not yet verified');
});

it('badges a car breaker\'s stock as such', function () {
    $breaker = Seller::factory()->ofType(SellerType::CarBreaker)->create();

    $this->getJson(route('api.v1.sellers.show', $breaker->id))
        ->assertJsonPath('data.sells_breaker_stock', true);

    $this->getJson(route('api.v1.sellers.show', $this->seller->id))
        ->assertJsonPath('data.type_label', $this->seller->type->label());
});

it('publishes the seller\'s current policies and the platform refund floor', function () {
    SellerPolicy::factory()->for($this->seller)->ofType(PolicyType::Refund)->create(['body' => 'Refunds within seven days.']);
    SellerPolicy::factory()->for($this->seller)->ofType(PolicyType::Refund)->superseded()->create(['version' => 0]);

    $this->getJson(route('api.v1.sellers.show', $this->seller->id))
        ->assertJsonCount(1, 'data.policies')
        ->assertJsonPath('data.policies.0.type', 'refund')
        ->assertJsonPath('data.policies.0.shows_platform_minimum', true)
        ->assertJsonPath('meta.platform_minimum_refund.days', 3);
});

it('hides a draft, rejected or suspended seller from buyers', function (string $state) {
    $seller = Seller::factory()->{$state}()->create();

    $this->get(route('sellers.show', $seller))->assertNotFound();
    $this->getJson(route('api.v1.sellers.show', $seller->id))->assertNotFound();
})->with(['draft', 'rejected', 'suspended']);

it('lists sellers for the mobile app without leaking contact details', function () {
    Seller::factory()->count(2)->create();

    $response = $this->getJson(route('api.v1.sellers.index'));

    $response->assertOk()->assertJsonPath('meta.pagination.total', 3);

    expect($response->getContent())->not->toContain('+260977123456');
});
