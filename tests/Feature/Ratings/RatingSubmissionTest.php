<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Events\RatingSubmitted;
use App\Modules\Ratings\Exceptions\RatingNotAllowed;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingEligibility;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->ratings = app(RatingService::class);
    $this->eligibility = app(RatingEligibility::class);

    $this->buyer = User::factory()->create();
    $this->sellerUser = User::factory()->create();
    $this->seller = Seller::factory()->for($this->sellerUser)->create();
});

/**
 * A completed order between this test's buyer and seller.
 */
function completedOrder(?OrderStatus $status = null): Order
{
    $order = Order::factory()->completed()->create([
        'user_id' => test()->buyer->getKey(),
        'seller_id' => test()->seller->getKey(),
    ]);

    if ($status !== null) {
        $order->forceFill(['status' => $status])->save();
    }

    return $order->refresh();
}

it('lets a buyer review the shop once the order has completed', function (): void {
    $order = completedOrder();

    $rating = $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, 5, 'Right part, fitted first time.');

    expect($rating->stars)->toBe(5)
        ->and($rating->status)->toBe(RatingStatus::Published)
        ->and($rating->verified_purchase)->toBeTrue()
        ->and($rating->rater_id)->toBe($this->buyer->getKey())
        ->and($rating->ratee_id)->toBe($this->seller->getKey())
        ->and($rating->published_at)->not->toBeNull();
});

it('refuses a review before the order has completed', function (OrderStatus $status): void {
    $order = completedOrder($status);

    expect(fn () => $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, 5))
        ->toThrow(RatingNotAllowed::class);

    expect(Rating::query()->count())->toBe(0);
})->with([
    'paid' => [OrderStatus::Paid],
    'collected' => [OrderStatus::Collected],
    'cancelled' => [OrderStatus::Cancelled],
]);

it('allows exactly one rating per direction per order', function (): void {
    $order = completedOrder();

    $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, 5);

    expect(fn () => $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, 1))
        ->toThrow(RatingNotAllowed::class);

    /* The other direction on the same order is a different rating and is still open. */
    $this->ratings->submit($order, RatingDirection::SellerToBuyer, $this->sellerUser, 4);

    expect(Rating::query()->count())->toBe(2);
});

it('will not let the database hold two ratings for one direction', function (): void {
    $order = completedOrder();

    $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, 5);

    /* Straight past the service, the way a race would arrive. */
    expect(fn () => Rating::factory()->forOrder($order)->create())
        ->toThrow(QueryException::class);
});

it('refuses a rating from somebody who was not part of the order', function (): void {
    $order = completedOrder();
    $stranger = User::factory()->create();

    expect(fn () => $this->ratings->submit($order, RatingDirection::BuyerToSeller, $stranger, 5))
        ->toThrow(RatingNotAllowed::class);
});

it('records a seller-to-buyer rating against the business, not the member of staff', function (): void {
    $order = completedOrder();

    $rating = $this->ratings->submit($order, RatingDirection::SellerToBuyer, $this->sellerUser, 2, 'Never collected.');

    expect($rating->rater_type)->toBe($this->seller->getMorphClass())
        ->and($rating->rater_id)->toBe($this->seller->getKey())
        ->and($rating->submitted_by)->toBe($this->sellerUser->getKey())
        ->and($rating->ratee_id)->toBe($this->buyer->getKey());
});

it('rejects stars outside one to five', function (int $stars): void {
    $order = completedOrder();

    expect(fn () => $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, $stars))
        ->toThrow(ValidationException::class);
})->with([0, 6, -1]);

it('announces every submission so the trust score can be recomputed', function (): void {
    Event::fake([RatingSubmitted::class]);

    $order = completedOrder();
    $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, 4);

    Event::assertDispatched(RatingSubmitted::class);
});

it('offers each side its own prompt and withdraws it once used', function (): void {
    $order = completedOrder();

    $buyerPrompts = $this->eligibility->promptsFor($order, $this->buyer);
    expect($buyerPrompts)->toHaveCount(1)
        ->and($buyerPrompts[0]->direction)->toBe(RatingDirection::BuyerToSeller);

    $sellerPrompts = $this->eligibility->promptsFor($order, $this->sellerUser);
    expect($sellerPrompts)->toHaveCount(1)
        ->and($sellerPrompts[0]->direction)->toBe(RatingDirection::SellerToBuyer);

    $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, 5);

    expect($this->eligibility->promptsFor($order->refresh(), $this->buyer))->toBeEmpty()
        ->and($this->eligibility->promptsFor($order, $this->sellerUser))->toHaveCount(1);
});

it('offers no prompt at all until the order completes', function (): void {
    $order = completedOrder(OrderStatus::Delivered);

    expect($this->eligibility->promptsFor($order, $this->buyer))->toBeEmpty();
});

it('keeps the photographs the buyer attached', function (): void {
    Storage::fake('local');
    Storage::fake('public');

    $order = completedOrder();

    $rating = $this->ratings->submit(
        $order,
        RatingDirection::BuyerToSeller,
        $this->buyer,
        4,
        'Casing was scuffed.',
        [UploadedFile::fake()->image('part.jpg')],
    );

    expect($rating->getMedia(Rating::PHOTOS_COLLECTION))->toHaveCount(1);
});

it('writes an audit row for every rating left', function (): void {
    $order = completedOrder();

    $rating = $this->ratings->submit($order, RatingDirection::BuyerToSeller, $this->buyer, 5);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'rating.submitted',
        'subject_id' => $rating->getKey(),
    ]);
});
