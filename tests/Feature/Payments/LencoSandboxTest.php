<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\LencoGateway;
use App\Support\Money\Money;
use Illuminate\Support\Sleep;

/**
 * The one test that talks to Lenco for real.
 *
 * ## Why it is gated off
 *
 * Every other test in this module runs against the in-memory fake and proves
 * our logic. This one proves something the fake cannot: that the REQUEST AND
 * RESPONSE SHAPES are right — that Lenco accepts the reference format we
 * build, the decimal amount we send and the operator names we use, and that
 * what comes back still parses.
 *
 * It needs live sandbox credentials, takes real seconds, and depends on a
 * third party being up, so it is excluded from CI and skipped unless
 * `LENCO_SANDBOX_TESTS=true` and a sandbox secret key are both present.
 *
 * ## Running it
 *
 *     LENCO_SANDBOX_TESTS=true \
 *     LENCO_SECRET_KEY=<sandbox secret> \
 *     LENCO_ACCOUNT_ID=<sandbox account uuid> \
 *     vendor/bin/pest tests/Feature/Payments/LencoSandboxTest.php
 *
 * The mobile-money leg uses Lenco's documented sandbox wallet 0961111111
 * (MTN), which always approves. The payout leg is opt-in on top
 * (`LENCO_SANDBOX_PAYOUT_TESTS`) because it moves a real sandbox balance and
 * needs a funded account; without it the transfer half is skipped rather than
 * failing on an empty account and looking like a code fault.
 */
beforeEach(function (): void {
    if (env('LENCO_SANDBOX_TESTS') !== true && env('LENCO_SANDBOX_TESTS') !== 'true') {
        test()->markTestSkipped('Set LENCO_SANDBOX_TESTS=true to run the live Lenco sandbox test.');
    }

    if (blank(env('LENCO_SECRET_KEY'))) {
        test()->markTestSkipped('LENCO_SECRET_KEY is not set.');
    }

    config()->set('integrations.payment_gateway.driver', 'lenco');
    config()->set('lenco.environment', 'sandbox');
    config()->set('lenco.secret_key', env('LENCO_SECRET_KEY'));
    config()->set('lenco.account_id', env('LENCO_ACCOUNT_ID'));

    $this->app->forgetInstance(PaymentGateway::class);

    $this->gateway = app(PaymentGateway::class);

    expect($this->gateway)->toBeInstanceOf(LencoGateway::class);
});

/**
 * Poll until the gateway reaches a terminal state or we run out of patience.
 */
function awaitCollection(PaymentGateway $gateway, string $reference, int $seconds = 60): PaymentStatus
{
    $deadline = time() + $seconds;

    do {
        $collection = $gateway->fetchCollection($reference);

        if ($collection->status->isFinal()) {
            return $collection->status;
        }

        Sleep::for(3)->seconds();
    } while (time() < $deadline);

    return PaymentStatus::Pending;
}

it('collects from a sandbox mobile-money wallet and verifies it by reference', function (): void {
    $reference = $this->gateway->reference('SBX'.strtoupper(bin2hex(random_bytes(4))), 1);
    $amount = Money::ofKwacha('5.00');

    /* Lenco's documented always-successful Zambian MTN sandbox wallet. */
    $collection = $this->gateway->collectMobileMoney($amount, $reference, '0961111111', 'mtn');

    expect($collection->reference)->toBe($reference)
        ->and($collection->status)->not->toBe(PaymentStatus::Failed);

    $status = awaitCollection($this->gateway, $reference);

    expect($status)->toBe(PaymentStatus::Successful);

    /* Verify-by-reference, the path every confirmation route goes through. */
    $verified = $this->gateway->fetchCollection($reference);

    expect($verified->status->isSettled())->toBeTrue()
        ->and($verified->amount->ngwee)->toBe($amount->ngwee)
        ->and($verified->gatewayId)->not->toBeNull();
})->group('lenco-sandbox');

it('resolves a real sandbox mobile-money account and registers it as a recipient', function (): void {
    $resolved = $this->gateway->resolveMobileMoney('0961111111', 'mtn');

    expect($resolved)->not->toBeNull()
        ->and($resolved->accountName)->not->toBe('')
        ->and($resolved->isMobileMoney())->toBeTrue();

    $recipientId = $this->gateway->createTransferRecipient($resolved);

    expect($recipientId)->not->toBe('');
})->group('lenco-sandbox');

it('lists the banks Lenco can pay out to in Zambia', function (): void {
    $banks = $this->gateway->listBanks();

    expect($banks)->not->toBeEmpty()
        ->and($banks[0]->code)->not->toBe('')
        ->and($banks[0]->name)->not->toBe('');
})->group('lenco-sandbox');

it('pays out to a sandbox wallet and tracks the transfer to completion', function (): void {
    if (env('LENCO_SANDBOX_PAYOUT_TESTS') !== true && env('LENCO_SANDBOX_PAYOUT_TESTS') !== 'true') {
        test()->markTestSkipped('Set LENCO_SANDBOX_PAYOUT_TESTS=true; this moves a real sandbox balance.');
    }

    $reference = 'PO-SBX-'.strtoupper(bin2hex(random_bytes(4)));

    $transfer = $this->gateway->initiateTransfer(
        Money::ofKwacha('1.00'),
        $reference,
        ['phone' => '0961111111', 'network' => 'mtn', 'narration' => 'MonaFind sandbox payout'],
    );

    expect($transfer->reference)->toBe($reference)
        ->and($transfer->status)->not->toBe(PaymentStatus::Failed);

    $deadline = time() + 60;

    do {
        $latest = $this->gateway->fetchTransfer($reference);

        if ($latest->status->isFinal()) {
            break;
        }

        Sleep::for(3)->seconds();
    } while (time() < $deadline);

    expect($latest->status)->toBe(PaymentStatus::Successful);
})->group('lenco-sandbox');
