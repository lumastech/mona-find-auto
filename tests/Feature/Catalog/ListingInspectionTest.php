<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Events\ListingInspectionChanged;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ListingInspectionService;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

/*
 * The inspection badge is MonaFind's own claim about a part, so the tests
 * here are about who may make it and whether the trail shows who did.
 */

beforeEach(function (): void {
    $this->inspection = app(ListingInspectionService::class);
});

it('starts every listing uninspected', function (): void {
    expect(Product::factory()->create()->inspection_status)->toBe(InspectionStatus::Uninspected);
});

it('lets a moderator mark a listing inspected and records who and why', function (): void {
    Event::fake([ListingInspectionChanged::class]);

    $moderator = actingAsRole([Role::Moderator]);
    $product = Product::factory()->create();

    $this->inspection->markInspected($product, $moderator, 'Checked the casting number against the OEM part.');

    $product->refresh();

    expect($product->inspection_status)->toBe(InspectionStatus::Inspected)
        ->and($product->inspected_by)->toBe($moderator->id)
        ->and($product->inspected_at)->not->toBeNull();

    Event::assertDispatched(ListingInspectionChanged::class);
});

it('writes an immutable audit row carrying the before and after', function (): void {
    $moderator = actingAsRole([Role::Moderator]);
    $product = Product::factory()->create();

    $this->inspection->markInspected($product, $moderator, 'Inspected at the Lusaka office.');

    $entry = AuditLog::query()->where('action', 'listing.inspection.inspected')->firstOrFail();

    expect($entry->before)->toBe(['inspection_status' => 'uninspected'])
        ->and($entry->after)->toBe(['inspection_status' => 'inspected'])
        ->and($entry->reason)->toBe('Inspected at the Lusaka office.');
});

it('refuses a seller trying to badge their own listing', function (): void {
    $seller = Seller::factory()->create();
    $product = Product::factory()->create(['seller_id' => $seller->id]);

    $this->inspection->markInspected($product, $seller->user, 'I had a look at it myself.');
})->throws(AuthorizationException::class);

it('refuses a buyer', function (): void {
    $buyer = actingAsRole([Role::Buyer]);

    $this->inspection->markInspected(Product::factory()->create(), $buyer, 'Looks fine to me.');
})->throws(AuthorizationException::class);

it('clears the inspector when the badge is taken away', function (): void {
    $moderator = actingAsRole([Role::Moderator]);
    $product = Product::factory()->inspected($moderator)->create();

    $this->inspection->markUninspected($product, $moderator, 'The part was swapped after inspection.');

    $product->refresh();

    expect($product->inspection_status)->toBe(InspectionStatus::Uninspected)
        ->and($product->inspected_by)->toBeNull()
        ->and($product->inspected_at)->toBeNull();
});

it('does not audit setting the badge to what it already is', function (): void {
    $moderator = actingAsRole([Role::Moderator]);
    $product = Product::factory()->create();

    $this->inspection->markUninspected($product, $moderator, 'No change.');

    expect(AuditLog::query()->where('action', 'like', 'listing.inspection.%')->count())->toBe(0);
});

it('keeps the condition and the inspection badge independent', function (): void {
    $moderator = actingAsRole([Role::Moderator]);

    $newAndUninspected = Product::factory()->condition(Condition::BrandNew)->create();
    $breakerAndInspected = Product::factory()->condition(Condition::CarBreaker)->inspected($moderator)->create();

    expect($newAndUninspected->condition)->toBe(Condition::BrandNew)
        ->and($newAndUninspected->inspection_status)->toBe(InspectionStatus::Uninspected)
        ->and($breakerAndInspected->condition)->toBe(Condition::CarBreaker)
        ->and($breakerAndInspected->inspection_status)->toBe(InspectionStatus::Inspected);
});
