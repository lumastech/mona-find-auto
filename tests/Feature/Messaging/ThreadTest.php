<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Enums\ThreadRole;
use App\Modules\Messaging\Exceptions\NotAParticipant;
use App\Modules\Messaging\Exceptions\ThreadClosed;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Notifications\NewMessageNotification;
use App\Modules\Messaging\Services\ThreadService;
use App\Modules\Messaging\Support\ThreadParties;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Sellers\Models\Seller;
use App\Support\Content\ScreenFlag;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->threads = app(ThreadService::class);

    $this->buyer = User::factory()->create();
    $this->seller = Seller::factory()->create();
    $this->listing = Product::factory()->ofSeller($this->seller)->create();
});

/** A listing conversation between this test's buyer and shop. */
function listingThread(): MessageThread
{
    return test()->threads->openWith(
        test()->listing,
        ThreadParties::buyerAndSeller(test()->buyer, test()->seller),
    );
}

/*
|--------------------------------------------------------------------------
| Opening
|--------------------------------------------------------------------------
*/

it('opens one conversation per subject per pair, however many times it is asked', function (): void {
    $first = listingThread();
    $second = listingThread();

    expect($second->id)->toBe($first->id)
        ->and(MessageThread::query()->count())->toBe(1);
});

it('gives a different buyer their own conversation about the same listing', function (): void {
    listingThread();

    $other = User::factory()->create();
    $this->threads->openWith($this->listing, ThreadParties::buyerAndSeller($other, $this->seller));

    expect(MessageThread::query()->count())->toBe(2);
});

it('reads the parties off an order without being told them', function (): void {
    $order = Order::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();

    $thread = $this->threads->openFor($order);

    expect($thread->hasParticipant($this->buyer))->toBeTrue()
        ->and($thread->hasParticipant($this->seller->user))->toBeTrue()
        ->and($thread->subject_label)->toBe('Order '.$order->number);
});

/*
|--------------------------------------------------------------------------
| Authorisation
|--------------------------------------------------------------------------
*/

it('lets a participant read and write', function (): void {
    $thread = listingThread();

    expect($this->buyer->can('view', $thread))->toBeTrue()
        ->and($this->buyer->can('reply', $thread))->toBeTrue()
        ->and($this->seller->user->can('view', $thread))->toBeTrue();
});

it('blocks a stranger from reading or writing', function (): void {
    $thread = listingThread();
    $stranger = User::factory()->create();

    expect($stranger->can('view', $thread))->toBeFalse()
        ->and($stranger->can('reply', $thread))->toBeFalse();
});

it('blocks a stranger at the route as well as at the policy', function (): void {
    $thread = listingThread();

    $this->actingAs(User::factory()->create())
        ->get(route('threads.show', $thread))
        ->assertForbidden();
});

it('refuses a message from somebody who is not in the conversation', function (): void {
    $thread = listingThread();

    $this->threads->post($thread, User::factory()->create(), 'Let me in.');
})->throws(NotAParticipant::class);

it('keeps staff out of an ordinary conversation', function (): void {
    $thread = listingThread();
    $moderator = actingAsRole([Role::Moderator]);

    expect($moderator->can('view', $thread))->toBeFalse();
});

it('lets staff read the thread of an order that has a dispute against it', function (): void {
    $order = Order::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
    $thread = $this->threads->openFor($order);

    $moderator = actingAsRole([Role::Moderator]);
    expect($moderator->can('view', $thread))->toBeFalse();

    OrderDispute::factory()->create([
        'order_id' => $order->getKey(),
        'opened_by' => $this->buyer->getKey(),
    ]);

    expect($moderator->fresh()->can('view', $thread->fresh()))->toBeTrue()
        /* Read, never write: a moderator posting could manufacture evidence. */
        ->and($moderator->fresh()->can('reply', $thread->fresh()))->toBeFalse();
});

it('does not open the parties\' other conversations because one order is disputed', function (): void {
    $order = Order::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
    $this->threads->openFor($order);

    OrderDispute::factory()->create([
        'order_id' => $order->getKey(),
        'opened_by' => $this->buyer->getKey(),
    ]);

    $unrelated = listingThread();
    $moderator = actingAsRole([Role::Moderator]);

    expect($moderator->can('view', $unrelated))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Redaction
|--------------------------------------------------------------------------
*/

it('redacts contact details before the thread\'s order is paid', function (): void {
    $thread = listingThread();

    $message = $this->threads->post($thread, $this->buyer, 'Call me on 0977123456 and I will collect it.');

    expect($message->body)->not->toContain('0977123456')
        ->and($message->body)->toContain('[removed]')
        ->and($message->wasRedacted())->toBeTrue()
        ->and($message->screenFlags())->toContain(ScreenFlag::PhoneNumber);
});

it('leaves contact details alone once the order has been paid', function (): void {
    $order = Order::factory()
        ->forBuyer($this->buyer)
        ->forSeller($this->seller)
        ->create(['paid_at' => now()]);

    $thread = $this->threads->openFor($order);

    $message = $this->threads->post($thread, $this->buyer, 'Call me on 0977123456 and I will collect it.');

    expect($message->body)->toContain('0977123456')
        ->and($message->wasRedacted())->toBeFalse();
});

it('still redacts on an order that has not been paid for yet', function (): void {
    $order = Order::factory()
        ->forBuyer($this->buyer)
        ->forSeller($this->seller)
        ->create(['paid_at' => null]);

    $thread = $this->threads->openFor($order);

    expect($this->threads->post($thread, $this->buyer, 'Email me on chanda@example.test')->body)
        ->not->toContain('chanda@example.test');
});

it('delivers a message the screen flagged rather than holding it', function (): void {
    settings()->set('content.profanity_terms', ['scammer']);

    $thread = listingThread();
    $message = $this->threads->post($thread, $this->buyer, 'This seller is a scammer.');

    /* Flagged, and in the thread: holding it would break the conversation. */
    expect($message->exists)->toBeTrue()
        ->and($message->screenFlags())->toContain(ScreenFlag::Profanity)
        ->and($thread->fresh()->messages_count)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Unread
|--------------------------------------------------------------------------
*/

it('counts a thread as unread for everybody except the person who wrote', function (): void {
    $thread = listingThread();

    $this->threads->post($thread, $this->buyer, 'Is this still available?');

    $thread = $thread->fresh()->load('participants');

    expect($thread->isUnreadFor($this->seller->user))->toBeTrue()
        ->and($thread->isUnreadFor($this->buyer))->toBeFalse()
        ->and($this->threads->unreadCountFor($this->seller->user))->toBe(1)
        ->and($this->threads->unreadCountFor($this->buyer))->toBe(0);
});

it('clears the count when the other side opens it', function (): void {
    $thread = listingThread();
    $this->threads->post($thread, $this->buyer, 'Is this still available?');

    $this->threads->markRead($thread, $this->seller->user);

    expect($this->threads->unreadCountFor($this->seller->user))->toBe(0);
});

it('counts conversations rather than messages', function (): void {
    $thread = listingThread();

    $this->threads->post($thread, $this->buyer, 'One.');
    $this->threads->post($thread, $this->buyer, 'Two.');
    $this->threads->post($thread, $this->buyer, 'Three.');

    expect($this->threads->unreadCountFor($this->seller->user))->toBe(1);
});

it('marks a thread read when the recipient opens it in the browser', function (): void {
    $thread = listingThread();
    $this->threads->post($thread, $this->buyer, 'Is this still available?');

    $this->actingAs($this->seller->user)
        ->get(route('threads.show', $thread))
        ->assertOk();

    expect($this->threads->unreadCountFor($this->seller->user))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

it('tells the other side and not the author', function (): void {
    $thread = listingThread();

    $this->threads->post($thread, $this->buyer, 'Is this still available?');

    Notification::assertSentTo($this->seller->user, NewMessageNotification::class);
    Notification::assertNotSentTo($this->buyer, NewMessageNotification::class);
});

/*
|--------------------------------------------------------------------------
| Closing
|--------------------------------------------------------------------------
*/

it('refuses a message to a closed conversation', function (): void {
    $thread = listingThread();
    $this->threads->close($thread);

    $this->threads->post($thread->fresh()->load('participants'), $this->buyer, 'Anyone there?');
})->throws(ThreadClosed::class);

it('records which side each participant is on', function (): void {
    $thread = listingThread();

    expect($this->threads->roleFor($thread, $this->buyer))->toBe(ThreadRole::Buyer)
        ->and($this->threads->roleFor($thread, $this->seller->user))->toBe(ThreadRole::Seller);
});
