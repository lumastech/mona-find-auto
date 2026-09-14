<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Enums\StockImportStatus;
use App\Modules\Inventory\Jobs\ApplyStockImport;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');

    $this->seller = Seller::factory()->create();
    $this->seller->user->refresh();

    $this->product = Product::factory()->ofSeller($this->seller)->stockConfirmedDaysAgo(8)->create();
    $this->variant = $this->product->variants()->first();
});

it('shows a seller their stock, worst freshness first', function (): void {
    Product::factory()->ofSeller($this->seller)->create();
    Product::factory()->ofSeller($this->seller)->stockHidden()->create();

    $this->actingAs($this->seller->user)
        ->get(route('seller.stock.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('seller/stock/Index')
                ->has('listings.data', 3)
                ->where('listings.data.0.freshness.value', FreshnessState::Hidden->value)
                ->where('summary.needs_confirmation', 2),
        );
});

it('confirms a whole shop in one request', function (): void {
    Product::factory()->count(2)->ofSeller($this->seller)->stockHidden()->create();

    $this->actingAs($this->seller->user)
        ->post(route('seller.stock.confirm'))
        ->assertRedirect();

    expect(
        Product::query()->where('seller_id', $this->seller->id)
            ->whereNot('freshness_state', FreshnessState::Fresh)
            ->count(),
    )->toBe(0);
});

it('confirms one listing on its own', function (): void {
    $this->actingAs($this->seller->user)
        ->post(route('seller.stock.confirm.product', $this->product))
        ->assertRedirect();

    expect($this->product->refresh()->freshness_state)->toBe(FreshnessState::Fresh);
});

it('records a quantity correction in the ledger and counts it as a confirmation', function (): void {
    $this->actingAs($this->seller->user)
        ->put(route('seller.stock.update', $this->product), [
            'variant_id' => $this->variant->id,
            'quantity' => 12,
            'low_stock_threshold' => 4,
        ])
        ->assertRedirect();

    expect($this->variant->refresh()->quantity)->toBe(12)
        ->and($this->variant->low_stock_threshold)->toBe(4)
        /* Typing a quantity is a statement about the shelf. */
        ->and($this->product->refresh()->freshness_state)->toBe(FreshnessState::Fresh)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('refuses a negative quantity', function (): void {
    $this->actingAs($this->seller->user)
        ->put(route('seller.stock.update', $this->product), [
            'variant_id' => $this->variant->id,
            'quantity' => -1,
        ])
        ->assertSessionHasErrors('quantity');
});

it('will not let a seller move an option that is not theirs', function (): void {
    $theirs = Product::factory()->create();

    $this->actingAs($this->seller->user)
        ->put(route('seller.stock.update', $this->product), [
            'variant_id' => $theirs->variants()->first()->id,
            'quantity' => 99,
        ])
        ->assertSessionHasErrors('variant_id');
});

it('keeps one shop out of another shop stock screen', function (): void {
    $intruder = Seller::factory()->create();

    $this->actingAs($intruder->user)
        ->post(route('seller.stock.confirm.product', $this->product))
        ->assertForbidden();
});

it('downloads the stock template', function (): void {
    $response = $this->actingAs($this->seller->user)
        ->get(route('seller.stock.template', ['format' => 'csv']));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=utf-8');

    expect($response->streamedContent())->toContain($this->variant->sku);
});

it('stages an upload, reports on it, and applies it only when told', function (): void {
    Queue::fake();

    $startingQuantity = $this->variant->quantity;

    $path = tempnam(sys_get_temp_dir(), 'mfa-upload-').'.csv';
    file_put_contents($path, "SKU,Quantity,Price (K)\n{$this->variant->sku},15,900.00\nGHOST-1,4,10.00\n");

    $file = new UploadedFile($path, 'stock.csv', 'text/csv', null, true);

    $this->actingAs($this->seller->user)
        ->post(route('seller.stock.imports.store'), ['file' => $file])
        ->assertRedirect();

    $batch = StockImportBatch::query()->firstOrFail();

    expect($batch->valid_rows)->toBe(1)
        ->and($batch->invalid_rows)->toBe(1)
        /*
         * Still nothing on the shelf: the report comes first.
         *
         * Compared against the quantity the variant actually started with
         * rather than against "not 15". The factory picks a random quantity,
         * and on the run where it picked 15 this assertion failed while
         * nothing was wrong — a flake that fails roughly one CI run in fifty.
         */
        ->and($this->variant->refresh()->quantity)->toBe($startingQuantity);

    $this->actingAs($this->seller->user)
        ->get(route('seller.stock.imports.show', $batch))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('seller/stock/Import')
                ->has('batch.problems', 1),
        );

    $this->actingAs($this->seller->user)
        ->post(route('seller.stock.imports.apply', $batch))
        ->assertRedirect(route('seller.stock.index'));

    Queue::assertPushed(ApplyStockImport::class);
});

it('rejects a file it cannot read with a message on the field', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'mfa-bad-').'.csv';
    file_put_contents($path, "Part,Amount\nX,1\n");

    $file = new UploadedFile($path, 'stock.csv', 'text/csv', null, true);

    $this->actingAs($this->seller->user)
        ->post(route('seller.stock.imports.store'), ['file' => $file])
        ->assertSessionHasErrors('file');

    /* Recorded even though nothing was applied. */
    expect(StockImportBatch::query()->first()->status)->toBe(StockImportStatus::Failed);
});

it('will not show one shop another shop upload report', function (): void {
    $batch = StockImportBatch::factory()->create();

    $this->actingAs($this->seller->user)
        ->get(route('seller.stock.imports.show', $batch))
        ->assertForbidden();
});

it('discards a report without touching the shelves', function (): void {
    $batch = StockImportBatch::factory()->for($this->seller)->create();

    $this->actingAs($this->seller->user)
        ->delete(route('seller.stock.imports.destroy', $batch))
        ->assertRedirect(route('seller.stock.index'));

    expect($batch->refresh()->status)->toBe(StockImportStatus::Discarded);
});

it('puts the stock summary in front of every seller-portal page', function (): void {
    $this->actingAs($this->seller->user)
        ->get(route('seller.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stock.needs_confirmation', 1));
});

it('leaves the stock summary off the storefront', function (): void {
    $this->actingAs($this->seller->user)
        ->get(route('listings.show', Product::factory()->create()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stock', null));
});
