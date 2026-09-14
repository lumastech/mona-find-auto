<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\ThreadService;
use App\Modules\Messaging\Support\ThreadParties;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * /api/v1/notifications and /api/v1/threads — the mobile app's half.
 *
 * The rules being checked are the same ones the web enforces, which is the
 * point: the policy and the router are shared, so the app cannot end up with
 * a laxer version of "who may read this conversation".
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->threads = app(ThreadService::class);

    $this->buyer = User::factory()->create(['phone_verified_at' => now()]);
    $this->seller = Seller::factory()->create();
    $this->listing = Product::factory()->ofSeller($this->seller)->create();
});

function apiThread(): MessageThread
{
    return test()->threads->openWith(
        test()->listing,
        ThreadParties::buyerAndSeller(test()->buyer, test()->seller),
    );
}

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

it('refuses the notification list without a token', function (): void {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
});

it('answers with notifications in the platform envelope', function (): void {
    Notification::fake()->assertNothingSent();

    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['pagination', 'unread_count']]);
});

it('lists a notification in the shape the app renders', function (): void {
    $this->buyer->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['type' => 'orders.placed', 'title' => 'New order', 'body' => 'MFA-1.'],
        'read_at' => null,
    ]);

    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'New order')
        ->assertJsonPath('data.0.event', 'orders.placed')
        ->assertJsonPath('meta.unread_count', 1);
});

it('marks one notification read', function (): void {
    $notification = $this->buyer->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['title' => 'New order'],
        'read_at' => null,
    ]);

    Sanctum::actingAs($this->buyer);

    $this->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);
});

it('will not let one account mark another\'s notification read', function (): void {
    $notification = $this->buyer->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['title' => 'New order'],
        'read_at' => null,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'notification_not_found');

    expect($notification->fresh()->read_at)->toBeNull();
});

it('reads and writes preferences over the API', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/v1/notifications/preferences')
        ->assertOk()
        ->assertJsonStructure(['data' => [['group', 'events']]]);

    $this->putJson('/api/v1/notifications/preferences', [
        'preferences' => [
            NotificationEvent::OrderPlaced->value => [
                NotificationChannel::Sms->value => false,
            ],
        ],
    ])->assertOk();

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $this->buyer->id,
        'event' => NotificationEvent::OrderPlaced->value,
        'channel' => NotificationChannel::Sms->value,
        'enabled' => 0,
    ]);
});

/*
|--------------------------------------------------------------------------
| Threads
|--------------------------------------------------------------------------
*/

it('refuses the thread list without a token', function (): void {
    $this->getJson('/api/v1/threads')->assertUnauthorized();
});

it('lists only the caller\'s own conversations', function (): void {
    apiThread();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/threads')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/v1/threads')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('answers with the shop\'s inbox when asked as a seller', function (): void {
    apiThread();

    Sanctum::actingAs($this->seller->user);

    $this->getJson('/api/v1/threads?role=seller')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses the seller inbox to an account with no shop', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/v1/threads?role=seller')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'not_a_seller');
});

it('blocks a non-participant from reading a conversation', function (): void {
    $thread = apiThread();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/threads/{$thread->id}")->assertForbidden();
});

it('opens a conversation about a listing and posts the first message', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/v1/threads', [
        'subject_type' => 'listing',
        'subject_id' => $this->listing->id,
        'body' => 'Does this fit a 2014 Hilux?',
    ])
        ->assertCreated()
        ->assertJsonPath('data.messages.0.body', 'Does this fit a 2014 Hilux?')
        ->assertJsonPath('data.allows_contact_details', false);
});

it('finds the existing conversation rather than opening a second', function (): void {
    apiThread();

    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/v1/threads', [
        'subject_type' => 'listing',
        'subject_id' => $this->listing->id,
    ])->assertCreated();

    expect(MessageThread::query()->count())->toBe(1);
});

it('refuses a subject type that is not on the list', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/v1/threads', [
        'subject_type' => 'App\\Models\\User',
        'subject_id' => 1,
    ])->assertUnprocessable();
});

it('redacts a phone number posted before the order is paid', function (): void {
    $thread = apiThread();

    Sanctum::actingAs($this->buyer);

    $this->postJson("/api/v1/threads/{$thread->id}/messages", [
        'body' => 'Ring me on 0977123456.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.was_redacted', true)
        ->assertJsonMissing(['body' => 'Ring me on 0977123456.']);
});

it('leaves a phone number alone once the order is paid', function (): void {
    $order = Order::factory()
        ->forBuyer($this->buyer)
        ->forSeller($this->seller)
        ->create(['paid_at' => now()]);

    $thread = $this->threads->openFor($order);

    Sanctum::actingAs($this->buyer);

    $this->postJson("/api/v1/threads/{$thread->id}/messages", [
        'body' => 'Ring me on 0977123456.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Ring me on 0977123456.')
        ->assertJsonPath('data.was_redacted', false);
});

it('refuses a message from somebody outside the conversation', function (): void {
    $thread = apiThread();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/threads/{$thread->id}/messages", ['body' => 'Let me in.'])
        ->assertForbidden();
});

it('clears the unread count when the app reads a thread', function (): void {
    $thread = apiThread();
    $this->threads->post($thread, $this->buyer, 'Is this available?');

    Sanctum::actingAs($this->seller->user);

    $this->postJson("/api/v1/threads/{$thread->id}/read")
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);
});
