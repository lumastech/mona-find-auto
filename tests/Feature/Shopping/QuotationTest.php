<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ListingModerationService;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Enums\CartLineIssue;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Exceptions\InvalidQuotationTransition;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Exceptions\QuotationNotAcceptable;
use App\Modules\Shopping\Jobs\ExpireStaleQuotations;
use App\Modules\Shopping\Models\Quotation;
use App\Modules\Shopping\Notifications\QuotationAnsweredNotification;
use App\Modules\Shopping\Notifications\QuotationRequestedNotification;
use App\Modules\Shopping\Services\CartService;
use App\Modules\Shopping\Services\QuotationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    $this->quotations = app(QuotationService::class);
    $this->cart = app(CartService::class);

    $this->buyer = User::factory()->create();

    $this->seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();
    $this->product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill(['price' => kwacha(600), 'quantity' => 50])->save();
});

/*
|--------------------------------------------------------------------------
| The lifecycle: open → quoted → accepted | expired
|--------------------------------------------------------------------------
*/

it('opens a request and tells the shop about it', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 20, 'Do you have twenty?');

    expect($quotation->status)->toBe(QuotationStatus::Open)
        ->and($quotation->seller_id)->toBe($this->seller->getKey())
        ->and($quotation->quantity)->toBe(20);

    Notification::assertSentTo($this->seller->user, QuotationRequestedNotification::class);
});

it('lets a buyer ask about a part the shop has none of', function (): void {
    $this->variant->forceFill(['quantity' => 0])->save();

    $quotation = $this->quotations->request($this->buyer, $this->variant->fresh(), 10);

    /* "Can you source ten of these?" is exactly what an RFQ is for. */
    expect($quotation->status)->toBe(QuotationStatus::Open);
});

it('refuses a request against a listing nobody may see', function (): void {
    app(ListingModerationService::class)
        ->unpublish($this->product, null, 'Taken down.');

    expect(fn () => $this->quotations->request($this->buyer, $this->variant->fresh(), 5))
        ->toThrow(ListingNotPurchasable::class);
});

it('records the seller\'s answer and tells the buyer', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 20);

    $this->quotations->quote($quotation, kwacha(520), now()->addDays(5), 'Collect from Kabwata.');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Quoted)
        ->and($quotation->fresh()->quoted_unit_price_ngwee)->toBeMoney(kwacha(520)->ngwee)
        ->and($quotation->fresh()->delivery_note)->toBe('Collect from Kabwata.');

    Notification::assertSentTo($this->buyer, QuotationAnsweredNotification::class);
});

it('refuses a quote whose validity date has already passed', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 20);

    expect(fn () => $this->quotations->quote($quotation, kwacha(520), now()->subDay()))
        ->toThrow(InvalidQuotationTransition::class);

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Open);
});

it('turns an accepted quote into a cart line at the quoted price', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 20);
    $this->quotations->quote($quotation, kwacha(520), now()->addDays(5));

    $line = $this->quotations->accept($quotation->fresh());

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted)
        ->and($line->quantity)->toBe(20)
        ->and($line->quotation_id)->toBe($quotation->getKey())
        ->and($line->unit_price_ngwee)->toBeMoney(kwacha(520)->ngwee);
});

it('holds the quoted price even after the seller moves the shelf price', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 10);
    $this->quotations->quote($quotation, kwacha(520), now()->addDays(5));
    $this->quotations->accept($quotation->fresh());

    /* The shop puts its shelf price up; the offer stands. */
    $this->variant->forceFill(['price' => kwacha(700)])->save();

    $line = $this->cart->view($this->buyer)->lines()[0];

    expect($line->unitPrice)->toBeMoney(kwacha(520)->ngwee)
        ->and($line->total())->toBeMoney(kwacha(5200)->ngwee)
        ->and($line->has(CartLineIssue::PriceChanged))->toBeFalse();
});

it('falls back to the shelf price when an accepted quote passes its date', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 10);
    $this->quotations->quote($quotation, kwacha(520), now()->addDay());
    $this->quotations->accept($quotation->fresh());

    $this->travel(3)->days();

    $line = $this->cart->view($this->buyer)->lines()[0];

    expect($line->has(CartLineIssue::QuoteExpired))->toBeTrue()
        ->and($line->unitPrice)->toBeMoney(kwacha(600)->ngwee);
});

it('refuses to accept a request the seller has not answered', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 10);

    expect(fn () => $this->quotations->accept($quotation))
        ->toThrow(QuotationNotAcceptable::class);
});

it('refuses to accept a quote whose day has passed, and records the expiry', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->stale()->create();

    expect(fn () => $this->quotations->accept($quotation))
        ->toThrow(QuotationNotAcceptable::class);

    /* Refusing it also writes down what time had already done. */
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Expired);
});

it('lets a shop decline a request with a reason', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 10);

    $this->quotations->decline($quotation, 'We do not carry this for the D-Max.');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Declined)
        ->and($quotation->fresh()->decline_reason)->toBe('We do not carry this for the D-Max.');

    Notification::assertSentTo($this->buyer, QuotationAnsweredNotification::class);
});

it('will not quote a request that is already finished', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->accepted()->create();

    expect(fn () => $this->quotations->quote($quotation, kwacha(400), now()->addDay()))
        ->toThrow(InvalidQuotationTransition::class);
});

/*
|--------------------------------------------------------------------------
| Expiry
|--------------------------------------------------------------------------
*/

it('is acceptable on the last day it is valid, and not the day after', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)
        ->quoted(validUntil: now()->addDays(2))->create();

    $this->travelTo(now()->addDays(2)->setTime(23, 0));
    expect($quotation->fresh()->isAcceptable())->toBeTrue();

    $this->travelTo(now()->addDay()->startOfDay());
    expect($quotation->fresh()->isAcceptable())->toBeFalse();
});

it('voids stale quotes and leaves fresh ones alone', function (): void {
    $stale = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->stale()->create();
    $fresh = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->quoted()->create();
    $open = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->create();

    $voided = $this->quotations->expireStale();

    expect($voided)->toBe(1)
        ->and($stale->fresh()->status)->toBe(QuotationStatus::Expired)
        ->and($stale->fresh()->expired_at)->not->toBeNull()
        ->and($fresh->fresh()->status)->toBe(QuotationStatus::Quoted)
        ->and($open->fresh()->status)->toBe(QuotationStatus::Open);
});

it('leaves an accepted quote alone even once its date has passed', function (): void {
    $accepted = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)
        ->accepted()->create(['valid_until' => now()->subDays(3)]);

    $this->quotations->expireStale();

    expect($accepted->fresh()->status)->toBe(QuotationStatus::Accepted);
});

it('sweeps stale quotes from the scheduled job', function (): void {
    $stale = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->stale()->create();

    (new ExpireStaleQuotations)->handle($this->quotations);

    expect($stale->fresh()->status)->toBe(QuotationStatus::Expired);
});

/*
|--------------------------------------------------------------------------
| Authorisation: only the addressed seller may quote
|--------------------------------------------------------------------------
*/

it('lets the addressed shop answer', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 10);

    $this->actingAs($this->seller->user)
        ->post(route('seller.quotations.respond', $quotation), [
            'unit_price' => '520.50',
            'valid_until' => now()->addDays(5)->toDateString(),
        ])
        ->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Quoted)
        ->and($quotation->fresh()->quoted_unit_price_ngwee)->toBeMoney(52050);
});

it('refuses a quote from a shop the request was not addressed to', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 10);

    $stranger = Seller::factory()->ofType(SellerType::Garage)->create();

    $this->actingAs($stranger->user)
        ->post(route('seller.quotations.respond', $quotation), [
            'unit_price' => '1',
            'valid_until' => now()->addDays(5)->toDateString(),
        ])
        ->assertForbidden();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Open);
});

it('refuses a quote from the buyer themselves', function (): void {
    $quotation = $this->quotations->request($this->buyer, $this->variant, 10);

    $this->actingAs($this->buyer)
        ->post(route('seller.quotations.respond', $quotation), [
            'unit_price' => '1',
            'valid_until' => now()->addDays(5)->toDateString(),
        ])
        ->assertForbidden();
});

it('refuses an acceptance from anyone but the buyer who asked', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->quoted()->create();

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post(route('quotations.accept', $quotation))
        ->assertForbidden();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Quoted);
});

it('refuses an acceptance of a stale quote at the door', function (): void {
    $quotation = Quotation::factory()->forVariant($this->variant)->forBuyer($this->buyer)->stale()->create();

    $this->actingAs($this->buyer)
        ->post(route('quotations.accept', $quotation))
        ->assertForbidden();
});

it('shows a shop only the requests addressed to it', function (): void {
    $mine = $this->quotations->request($this->buyer, $this->variant, 10);

    $otherSeller = Seller::factory()->ofType(SellerType::CarBreaker)->create();
    $otherProduct = Product::factory()->ofSeller($otherSeller)->create();
    Quotation::factory()->forVariant($otherProduct->variants()->first())->create();

    $this->actingAs($this->seller->user)
        ->get(route('seller.quotations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('seller/quotations/Index')
            ->where('awaitingCount', 1)
            ->has('quotations.data', 1)
            ->where('quotations.data.0.id', $mine->id));
});

it('shows a buyer their own requests', function (): void {
    $mine = $this->quotations->request($this->buyer, $this->variant, 10);
    Quotation::factory()->forVariant($this->variant)->create();

    $this->actingAs($this->buyer)
        ->get(route('quotations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/Quotations')
            ->has('quotations.data', 1)
            ->where('quotations.data.0.id', $mine->id));
});
