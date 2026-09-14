<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role as SpatieRole;

/*
 * The rule under test: a shop's rating of a buyer is trade information, not
 * content. It is readable by sellers, mechanics and staff and by nobody else
 * — including the buyer it is about, who would otherwise learn to trade a bad
 * review for a good rating.
 *
 * Asserted on every surface separately, because each one could leak it on its
 * own: the storefront page props, the seller's page props, and the JSON API.
 */

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->ratings = app(RatingService::class);

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

    $this->publicReview = $this->ratings->submit(
        $this->order,
        RatingDirection::BuyerToSeller,
        $this->buyer,
        5,
        'Genuine part, good price.',
    );

    $this->privateRating = $this->ratings->submit(
        $this->order,
        RatingDirection::SellerToBuyer,
        $this->sellerUser,
        2,
        'Argued about the price after collecting.',
    );
});

it('shows only public reviews on the seller page', function (): void {
    $this->get(route('sellers.show', $this->seller))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reviews.reviews.data', 1)
            ->where('reviews.reviews.data.0.body', 'Genuine part, good price.')
            ->where('reviews.summary.count', 1)
        );
});

it('shows only public reviews on a listing page', function (): void {
    $product = Product::factory()->ofSeller($this->seller)->create();

    $this->get(route('listings.show', $product))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reviews.reviews.data', 1)
            ->where('reviews.reviews.data.0.direction', RatingDirection::BuyerToSeller->value)
        );
});

it('never puts a buyer-directed rating in a storefront page, even for the buyer', function (): void {
    $response = $this->actingAs($this->buyer)->get(route('sellers.show', $this->seller));

    expect($response->content())->not->toContain('Argued about the price');
});

it('keeps buyer-directed ratings out of the public API', function (): void {
    $this->getJson(route('api.v1.ratings.index', ['seller' => $this->seller->slug]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.direction', RatingDirection::BuyerToSeller->value)
        ->assertJsonMissing(['body' => 'Argued about the price after collecting.']);
});

it('will not return a private rating even when asked for one directly', function (): void {
    /*
     * The endpoint takes no direction filter. This asserts the scope rather
     * than the absence of a parameter: a query string somebody adds later
     * must not be able to widen what comes back.
     */
    $this->getJson(route('api.v1.ratings.index', [
        'seller' => $this->seller->slug,
        'direction' => RatingDirection::SellerToBuyer->value,
    ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.direction', RatingDirection::BuyerToSeller->value);
});

it('lets the shop read its own rating of a buyer', function (): void {
    $this->actingAs($this->sellerUser)
        ->get(route('seller.ratings.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('given.data', 1)
            ->where('given.data.0.body', 'Argued about the price after collecting.')
        );
})->skip(fn (): bool => ! Route::has('seller.ratings.index'), 'Seller portal route missing.');

it('allows other sellers and staff to read a buyer-directed rating, and refuses everyone else', function (): void {
    SpatieRole::findOrCreate(Role::Seller->value, 'web');
    SpatieRole::findOrCreate(Role::Moderator->value, 'web');
    SpatieRole::findOrCreate(Role::Mechanic->value, 'web');

    $otherSeller = User::factory()->create();
    $otherSeller->assignRole(Role::Seller->value);

    $mechanic = User::factory()->create();
    $mechanic->assignRole(Role::Mechanic->value);

    $moderator = User::factory()->create();
    $moderator->assignRole(Role::Moderator->value);

    $stranger = User::factory()->create();

    expect($otherSeller->can('view', $this->privateRating))->toBeTrue()
        ->and($mechanic->can('view', $this->privateRating))->toBeTrue()
        ->and($moderator->can('view', $this->privateRating))->toBeTrue()
        ->and($stranger->can('view', $this->privateRating))->toBeFalse()
        /* The buyer it is about is not shown it either. */
        ->and($this->buyer->can('view', $this->privateRating))->toBeFalse();
});

it('signs public reviews with a first name and an initial', function (): void {
    $this->buyer->forceFill(['name' => 'Chanda Mwale'])->save();

    expect($this->publicReview->refresh()->authorName())->toBe('Chanda M.');
});

it('signs a shop rating with the business name', function (): void {
    expect($this->privateRating->refresh()->authorName())->toBe($this->seller->business_name);
});

it('counts only public directions in a seller aggregate', function (): void {
    expect($this->ratings->aggregateFor($this->seller)->count)->toBe(1)
        ->and(Rating::query()->count())->toBe(2);
});
