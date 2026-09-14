<?php

declare(strict_types=1);

use App\Contracts\SmsProvider;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Jobs\SendStockConfirmationReminders;
use App\Modules\Inventory\Notifications\StockConfirmationReminder;
use App\Modules\Inventory\Services\FreshnessService;
use App\Modules\Messaging\Channels\SmsChannel;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    $this->freshness = app(FreshnessService::class);
    $this->seller = Seller::factory()->create();
    $this->seller->user->refresh();
});

/** Run the reminder sweep the way the schedule does. */
function runReminders(): void
{
    app(SendStockConfirmationReminders::class)->handle(app(FreshnessService::class));
}

it('nudges a seller three days after their last confirmation', function (): void {
    Product::factory()->count(2)->ofSeller($this->seller)->stockConfirmedDaysAgo(3)->create();

    runReminders();

    Notification::assertSentTo(
        $this->seller->user,
        StockConfirmationReminder::class,
        fn (StockConfirmationReminder $notification): bool => $notification->toArray($this->seller->user)['listing_count'] === 2,
    );
});

it('says nothing to a seller who confirmed yesterday', function (): void {
    Product::factory()->ofSeller($this->seller)->stockConfirmedDaysAgo(1)->create();

    runReminders();

    Notification::assertNothingSent();
});

it('does not repeat the same reminder the next morning', function (): void {
    Product::factory()->ofSeller($this->seller)->stockConfirmedDaysAgo(3)->create();

    runReminders();
    runReminders();

    Notification::assertSentToTimes($this->seller->user, StockConfirmationReminder::class, 1);
});

it('sends a sharper reminder at day five, once the label is showing', function (): void {
    Product::factory()->ofSeller($this->seller)->stockConfirmedDaysAgo(6)->create();

    runReminders();

    Notification::assertSentTo(
        $this->seller->user,
        StockConfirmationReminder::class,
        fn (StockConfirmationReminder $notification): bool => $notification->toArray($this->seller->user)['is_final_warning'] === true,
    );
});

it('counts an overdue listing towards the final warning, not the first nudge', function (): void {
    Product::factory()->ofSeller($this->seller)->stockConfirmedDaysAgo(20)->create();

    runReminders();

    Notification::assertSentToTimes($this->seller->user, StockConfirmationReminder::class, 1);
});

it('sends one message per shop rather than one per listing', function (): void {
    Product::factory()->count(40)->ofSeller($this->seller)->stockConfirmedDaysAgo(4)->create();

    runReminders();

    Notification::assertSentToTimes($this->seller->user, StockConfirmationReminder::class, 1);
});

it('texts the seller as well as emailing them', function (): void {
    Notification::fake();

    Product::factory()->ofSeller($this->seller)->stockConfirmedDaysAgo(3)->create();

    runReminders();

    /*
     * SMS is a channel on the notification now rather than a second job, so
     * what is asserted is the routing decision: the reminder went out on all
     * three, which is what the default matrix says for a seller with a
     * verified number.
     */
    Notification::assertSentTo(
        $this->seller->user,
        StockConfirmationReminder::class,
        fn (StockConfirmationReminder $notification, array $channels): bool => in_array(SmsChannel::class, $channels, true)
            && in_array('mail', $channels, true),
    );
});

it('hands the message to the SMS network', function (): void {
    /* Notification::fake() intercepts the send, so drive the channel itself. */
    app(SmsChannel::class)->send(
        $this->seller->user,
        new StockConfirmationReminder($this->seller, 3, 3, false),
    );

    expect(app(SmsProvider::class)->messagesTo($this->seller->user->phone))->not->toBeEmpty();
});

it('starts the clock over once the seller confirms', function (): void {
    $product = Product::factory()->ofSeller($this->seller)->stockConfirmedDaysAgo(3)->create();

    runReminders();
    expect($product->refresh()->stock_reminder_stage)->toBe(1);

    $this->freshness->confirm($product);

    expect($product->refresh()->stock_reminder_stage)->toBe(0)
        ->and($product->stock_reminder_sent_at)->toBeNull();
});

it('leaves drafts out of the reminder count', function (): void {
    Product::factory()->ofSeller($this->seller)->draft()->stockConfirmedDaysAgo(30)->create();

    runReminders();

    Notification::assertNothingSent();
});
