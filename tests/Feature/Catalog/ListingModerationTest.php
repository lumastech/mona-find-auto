<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Events\ListingPublished;
use App\Modules\Catalog\Events\ListingRejected;
use App\Modules\Catalog\Events\ListingSubmittedForReview;
use App\Modules\Catalog\Exceptions\InvalidListingTransition;
use App\Modules\Catalog\Exceptions\ListingNotReadyForReview;
use App\Modules\Catalog\Exceptions\SellerMayNotList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ListingModerationService;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerVerificationService;
use App\Support\Roles\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    $this->moderation = app(ListingModerationService::class);
    $this->moderator = actingAsRole([Role::Moderator]);
});

/**
 * A draft with everything a review needs: a photo, a price and a description.
 */
function reviewableDraft(?Seller $seller = null): Product
{
    $product = Product::factory()->draft()->create(
        $seller === null ? [] : ['seller_id' => $seller->getKey()],
    );

    $product->addMedia(UploadedFile::fake()->image('part.jpg', 800, 600))
        ->toMediaCollection(Product::PHOTOS_COLLECTION);

    return $product->refresh();
}

it('sends a complete draft to the moderation queue', function (): void {
    Event::fake([ListingSubmittedForReview::class]);

    $product = reviewableDraft();

    $this->moderation->submit($product);

    expect($product->refresh()->status)->toBe(ListingStatus::PendingReview)
        ->and($product->submitted_at)->not->toBeNull();

    Event::assertDispatched(ListingSubmittedForReview::class);
});

it('refuses to submit a listing with no photo, naming the field', function (): void {
    $product = Product::factory()->draft()->create();

    expect(fn () => $this->moderation->submit($product))
        ->toThrow(function (ListingNotReadyForReview $exception): void {
            expect($exception->reasons)->toHaveKey('photos');
        });
});

it('refuses to submit a listing from a rejected shop', function (): void {
    $seller = Seller::factory()->rejected()->create();
    $product = reviewableDraft($seller);

    $this->moderation->submit($product);
})->throws(SellerMayNotList::class);

it('lets a seller still waiting on verification submit', function (): void {
    $seller = Seller::factory()->submitted()->create();
    $product = reviewableDraft($seller);

    $this->moderation->submit($product);

    expect($product->refresh()->status)->toBe(ListingStatus::PendingReview);
});

it('publishes a listing and records who decided', function (): void {
    Event::fake([ListingPublished::class]);

    $product = Product::factory()->pendingReview()->create();

    $this->moderation->publish($product, $this->moderator);

    expect($product->refresh()->status)->toBe(ListingStatus::Published)
        ->and($product->published_at)->not->toBeNull()
        ->and($product->reviewed_by)->toBe($this->moderator->id);

    Event::assertDispatched(ListingPublished::class);
});

it('returns per-field reasons to the seller when it rejects', function (): void {
    Event::fake([ListingRejected::class]);

    $product = Product::factory()->pendingReview()->create();

    $this->moderation->reject(
        $product,
        $this->moderator,
        'The photos do not show the part clearly.',
        ['photos' => 'Too dark to see the part.', 'description' => 'Say which engine code this fits.'],
    );

    $product->refresh();

    expect($product->status)->toBe(ListingStatus::Rejected)
        ->and($product->rejection_fields)->toBe([
            'photos' => 'Too dark to see the part.',
            'description' => 'Say which engine code this fits.',
        ]);

    Event::assertDispatched(ListingRejected::class);
});

it('clears the old reasons when a rejected listing is sent back', function (): void {
    $product = Product::factory()->rejected()->create();

    $product->addMedia(UploadedFile::fake()->image('part.jpg'))
        ->toMediaCollection(Product::PHOTOS_COLLECTION);

    $this->moderation->submit($product->refresh());

    expect($product->refresh()->rejection_reason)->toBeNull()
        ->and($product->rejection_fields)->toBeNull();
});

it('writes a review event and an audit row for every move', function (): void {
    $product = Product::factory()->pendingReview()->create();

    $this->moderation->publish($product, $this->moderator);

    expect($product->reviewEvents()->count())->toBe(1)
        ->and($product->reviewEvents()->first()->to_status)->toBe(ListingStatus::Published)
        ->and(AuditLog::query()->where('action', 'listing.published')->exists())->toBeTrue();
});

it('refuses a move the lifecycle does not allow', function (): void {
    $product = Product::factory()->archived()->create();

    $this->moderation->publish($product, $this->moderator);
})->throws(InvalidListingTransition::class);

it('will not unpublish a listing that was never published', function (): void {
    $product = Product::factory()->draft()->create();

    $this->moderation->unpublish($product, $this->moderator, 'Out of stock.');
})->throws(InvalidListingTransition::class);

it('takes a suspended seller stock down with the shop', function (): void {
    $seller = Seller::factory()->create();
    $live = Product::factory()->create(['seller_id' => $seller->id]);
    $queued = Product::factory()->pendingReview()->create(['seller_id' => $seller->id]);
    $elsewhere = Product::factory()->create();

    app(SellerVerificationService::class)
        ->suspend($seller, $this->moderator, 'Selling parts that were not theirs.');

    expect($live->refresh()->status)->toBe(ListingStatus::Unpublished)
        /* A listing still in the queue was never live, so it goes back to the seller. */
        ->and($queued->refresh()->status)->toBe(ListingStatus::Draft)
        ->and($elsewhere->refresh()->status)->toBe(ListingStatus::Published);
});

it('hides a suspended shop listings from buyers', function (): void {
    $seller = Seller::factory()->create(['verification_status' => VerificationStatus::Suspended]);
    $product = Product::factory()->create(['seller_id' => $seller->id]);

    expect($product->isVisibleToBuyers())->toBeFalse()
        ->and(Product::query()->published()->count())->toBe(0);
});
