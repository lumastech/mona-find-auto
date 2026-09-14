<?php

declare(strict_types=1);

use App\Contracts\MapsProvider;
use App\Contracts\PaymentGateway;
use App\Contracts\SmsProvider;
use App\Integrations\Maps\Data\Coordinates;
use App\Integrations\Maps\FakeMapsProvider;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Sms\FakeSmsProvider;
use App\Integrations\Sms\LogSmsProvider;
use App\Support\Money\Money;

it('binds each integration to its configured driver', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class)
        ->and(app(SmsProvider::class))->toBeInstanceOf(FakeSmsProvider::class)
        ->and(app(MapsProvider::class))->toBeInstanceOf(FakeMapsProvider::class);
});

it('binds the log SMS provider when configured', function () {
    config()->set('integrations.sms.driver', 'log');
    app()->forgetInstance(SmsProvider::class);

    expect(app(SmsProvider::class))->toBeInstanceOf(LogSmsProvider::class);
});

it('refuses an unknown driver rather than falling back silently', function () {
    config()->set('integrations.maps.driver', 'atlas');
    app()->forgetInstance(MapsProvider::class);

    expect(fn () => app(MapsProvider::class))->toThrow(InvalidArgumentException::class);
});

it('builds payment references in the documented format', function () {
    expect(app(PaymentGateway::class)->reference('01JBX9', 2))->toBe('MFA-01JBX9-2')
        ->and(app(PaymentGateway::class)->reference('ab/cd 12', 1))->toBe('MFA-abcd12-1');
});

it('runs a collection through the fake gateway without touching the network', function () {
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $reference = $gateway->reference('01JBX9', 1);

    $started = $gateway->initiateCollection(Money::ofKwacha('1299.05'), $reference, ['name' => 'Buyer']);

    expect($started->status)->toBe(PaymentStatus::Pending)
        ->and($started->amount->ngwee)->toBe(129905)
        ->and($started->checkoutUrl)->toContain($reference);

    $gateway->settleCollection($reference, Money::ofKwacha('12.99'));

    $settled = $gateway->fetchCollection($reference);

    expect($settled->status)->toBe(PaymentStatus::Successful)
        ->and($settled->status->isSettled())->toBeTrue()
        ->and($settled->fee?->ngwee)->toBe(1299)
        ->and($gateway->calls('initiateCollection'))->toHaveCount(1);
});

/**
 * Pending, not failed — and deliberately so.
 *
 * A reference the gateway has not heard of yet is the normal state in the
 * seconds between a widget opening and the customer touching anything, and it
 * is what a collection driven entirely by the inline widget looks like from
 * the server before the first callback. LencoGateway maps it to Pending for
 * that reason, and the fake has to agree or code that cancels orders
 * mid-payment would pass its tests and fail in production.
 */
it('reports an unknown reference as pending rather than inventing a failure', function () {
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);

    $response = $gateway->fetchCollection('MFA-nope-1');

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->status->isFinal())->toBeFalse()
        ->and($response->amount->isZero())->toBeTrue();
});

it('verifies its own webhook signatures', function () {
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $payload = '{"event":"collection.successful"}';

    expect($gateway->verifyWebhookSignature($payload, $gateway->signatureFor($payload)))->toBeTrue()
        ->and($gateway->verifyWebhookSignature($payload, 'wrong'))->toBeFalse();
});

it('collects sent messages in the fake SMS provider', function () {
    /** @var FakeSmsProvider $sms */
    $sms = app(SmsProvider::class);

    $result = $sms->send('+260971234567', 'Your order is ready for pickup.');

    expect($result->accepted)->toBeTrue()
        ->and($sms->messagesTo('+260971234567'))->toHaveCount(1)
        ->and($sms->messages()[0]['message'])->toBe('Your order is ready for pickup.');
});

it('reports a rejected message', function () {
    /** @var FakeSmsProvider $sms */
    $sms = app(SmsProvider::class);

    $results = $sms->failNextMessages()->sendBulk(['+260971234567', '+260961234567'], 'Test');

    expect($results)->toHaveCount(2)
        ->and($results[0]->accepted)->toBeFalse()
        ->and($results[0]->failureReason)->not->toBeNull();
});

it('geocodes deterministically without the network', function () {
    /** @var FakeMapsProvider $maps */
    $maps = app(MapsProvider::class);

    $first = $maps->geocode('Kafue Road, Lusaka');
    $second = $maps->geocode('kafue road, lusaka');

    expect($first?->coordinates->latitude)->toBe($second?->coordinates->latitude)
        ->and($first?->locality)->toBe('Lusaka');
});

it('honours a stubbed address', function () {
    /** @var FakeMapsProvider $maps */
    $maps = app(MapsProvider::class);
    $maps->stubAddress('Cairo Road', new Coordinates(-15.4167, 28.2833));

    expect($maps->geocode('cairo road')?->coordinates->longitude)->toBe(28.2833);
});

it('estimates travel between two points', function () {
    /** @var FakeMapsProvider $maps */
    $maps = app(MapsProvider::class);

    $estimate = $maps->travelEstimate(
        new Coordinates(-15.4167, 28.2833),
        new Coordinates(-15.3875, 28.3228),
    );

    expect($estimate?->distanceInMetres)->toBeGreaterThan(0)
        ->and($estimate?->durationInSeconds)->toBeGreaterThan(0);
});
