<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Search\Enums\DisputeBand;
use App\Modules\Search\Services\QualityScore;
use App\Modules\Search\Support\SellerReputation;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->score = app(QualityScore::class);
});

/**
 * A listing from an unverified, uninspected, unrated shop with fresh stock.
 */
function baselineListing(array $sellerAttributes = [], array $productAttributes = []): Product
{
    $seller = Seller::factory()->create([
        'verification_status' => VerificationStatus::UnderReview,
        ...$sellerAttributes,
    ]);

    return Product::factory()->ofSeller($seller)->create([
        'inspection_status' => InspectionStatus::Uninspected,
        'freshness_state' => FreshnessState::Fresh,
        ...$productAttributes,
    ]);
}

/**
 * The best a listing can do is the weights added up — approached, never
 * reached, because the rating component is pulled towards the prior by design
 * and only an infinite number of five-star reviews escapes it entirely.
 */
it('scores every component out of its configured weight', function (): void {
    $listing = baselineListing(['verification_status' => VerificationStatus::Verified], [
        'inspection_status' => InspectionStatus::Inspected,
    ]);

    $best = $this->score->for($listing, new SellerReputation(5.0, 500, 0.0));

    expect($this->score->maximum())->toBe(100.0)
        ->and($best)->toBeLessThan(100.0)
        ->and($best)->toBeGreaterThan(99.0);
});

it('ranks a verified seller above an unverified one, all else equal', function (): void {
    $verified = baselineListing(['verification_status' => VerificationStatus::Verified]);
    $unverified = baselineListing();

    $reputation = SellerReputation::unrated();

    expect($this->score->for($verified, $reputation))
        ->toBeGreaterThan($this->score->for($unverified, $reputation));
});

it('ranks an inspected listing above an uninspected one', function (): void {
    $inspected = baselineListing([], ['inspection_status' => InspectionStatus::Inspected]);
    $uninspected = baselineListing();

    $reputation = SellerReputation::unrated();

    expect($this->score->for($inspected, $reputation))
        ->toBeGreaterThan($this->score->for($uninspected, $reputation));
});

/**
 * Ageing is meant to be a nudge and Unconfirmed a real demotion. Asserting
 * the shape of the gap rather than the exact numbers keeps the test honest
 * about what the requirement actually is.
 */
it('demotes ageing stock a little and unconfirmed stock a lot', function (): void {
    $reputation = SellerReputation::unrated();

    $fresh = $this->score->for(baselineListing(), $reputation);
    $ageing = $this->score->for(baselineListing([], ['freshness_state' => FreshnessState::Ageing]), $reputation);
    $unconfirmed = $this->score->for(baselineListing([], ['freshness_state' => FreshnessState::Unconfirmed]), $reputation);

    expect($ageing)->toBeLessThan($fresh)
        ->and($unconfirmed)->toBeLessThan($ageing)
        ->and($fresh - $unconfirmed)->toBeGreaterThan(($fresh - $ageing) * 3);
});

it('does not let a single five-star review outrank a long, strong record', function (): void {
    $listing = baselineListing();

    $oneReview = $this->score->for($listing, new SellerReputation(5.0, 1, 0.0));
    $manyReviews = $this->score->for($listing, new SellerReputation(4.8, 60, 0.0));

    expect($manyReviews)->toBeGreaterThan($oneReview);
});

it('treats an unrated seller as neither good nor bad', function (): void {
    $listing = baselineListing();

    $unrated = $this->score->for($listing, SellerReputation::unrated());

    expect($unrated)->toBeGreaterThan($this->score->for($listing, new SellerReputation(1.0, 40, 0.0)))
        ->and($unrated)->toBeLessThan($this->score->for($listing, new SellerReputation(5.0, 40, 0.0)));
});

it('bands dispute rates against the platform threshold, not a number of its own', function (): void {
    settings()->set('risk.dispute_rate_threshold_percent', '4.00');

    expect(DisputeBand::forRate(0.0, 4.0))->toBe(DisputeBand::None)
        ->and(DisputeBand::forRate(1.5, 4.0))->toBe(DisputeBand::Low)
        ->and(DisputeBand::forRate(3.0, 4.0))->toBe(DisputeBand::Elevated)
        ->and(DisputeBand::forRate(9.0, 4.0))->toBe(DisputeBand::High);

    $listing = baselineListing();

    expect($this->score->for($listing, new SellerReputation(4.0, 10, 9.0)))
        ->toBeLessThan($this->score->for($listing, new SellerReputation(4.0, 10, 0.0)));
});

it('recomputes against whatever weights an administrator has set', function (): void {
    $verified = baselineListing(['verification_status' => VerificationStatus::Verified]);
    $reputation = SellerReputation::unrated();

    $before = $this->score->for($verified, $reputation);

    settings()->set('ranking.weight.verified_seller', 60);

    expect($this->score->for($verified, $reputation))->toBeGreaterThan($before)
        ->and($this->score->weights()['verified_seller'])->toBe(60);
});
