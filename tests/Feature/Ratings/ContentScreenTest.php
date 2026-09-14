<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Sellers\Models\Seller;
use App\Support\Content\ContentScreen;
use App\Support\Content\ScreenFlag;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * The screen does two different jobs and the tests are grouped by which.
 *
 * Contact details are redacted and the review still publishes — the harm is
 * the number living on a public page, and blanking it keeps a review somebody
 * took the trouble to write. Profanity is queued, because there is no
 * automatic fix for it.
 */

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->screen = app(ContentScreen::class);
});

it('redacts a phone number however it was typed', function (string $body): void {
    $result = $this->screen->screen($body);

    expect($result->text)->not->toContain('977')
        ->and($result->text)->toContain('[removed]')
        ->and($result->flags)->toContain(ScreenFlag::PhoneNumber)
        ->and($result->requiresReview())->toBeFalse();
})->with([
    'plain' => ['Call me on 0977123456 for a better price'],
    'spaced' => ['Ring 0977 123 456 instead'],
    'dashed' => ['Try 0977-123-456'],
    'international' => ['WhatsApp +260 977 123 456'],
    'dotted' => ['My line is 0977.123.456'],
]);

it('leaves prices, years and quantities alone', function (string $body): void {
    expect($this->screen->screen($body)->text)->toBe($body);
})->with([
    'a price' => ['Paid K 1 200 for the set'],
    'a year' => ['Fits my 2019 Hilux perfectly'],
    'a part number' => ['Part 90919-01253 was the right one'],
    'quantities' => ['Bought 4 of them, all 4 were fine'],
]);

it('redacts email addresses and links', function (): void {
    $result = $this->screen->screen('Email me at parts@example.com or visit www.cheaperparts.co.zm');

    expect($result->text)->not->toContain('parts@example.com')
        ->and($result->text)->not->toContain('cheaperparts')
        ->and($result->flags)->toContain(ScreenFlag::EmailAddress)
        ->and($result->requiresReview())->toBeFalse();
});

it('holds a review containing a word from the list', function (): void {
    settings()->set('content.profanity_terms', ['scammer']);

    $result = $this->screen->screen('This seller is a scammer');

    expect($result->requiresReview())->toBeTrue()
        ->and(RatingStatus::forScreen($result))->toBe(RatingStatus::PendingReview)
        ->and($result->flags)->toContain(ScreenFlag::Profanity);
});

it('does not flag a listed word buried inside a longer one', function (): void {
    settings()->set('content.profanity_terms', ['ass']);

    expect($this->screen->screen('The gasket and the chassis were fine')->requiresReview())
        ->toBeFalse();
});

it('treats an empty body as clean', function (): void {
    expect($this->screen->screen(null)->isClean())->toBeTrue()
        ->and($this->screen->screen('')->isClean())->toBeTrue();
});

it('stores the redacted text on the rating and never the original', function (): void {
    $buyer = User::factory()->create();
    $seller = Seller::factory()->create();
    $order = Order::factory()->completed()->create([
        'user_id' => $buyer->getKey(),
        'seller_id' => $seller->getKey(),
    ]);

    $rating = app(RatingService::class)->submit(
        $order,
        RatingDirection::BuyerToSeller,
        $buyer,
        5,
        'Good shop. Call me on 0977123456 if you want the same part.',
    );

    expect($rating->body)->not->toContain('0977123456')
        ->and($rating->status)->toBe(RatingStatus::Published)
        ->and($rating->wasRedacted())->toBeTrue();

    /* Nothing in the row is holding the number for later. */
    expect(json_encode($rating->getAttributes()))->not->toContain('0977123456');
});

it('queues a review the screen could not fix, and still counts it', function (): void {
    settings()->set('content.profanity_terms', ['thief']);

    $buyer = User::factory()->create();
    $seller = Seller::factory()->create();
    $order = Order::factory()->completed()->create([
        'user_id' => $buyer->getKey(),
        'seller_id' => $seller->getKey(),
    ]);

    $rating = app(RatingService::class)->submit(
        $order,
        RatingDirection::BuyerToSeller,
        $buyer,
        1,
        'This man is a thief',
    );

    expect($rating->status)->toBe(RatingStatus::PendingReview)
        ->and($rating->published_at)->toBeNull()
        /* A shop cannot bury a bad review by getting it queued. */
        ->and(app(RatingService::class)->aggregateFor($seller)->count)->toBe(1);
});
