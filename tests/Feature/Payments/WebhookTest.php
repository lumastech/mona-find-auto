<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\FakePaymentGateway;
use App\Modules\Payments\Jobs\ProcessLencoWebhook;
use App\Modules\Payments\Models\LencoWebhookEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CollectionService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * The webhook endpoint.
 *
 * What is being protected here is the front door to the money path: an
 * unsigned request must never reach the database, and a redelivery must never
 * be acted on twice.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    $this->collections = app(CollectionService::class);
});

/**
 * Post a body to the webhook with whatever signature is given.
 */
function postWebhook(array $payload, ?string $signature = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call(
        'POST',
        route('webhooks.lenco'),
        [],
        [],
        [],
        array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LENCO_SIGNATURE' => $signature,
        ]),
        $body,
    );
}

function collectionEvent(string $reference, string $event = 'collection.successful', ?string $id = null): array
{
    return [
        'event' => $event,
        'data' => [
            'id' => $id ?? 'col_'.substr(md5($reference), 0, 12),
            'reference' => $reference,
            'status' => $event === 'collection.successful' ? 'successful' : 'failed',
            'amount' => '1000.00',
        ],
    ];
}

it('rejects a webhook with no signature and stores nothing', function (): void {
    $payload = collectionEvent('MFA-ABC-1');

    postWebhook($payload)->assertStatus(401);

    expect(LencoWebhookEvent::count())->toBe(0);
});

it('rejects a webhook with a forged signature and stores nothing', function (): void {
    $payload = collectionEvent('MFA-ABC-1');

    postWebhook($payload, 'not-a-real-signature')->assertStatus(401);

    expect(LencoWebhookEvent::count())->toBe(0);
});

/**
 * The signature covers the raw bytes, so a body re-encoded after signing must
 * not verify — that is what stops a tampered payload riding a valid header.
 */
it('rejects a signature computed over a different body', function (): void {
    $signed = json_encode(collectionEvent('MFA-ABC-1'), JSON_THROW_ON_ERROR);
    $signature = $this->gateway->signatureFor($signed);

    postWebhook(collectionEvent('MFA-TAMPERED-1'), $signature)->assertStatus(401);

    expect(LencoWebhookEvent::count())->toBe(0);
});

it('accepts and queues a correctly signed webhook', function (): void {
    Queue::fake();

    $payload = collectionEvent('MFA-ABC-1');
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    postWebhook($payload, $this->gateway->signatureFor($body))
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => false]);

    expect(LencoWebhookEvent::count())->toBe(1)
        ->and(LencoWebhookEvent::sole()->reference)->toBe('MFA-ABC-1');

    Queue::assertPushed(ProcessLencoWebhook::class);
});

/**
 * Lenco redelivers anything it is not sure we got. The second delivery has to
 * be acknowledged and dropped, not processed again.
 */
it('stores and queues a redelivered webhook exactly once', function (): void {
    Queue::fake();

    $payload = collectionEvent('MFA-ABC-1');
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = $this->gateway->signatureFor($body);

    postWebhook($payload, $signature)->assertOk()->assertJson(['duplicate' => false]);
    postWebhook($payload, $signature)->assertOk()->assertJson(['duplicate' => true]);
    postWebhook($payload, $signature)->assertOk()->assertJson(['duplicate' => true]);

    expect(LencoWebhookEvent::count())->toBe(1);

    Queue::assertPushed(ProcessLencoWebhook::class, 1);
});

it('settles the order group when a collection.successful webhook is processed', function (): void {
    $group = payableGroup();
    $attempt = $this->collections->begin($group);
    $this->gateway->settleCollection($attempt['reference']);

    $payload = collectionEvent($attempt['reference']);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    postWebhook($payload, $this->gateway->signatureFor($body))->assertOk();

    expect($group->refresh()->isPaid())->toBeTrue()
        ->and(LencoWebhookEvent::sole()->isProcessed())->toBeTrue();
});

/**
 * A signed body is proof of origin, not proof of content. The processor
 * re-reads the collection from the gateway and believes that instead.
 */
it('believes the gateway rather than the webhook body', function (): void {
    $group = payableGroup();
    $attempt = $this->collections->begin($group);

    /* The gateway has NOT settled this, whatever the body claims. */
    $payload = collectionEvent($attempt['reference']);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    postWebhook($payload, $this->gateway->signatureFor($body))->assertOk();

    expect($group->refresh()->isPaid())->toBeFalse()
        ->and(Payment::currentFor($attempt['reference'])?->status)->toBe(PaymentStatus::Pending);
});

it('marks an unknown event type processed rather than retrying it forever', function (): void {
    $payload = [
        'event' => 'something.lenco.added.later',
        'data' => ['id' => 'x-1', 'reference' => 'MFA-ABC-1'],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    postWebhook($payload, $this->gateway->signatureFor($body))->assertOk();

    expect(LencoWebhookEvent::sole()->isProcessed())->toBeTrue();
});

it('refuses a malformed body that is correctly signed', function (): void {
    $body = 'not json at all';

    $this->call(
        'POST',
        route('webhooks.lenco'),
        [],
        [],
        [],
        ['HTTP_X_LENCO_SIGNATURE' => $this->gateway->signatureFor($body), 'CONTENT_TYPE' => 'application/json'],
        $body,
    )->assertStatus(400);

    expect(LencoWebhookEvent::count())->toBe(0);
});

it('identifies an event by lenco id, falling back to a body fingerprint', function (): void {
    $withId = ['event' => 'collection.successful', 'data' => ['id' => 'col-1']];
    $withoutId = ['event' => 'collection.successful', 'data' => ['reference' => 'MFA-X-1']];

    expect(LencoWebhookEvent::identify($withId, json_encode($withId)))
        ->toBe('collection.successful:col-1')
        ->and(LencoWebhookEvent::identify($withoutId, '{"a":1}'))
        ->toBe('collection.successful:sha256:'.hash('sha256', '{"a":1}'));
});
