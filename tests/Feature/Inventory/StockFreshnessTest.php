<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Events\ProductFreshnessChanged;
use App\Modules\Inventory\Jobs\EvaluateStockFreshness;
use App\Modules\Inventory\Services\FreshnessService;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->freshness = app(FreshnessService::class);
    $this->seller = Seller::factory()->create();

    /* Confirmed the moment it was created, which is what listing a part means. */
    $this->product = Product::factory()->ofSeller($this->seller)->create([
        'freshness_confirmed_at' => now(),
    ]);
});

/**
 * The state machine, walked day by day. The boundaries are the whole point:
 * a listing on day three is Fresh and on day four it is not.
 */
it('slides through the freshness states as the days pass', function (): void {
    $expectations = [
        0 => FreshnessState::Fresh,
        3 => FreshnessState::Fresh,
        4 => FreshnessState::Ageing,
        5 => FreshnessState::Ageing,
        6 => FreshnessState::Unconfirmed,
        14 => FreshnessState::Unconfirmed,
        15 => FreshnessState::Hidden,
    ];

    /* Fixed, because travelling relative to a travelled clock compounds. */
    $confirmedOn = now()->startOfDay();

    foreach ($expectations as $days => $expected) {
        $this->travelTo($confirmedOn->copy()->addDays($days)->addHours(2));

        $this->freshness->refreshStates();

        expect($this->product->refresh()->freshness_state)
            ->toBe($expected, "day {$days}");
    }

    $this->travelBack();
});

it('takes a hidden listing off the storefront', function (): void {
    $this->travelTo(now()->addDays(20));
    $this->freshness->refreshStates();

    expect($this->product->refresh()->freshness_state)->toBe(FreshnessState::Hidden)
        ->and($this->product->isVisibleToBuyers())->toBeFalse()
        ->and(Product::query()->published()->count())->toBe(0);

    $this->travelBack();
});

it('excludes hidden listings from every storefront query', function (): void {
    Product::factory()->ofSeller($this->seller)->stockHidden()->create();
    Product::factory()->ofSeller($this->seller)->create();

    expect(Product::query()->published()->count())->toBe(2);

    $this->get(route('api.v1.products.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns a hidden listing to buyers the moment its stock is confirmed', function (): void {
    $product = Product::factory()->ofSeller($this->seller)->stockHidden()->create();

    expect($product->isVisibleToBuyers())->toBeFalse();

    $this->freshness->confirm($product);

    expect($product->refresh()->freshness_state)->toBe(FreshnessState::Fresh)
        ->and($product->freshness_hidden_at)->toBeNull()
        ->and($product->isVisibleToBuyers())->toBeTrue();
});

it('announces a state change so search and messaging can react', function (): void {
    Event::fake([ProductFreshnessChanged::class]);

    $this->travelTo(now()->addDays(4));
    $this->freshness->refreshStates();
    $this->travelBack();

    Event::assertDispatched(
        ProductFreshnessChanged::class,
        fn (ProductFreshnessChanged $event): bool => $event->from === FreshnessState::Fresh
            && $event->to === FreshnessState::Ageing
            && $event->isDemotion(),
    );
});

it('says nothing when the sweep finds nothing to change', function (): void {
    Event::fake([ProductFreshnessChanged::class]);

    $this->freshness->refreshStates();

    Event::assertNotDispatched(ProductFreshnessChanged::class);
});

it('follows the thresholds an administrator sets rather than hard-coded days', function (): void {
    $this->seed(SettingsSeeder::class);

    settings()->set('freshness.fresh_max_days', 1);
    settings()->set('freshness.ageing_max_days', 2);

    $this->travelTo(now()->addDays(2));
    $this->freshness->refreshStates();

    expect($this->product->refresh()->freshness_state)->toBe(FreshnessState::Ageing);

    $this->travelBack();
});

it('leaves drafts and archived listings out of the sweep', function (): void {
    $draft = Product::factory()->ofSeller($this->seller)->draft()->create([
        'freshness_confirmed_at' => now()->subDays(30),
    ]);

    $this->freshness->refreshStates();

    expect($draft->refresh()->freshness_state)->toBe(FreshnessState::Fresh);
});

it('keeps an unpublished listing ageing while it is down', function (): void {
    $unpublished = Product::factory()->ofSeller($this->seller)->unpublished()->create([
        'freshness_confirmed_at' => now()->subDays(30),
    ]);

    $this->freshness->refreshStates();

    expect($unpublished->refresh()->freshness_state)->toBe(FreshnessState::Hidden);
});

it('confirms a whole shop in one call and leaves other shops alone', function (): void {
    $mine = Product::factory()->count(3)->ofSeller($this->seller)->create([
        'freshness_confirmed_at' => now()->subDays(10),
        'freshness_state' => FreshnessState::Unconfirmed,
    ]);

    $theirs = Product::factory()->create([
        'freshness_confirmed_at' => now()->subDays(10),
        'freshness_state' => FreshnessState::Unconfirmed,
    ]);

    /* The listing created in beforeEach is confirmed too. */
    expect($this->freshness->confirmAllFor($this->seller))->toBe(4);

    $mine->each(fn (Product $product) => expect($product->refresh()->freshness_state)->toBe(FreshnessState::Fresh));

    expect($theirs->refresh()->freshness_state)->toBe(FreshnessState::Unconfirmed);
});

it('writes an audit row when a seller confirms their whole shop', function (): void {
    $this->freshness->confirmAllFor($this->seller);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'stock.confirmed_all',
        'subject_type' => Seller::class,
        'subject_id' => $this->seller->id,
    ]);
});

it('runs the sweep from its scheduled job', function (): void {
    $this->travelTo(now()->addDays(7));

    app(EvaluateStockFreshness::class)->handle($this->freshness);

    expect($this->product->refresh()->freshness_state)->toBe(FreshnessState::Unconfirmed);

    $this->travelBack();
});

it('counts what a shop still owes a confirmation on', function (): void {
    Product::factory()->count(2)->ofSeller($this->seller)->stockHidden()->create();

    $outstanding = $this->freshness->outstandingFor($this->seller);

    expect($outstanding[FreshnessState::Hidden->value])->toBe(2)
        ->and($outstanding[FreshnessState::Fresh->value])->toBe(1);
});

it('treats a listing that was never confirmed as confirmed when it was published', function (): void {
    $product = Product::factory()->ofSeller($this->seller)->create([
        'freshness_confirmed_at' => null,
        'published_at' => now()->subDays(7),
        'status' => ListingStatus::Published,
    ]);

    expect($product->daysSinceStockConfirmed())->toBe(7);

    $this->freshness->refreshStates();

    expect($product->refresh()->freshness_state)->toBe(FreshnessState::Unconfirmed);
});
