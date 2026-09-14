<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\TrustBand;
use App\Modules\Ratings\Events\SellerTrustScoreChanged;
use App\Modules\Ratings\Jobs\RecomputeSellerTrustScore;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Models\SellerTrustScore;
use App\Modules\Ratings\Services\RatingModerationService;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Ratings\Services\RatingsSellerReputation;
use App\Modules\Ratings\Services\TrustScoreService;
use App\Modules\Search\Contracts\SellerReputationProvider;
use App\Modules\Search\Jobs\ReindexSellerListings;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->trust = app(TrustScoreService::class);
    $this->ratings = app(RatingService::class);

    $this->seller = Seller::factory()->create();
});

/**
 * A paid order for this test's seller, disputed or not.
 *
 * The dispute is a real row rather than the order's `disputed_at` stamp,
 * because DisputeService::disputeRateFor() — which is where the trust score
 * gets its figure — counts dispute rows.
 */
function sellerOrder(Seller $seller, bool $disputed): Order
{
    $order = Order::factory()->completed()->create([
        'seller_id' => $seller->getKey(),
        'paid_at' => now()->subDays(5),
    ]);

    if ($disputed) {
        OrderDispute::factory()->create(['order_id' => $order->getKey()]);
    }

    return $order;
}

/**
 * Leave one review of this test's seller, at the given number of stars.
 */
function reviewSeller(int $stars, ?Seller $seller = null): Rating
{
    $seller ??= test()->seller;

    $order = Order::factory()->completed()->create(['seller_id' => $seller->getKey()]);

    return test()->ratings->submit($order, RatingDirection::BuyerToSeller, $order->buyer, $stars);
}

it('scores a seller nobody has rated neutrally rather than at zero', function (): void {
    $score = $this->trust->recompute($this->seller);

    expect($score->ratings_count)->toBe(0)
        ->and($score->average_stars)->toBeNull()
        ->and($score->trust_score)->toBeGreaterThan(30.0)
        ->and($score->trust_band)->not->toBe(TrustBand::Critical);
});

it('builds the average and the star breakdown from published reviews', function (): void {
    reviewSeller(5);
    reviewSeller(5);
    reviewSeller(3);

    $score = $this->trust->recompute($this->seller->refresh());

    expect($score->ratings_count)->toBe(3)
        ->and($score->average_stars)->toBe(4.33)
        ->and($score->star_breakdown[5])->toBe(2)
        ->and($score->star_breakdown[3])->toBe(1);
});

it('scores a well-reviewed shop above a badly reviewed one', function (): void {
    $good = Seller::factory()->create();
    $bad = Seller::factory()->create();

    foreach (range(1, 6) as $ignored) {
        reviewSeller(5, $good);
        reviewSeller(1, $bad);
    }

    expect($this->trust->recompute($good)->trust_score)
        ->toBeGreaterThan($this->trust->recompute($bad)->trust_score);
});

it('does not let one five-star review outrank a long good record', function (): void {
    $newcomer = Seller::factory()->create();
    reviewSeller(5, $newcomer);

    $established = Seller::factory()->create();
    foreach (range(1, 20) as $ignored) {
        reviewSeller(5, $established);
    }
    reviewSeller(4, $established);

    expect($this->trust->recompute($established)->trust_score)
        ->toBeGreaterThan($this->trust->recompute($newcomer)->trust_score);
});

it('leaves a hidden review out of the score', function (): void {
    $review = reviewSeller(1);
    reviewSeller(5);

    expect($this->trust->recompute($this->seller)->average_stars)->toBe(3.0);

    SpatieRole::findOrCreate(Role::Moderator->value, 'web');
    $moderator = User::factory()->create();
    $moderator->assignRole(Role::Moderator->value);

    app(RatingModerationService::class)->hide($review, $moderator, 'About a different shop.');

    expect($this->trust->recompute($this->seller)->average_stars)->toBe(5.0);
});

it('announces a change so the search index can be rebuilt', function (): void {
    Event::fake([SellerTrustScoreChanged::class]);

    reviewSeller(5);
    $this->trust->recompute($this->seller);

    Event::assertDispatched(SellerTrustScoreChanged::class);
});

it('stays quiet when a recompute lands on the same numbers', function (): void {
    reviewSeller(5);
    $this->trust->recompute($this->seller);

    Event::fake([SellerTrustScoreChanged::class]);

    $this->trust->recompute($this->seller);

    Event::assertNotDispatched(SellerTrustScoreChanged::class);
});

it('re-indexes the seller listings when the score moves', function (): void {
    Queue::fake();

    SellerTrustScoreChanged::dispatch($this->seller, SellerTrustScore::factory()->create([
        'seller_id' => $this->seller->getKey(),
    ]));

    Queue::assertPushed(ReindexSellerListings::class);
});

it('queues a recompute when a review lands', function (): void {
    Queue::fake();

    reviewSeller(4);

    Queue::assertPushed(
        RecomputeSellerTrustScore::class,
        fn (RecomputeSellerTrustScore $job): bool => $job->seller->is($this->seller),
    );
});

it('answers search with the precomputed row', function (): void {
    reviewSeller(4);
    reviewSeller(4);
    $this->trust->recompute($this->seller);

    $reputation = app(SellerReputationProvider::class)->for($this->seller);

    expect(app(SellerReputationProvider::class))->toBeInstanceOf(RatingsSellerReputation::class)
        ->and($reputation->ratingAverage)->toBe(4.0)
        ->and($reputation->reviewCount)->toBe(2);
});

it('answers for many sellers at once, including those with no row', function (): void {
    reviewSeller(5);
    $this->trust->recompute($this->seller);

    $unrated = Seller::factory()->create();

    $reputations = app(SellerReputationProvider::class)->forMany(
        collect([$this->seller, $unrated]),
    );

    expect($reputations[$this->seller->getKey()]->reviewCount)->toBe(1)
        ->and($reputations[$unrated->getKey()]->ratingAverage)->toBeNull()
        ->and($reputations[$unrated->getKey()]->reviewCount)->toBe(0);
});

it('counts a dispute against the score', function (): void {
    foreach (range(1, 10) as $index) {
        sellerOrder($this->seller, disputed: $index <= 5);
    }

    $disputed = $this->trust->recompute($this->seller);

    $clean = $this->trust->recompute(Seller::factory()->create());

    expect($disputed->dispute_rate_percent)->toBeGreaterThan(0.0)
        ->and($disputed->trust_score)->toBeLessThan($clean->trust_score);
});

it('never bands a poorly rated shop above Watch, however clean the rest of its record', function (): void {
    /*
     * No disputes, every order completed: three quarters of the weight is
     * full marks, and the composite alone would call this shop Fair.
     */
    foreach (range(1, 5) as $ignored) {
        reviewSeller(1);
    }

    $score = $this->trust->recompute($this->seller);

    expect($score->average_stars)->toBe(1.0)
        ->and($score->dispute_rate_percent)->toBe(0.0)
        ->and($score->trust_band->needsReview())->toBeTrue()
        ->and($score->trust_band->recommendedActions())->not->toBeEmpty();
});

it('puts a poorly rated shop on the moderator review list', function (): void {
    foreach (range(1, 5) as $ignored) {
        reviewSeller(1);
    }

    $this->trust->recompute($this->seller);

    $list = $this->trust->reviewList();

    expect($list)->toHaveCount(1)
        ->and($list->first()->trust_band->needsReview())->toBeTrue()
        ->and($list->first()->trust_band->recommendedActions())->not->toBeEmpty();
});

it('puts a well-rated shop with a high dispute rate on the list too', function (): void {
    foreach (range(1, 8) as $ignored) {
        reviewSeller(5);
    }

    foreach (range(1, 10) as $index) {
        sellerOrder($this->seller, disputed: $index <= 3);
    }

    $score = $this->trust->recompute($this->seller);

    expect($score->average_stars)->toBe(5.0)
        ->and($this->trust->reviewList()->pluck('seller_id'))->toContain($this->seller->getKey());
});

it('shows the review list to a moderator with its recommended actions', function (): void {
    foreach (range(1, 5) as $ignored) {
        reviewSeller(1);
    }
    $this->trust->recompute($this->seller);

    SpatieRole::findOrCreate(Role::Moderator->value, 'web');
    $moderator = User::factory()->withTwoFactor()->create();
    $moderator->assignRole(Role::Moderator->value);

    $this->actingAs($moderator)
        ->get(route('admin.trust.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/ratings/Trust')
            ->has('sellers', 1)
            ->has('sellers.0.recommended_actions')
        );
});
