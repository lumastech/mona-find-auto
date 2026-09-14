<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * The API is a second front door, not a second rulebook: it calls the same
 * service the web form does, so the tests here are about the envelope, the
 * token and the one thing the API could get wrong on its own — returning a
 * direction the storefront would never show.
 */

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->buyer = User::factory()->create();
    $this->sellerUser = User::factory()->create();
    $this->seller = Seller::factory()->for($this->sellerUser)->create([
        'verification_status' => VerificationStatus::Verified,
        'verified_at' => now()->subMonth(),
    ]);

    $this->order = Order::factory()->completed()->create([
        'user_id' => $this->buyer->getKey(),
        'seller_id' => $this->seller->getKey(),
    ]);
});

it('lists a shop reviews to anybody, in the standard envelope', function (): void {
    app(RatingService::class)->submit(
        $this->order,
        RatingDirection::BuyerToSeller,
        $this->buyer,
        5,
        'Genuine part, fair price.',
    );

    $this->getJson(route('api.v1.ratings.index', ['seller' => $this->seller->slug]))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'stars', 'body', 'author', 'verified_purchase', 'photos', 'reply']],
            'meta' => ['summary', 'pagination'],
        ])
        ->assertJsonPath('meta.summary.count', 1)
        ->assertJsonPath('meta.summary.average', 5);
});

it('answers 404 for a shop that is not publicly visible', function (): void {
    $draft = Seller::factory()->create(['verification_status' => VerificationStatus::Draft]);

    $this->getJson(route('api.v1.ratings.index', ['seller' => $draft->slug]))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('takes a rating from a token holder', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson(route('api.v1.orders.ratings.store', $this->order), [
        'direction' => RatingDirection::BuyerToSeller->value,
        'stars' => 4,
        'body' => 'Took a day longer than promised but the part was right.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.stars', 4)
        ->assertJsonPath('data.verified_purchase', true);

    expect(Rating::query()->count())->toBe(1);
});

it('refuses a rating on an order that has not completed', function (): void {
    $this->order->forceFill(['status' => OrderStatus::Delivered])->save();

    Sanctum::actingAs($this->buyer);

    $this->postJson(route('api.v1.orders.ratings.store', $this->order), [
        'direction' => RatingDirection::BuyerToSeller->value,
        'stars' => 5,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'rating_not_allowed');

    expect(Rating::query()->count())->toBe(0);
});

it('refuses a second rating in the same direction', function (): void {
    Sanctum::actingAs($this->buyer);

    $payload = [
        'direction' => RatingDirection::BuyerToSeller->value,
        'stars' => 5,
    ];

    $this->postJson(route('api.v1.orders.ratings.store', $this->order), $payload)->assertCreated();
    $this->postJson(route('api.v1.orders.ratings.store', $this->order), $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'rating_not_allowed');
});

it('validates stars', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson(route('api.v1.orders.ratings.store', $this->order), [
        'direction' => RatingDirection::BuyerToSeller->value,
        'stars' => 9,
    ])->assertStatus(422);
});

it('refuses an order the token holder had nothing to do with', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.orders.ratings.store', $this->order), [
        'direction' => RatingDirection::BuyerToSeller->value,
        'stars' => 5,
    ])->assertForbidden();
});

it('needs a token to submit anything', function (): void {
    $this->postJson(route('api.v1.orders.ratings.store', $this->order), [
        'direction' => RatingDirection::BuyerToSeller->value,
        'stars' => 5,
    ])->assertUnauthorized();
});

it('tells the app what is left to rate', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->getJson(route('api.v1.orders.ratings.prompts', $this->order))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.direction', RatingDirection::BuyerToSeller->value)
        ->assertJsonPath('data.0.is_public', true);

    app(RatingService::class)->submit($this->order, RatingDirection::BuyerToSeller, $this->buyer, 5);

    $this->getJson(route('api.v1.orders.ratings.prompts', $this->order))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('offers the seller the private direction and calls it private', function (): void {
    Sanctum::actingAs($this->sellerUser);

    $this->getJson(route('api.v1.orders.ratings.prompts', $this->order))
        ->assertOk()
        ->assertJsonPath('data.0.direction', RatingDirection::SellerToBuyer->value)
        ->assertJsonPath('data.0.is_public', false);
});

it('never lists a rating that is not on the storefront', function (): void {
    $ratings = app(RatingService::class);

    $ratings->submit($this->order, RatingDirection::BuyerToSeller, $this->buyer, 5, 'All good.');
    $ratings->submit($this->order, RatingDirection::SellerToBuyer, $this->sellerUser, 1, 'Haggled endlessly.');

    /* Even signed in as the shop that wrote the private one. */
    Sanctum::actingAs($this->sellerUser);

    $this->getJson(route('api.v1.ratings.index', ['seller' => $this->seller->slug]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['body' => 'Haggled endlessly.']);
});
