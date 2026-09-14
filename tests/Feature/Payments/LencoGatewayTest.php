<?php

declare(strict_types=1);

use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\Data\ResolvedAccount;
use App\Integrations\Payments\Exceptions\LencoRequestFailed;
use App\Integrations\Payments\LencoGateway;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The Lenco client, against a faked HTTP layer.
 *
 * The response bodies below are copied from Lenco's v2 reference rather than
 * invented, because the whole value of this class is that it reads THEIR
 * shapes correctly — a test built on a shape we made up would pass against a
 * client that cannot talk to the real gateway.
 */
function lenco(array $overrides = []): LencoGateway
{
    return new LencoGateway(
        baseUrl: $overrides['baseUrl'] ?? 'https://api.lenco.test/access/v2',
        secretKey: $overrides['secretKey'] ?? 'sk_test_secret',
        accountId: $overrides['accountId'] ?? 'acct-1',
        country: 'zm',
        retryTimes: 1,
        retrySleepMs: 0,
        webhookSecret: $overrides['webhookSecret'] ?? null,
    );
}

/**
 * A Lenco collection object, as /collections/status/:reference returns it.
 */
function lencoCollection(array $overrides = []): array
{
    return [
        'status' => true,
        'message' => '',
        'data' => array_merge([
            'id' => 'col-9525b4c6',
            'initiatedAt' => '2026-09-11T09:00:00.000Z',
            'completedAt' => '2026-09-11T09:01:00.000Z',
            'amount' => '1234.56',
            'fee' => '18.52',
            'bearer' => 'merchant',
            'currency' => 'ZMW',
            'reference' => 'MFA-01JABC-1',
            'lencoReference' => '240010002',
            'type' => 'mobile-money',
            'status' => 'successful',
            'source' => 'api',
            'reasonForFailure' => null,
            'settlementStatus' => 'pending',
            'settlement' => null,
        ], $overrides),
    ];
}

it('reads a successful collection in ngwee without touching a float', function (): void {
    Http::fake([
        '*/collections/status/*' => Http::response(lencoCollection()),
    ]);

    $collection = lenco()->fetchCollection('MFA-01JABC-1');

    expect($collection->status)->toBe(PaymentStatus::Successful)
        ->and($collection->amount->ngwee)->toBe(123_456)
        ->and($collection->fee?->ngwee)->toBe(1_852)
        ->and($collection->gatewayId)->toBe('col-9525b4c6');
});

it('reads a failed collection with the gateway reason', function (): void {
    Http::fake([
        '*/collections/status/*' => Http::response(lencoCollection([
            'status' => 'failed',
            'reasonForFailure' => 'Insufficient funds',
            'fee' => null,
        ])),
    ]);

    $collection = lenco()->fetchCollection('MFA-01JABC-1');

    expect($collection->status)->toBe(PaymentStatus::Failed)
        ->and($collection->failureReason)->toBe('Insufficient funds')
        ->and($collection->fee)->toBeNull();
});

/**
 * The mapping that matters most: a customer who has not finished is not a
 * customer who failed. Both of these arrive while a payment is still live.
 */
it('treats pay-offline and 3ds-auth-required as pending, not failure', function (string $status): void {
    Http::fake([
        '*/collections/status/*' => Http::response(lencoCollection(['status' => $status])),
    ]);

    expect(lenco()->fetchCollection('MFA-01JABC-1')->status)->toBe(PaymentStatus::Pending);
})->with(['pay-offline', '3ds-auth-required', 'something-new-lenco-added']);

it('treats an unknown reference as pending rather than failed', function (): void {
    Http::fake([
        '*/collections/status/*' => Http::response([
            'status' => false,
            'message' => 'Collection not found',
            'data' => null,
        ], 400),
    ]);

    $collection = lenco()->fetchCollection('MFA-NOPE-1');

    expect($collection->status)->toBe(PaymentStatus::Pending)
        ->and($collection->failureReason)->toBe('Collection not found');
});

it('pushes a mobile-money collection as decimal kwacha', function (): void {
    Http::fake([
        '*/collections/mobile-money' => Http::response(lencoCollection(['status' => 'pay-offline'])),
    ]);

    $collection = lenco()->collectMobileMoney(
        Money::ofNgwee(123_456),
        'MFA-01JABC-1',
        '260971234567',
        'mtn',
    );

    expect($collection->status)->toBe(PaymentStatus::Pending);

    Http::assertSent(function ($request): bool {
        return $request['amount'] === 1234.56
            && $request['reference'] === 'MFA-01JABC-1'
            && $request['operator'] === 'mtn'
            && $request['country'] === 'zm';
    });
});

it('raises a refused collection rather than reporting a phantom pending', function (): void {
    Http::fake([
        '*/collections/mobile-money' => Http::response([
            'status' => false,
            'message' => 'Duplicate reference',
            'data' => null,
        ], 400),
    ]);

    lenco()->collectMobileMoney(Money::ofNgwee(1000), 'MFA-01JABC-1', '260971234567', 'mtn');
})->throws(LencoRequestFailed::class, 'Duplicate reference');

it('sends a bank transfer with the recipient id and the account details', function (): void {
    Http::fake([
        '*/transfers/bank-account' => Http::response([
            'status' => true,
            'message' => '',
            'data' => [
                'id' => 'trf-1',
                'amount' => '20.00',
                'fee' => '8.50',
                'reference' => 'PO-1-7',
                'status' => 'successful',
                'reasonForFailure' => null,
            ],
        ]),
    ]);

    $transfer = lenco()->initiateTransfer(Money::ofNgwee(2_000), 'PO-1-7', [
        'recipient_id' => 'rcp-1',
        'account_number' => '9130000000000',
        'bank_code' => '002',
        'narration' => 'MonaFind payout',
    ]);

    expect($transfer->status)->toBe(PaymentStatus::Successful)
        ->and($transfer->fee?->ngwee)->toBe(850);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/transfers/bank-account')
            && $request['accountId'] === 'acct-1'
            && $request['transferRecipientId'] === 'rcp-1'
            && $request['accountNumber'] === '9130000000000'
            && $request['bankId'] === '002'
            && $request['amount'] === 20.0;
    });
});

it('routes a transfer to mobile money when the recipient has a wallet', function (): void {
    Http::fake([
        '*/transfers/mobile-money' => Http::response([
            'status' => true,
            'message' => '',
            'data' => ['id' => 'trf-2', 'amount' => '50.00', 'status' => 'pending', 'reference' => 'PO-1-8'],
        ]),
    ]);

    lenco()->initiateTransfer(Money::ofNgwee(5_000), 'PO-1-8', [
        'phone' => '260971234567',
        'network' => 'airtel',
    ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/transfers/mobile-money')
        && $request['phone'] === '260971234567'
        && $request['operator'] === 'airtel');
});

/**
 * A transfer that may or may not have happened must not be reported as
 * failed — that would revert a payable the platform might really have paid.
 */
it('raises rather than reports failure when the gateway is unreachable on a transfer', function (): void {
    Http::fake(['*/transfers/bank-account' => Http::response('gateway down', 503)]);

    lenco()->initiateTransfer(Money::ofNgwee(2_000), 'PO-1-9', ['account_number' => '91300', 'bank_code' => '002']);
})->throws(LencoRequestFailed::class, 'unreachable');

it('resolves a bank account to the name the bank holds', function (): void {
    Http::fake([
        '*/resolve/bank-account' => Http::response([
            'status' => true,
            'message' => '',
            'data' => [
                'type' => 'bank-account',
                'accountName' => 'MULENGA BANDA LTD',
                'accountNumber' => '9130000000000',
                'bank' => ['id' => '002', 'name' => 'Absa Bank', 'country' => 'zm'],
            ],
        ]),
    ]);

    $resolved = lenco()->resolveBankAccount('9130000000000', '002');

    expect($resolved?->accountName)->toBe('MULENGA BANDA LTD')
        ->and($resolved?->bankName)->toBe('Absa Bank')
        ->and($resolved?->isMobileMoney())->toBeFalse();
});

it('returns null for an account the gateway does not recognise', function (): void {
    Http::fake([
        '*/resolve/bank-account' => Http::response([
            'status' => false,
            'message' => 'Account details was not found',
            'data' => null,
        ], 400),
    ]);

    expect(lenco()->resolveBankAccount('0000', '002'))->toBeNull();
});

it('creates a mobile-money transfer recipient and returns its id', function (): void {
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response([
            'status' => true,
            'message' => '',
            'data' => ['id' => 'd4f71d4a-eda4-4237-9976-5cbdc8a54cf3', 'type' => 'mobile-money'],
        ]),
    ]);

    $id = lenco()->createTransferRecipient(new ResolvedAccount(
        accountName: 'BEATA JEAN',
        accountNumber: '260971234567',
        network: 'airtel',
    ));

    expect($id)->toBe('d4f71d4a-eda4-4237-9976-5cbdc8a54cf3');
});

it('lists banks from the gateway', function (): void {
    Http::fake([
        '*/banks*' => Http::response([
            'status' => true,
            'message' => '',
            'data' => [
                ['id' => '002', 'name' => 'Absa Bank', 'country' => 'zm'],
                ['id' => '003', 'name' => 'Zanaco', 'country' => 'zm'],
            ],
        ]),
    ]);

    expect(lenco()->listBanks())->toHaveCount(2)
        ->and(lenco()->listBanks()[0]->name)->toBe('Absa Bank');
});

it('walks every page of a dated listing', function (): void {
    $page = 0;

    Http::fake(['*/collections*' => function () use (&$page) {
        $page++;

        return Http::response([
            'status' => true,
            'message' => '',
            'data' => [lencoCollection(['reference' => "MFA-P{$page}-1"])['data']],
            'meta' => ['total' => 2, 'pageCount' => 2, 'perPage' => 1, 'currentPage' => $page],
        ]);
    }]);

    $collections = lenco()->collectionsBetween(Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'));

    expect($collections)->toHaveCount(2)
        ->and($collections[1]->reference)->toBe('MFA-P2-1');
});

it('reads settlements with the collection reference that ties them to an order', function (): void {
    Http::fake([
        '*/settlements*' => Http::response([
            'status' => true,
            'message' => '',
            'data' => [[
                'id' => 'stl-1',
                'amountSettled' => '1216.04',
                'currency' => 'ZMW',
                'status' => 'settled',
                'settledAt' => '2026-09-11T18:00:00.000Z',
                'collection' => ['reference' => 'MFA-01JABC-1', 'amount' => '1234.56'],
            ]],
            'meta' => ['currentPage' => 1, 'pageCount' => 1],
        ]),
    ]);

    $settlements = lenco()->settlementsBetween(Carbon::parse('2026-09-11'), Carbon::parse('2026-09-11'));

    expect($settlements[0]->amountSettled->ngwee)->toBe(121_604)
        ->and($settlements[0]->collectionReference)->toBe('MFA-01JABC-1')
        ->and($settlements[0]->isSettled())->toBeTrue();
});

it('signs the gateway account statement the way the ledger reads it', function (): void {
    Http::fake([
        '*/transactions*' => Http::response([
            'status' => true,
            'message' => '',
            'data' => [
                ['id' => 'tx-1', 'amount' => '100.00', 'type' => 'credit', 'datetime' => '2026-09-11T10:00:00.000Z'],
                ['id' => 'tx-2', 'amount' => '40.00', 'type' => 'debit', 'datetime' => '2026-09-11T11:00:00.000Z'],
            ],
            'meta' => ['currentPage' => 1, 'pageCount' => 1],
        ]),
    ]);

    $transactions = lenco()->transactionsBetween(Carbon::parse('2026-09-11'), Carbon::parse('2026-09-11'));

    expect($transactions[0]->signedAmount()->ngwee)->toBe(10_000)
        ->and($transactions[1]->signedAmount()->ngwee)->toBe(-4_000);
});

it('verifies a webhook signature with HMAC-SHA512 and rejects a forged one', function (): void {
    $gateway = lenco(['webhookSecret' => 'hash-key']);
    $payload = '{"event":"collection.successful"}';

    expect($gateway->verifyWebhookSignature($payload, hash_hmac('sha512', $payload, 'hash-key')))->toBeTrue()
        ->and($gateway->verifyWebhookSignature($payload, 'deadbeef'))->toBeFalse()
        ->and($gateway->verifyWebhookSignature($payload, hash_hmac('sha256', $payload, 'hash-key')))->toBeFalse();
});

it('derives the webhook secret from the api token when none is configured', function (): void {
    $gateway = lenco(['secretKey' => 'sk_live_abc', 'webhookSecret' => null]);
    $payload = '{"event":"collection.failed"}';

    $expected = hash_hmac('sha512', $payload, hash('sha256', 'sk_live_abc'));

    expect($gateway->verifyWebhookSignature($payload, $expected))->toBeTrue();
});

it('builds references Lenco will accept', function (): void {
    expect(lenco()->reference('01JABCDEF', 1))->toBe('MFA-01JABCDEF-1')
        ->and(lenco()->reference('01J/ABC DEF', 3))->toBe('MFA-01JABCDEF-3')
        ->and(lenco()->reference('01JABCDEF', 2))->toMatch('/^[A-Za-z0-9._-]+$/');
});

it('never sends the secret key anywhere but the authorization header', function (): void {
    Http::fake(['*' => Http::response(lencoCollection())]);

    lenco(['secretKey' => 'sk_live_supersecret'])->fetchCollection('MFA-01JABC-1');

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer sk_live_supersecret')
            && ! str_contains($request->url(), 'supersecret')
            && ! str_contains($request->body(), 'supersecret');
    });
});
