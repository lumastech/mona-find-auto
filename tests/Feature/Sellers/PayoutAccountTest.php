<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\FakePaymentGateway;
use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Sellers\Enums\PayoutMethod;
use App\Modules\Sellers\Exceptions\PayoutAccountUnresolved;
use App\Modules\Sellers\Models\PayoutAccount;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\PayoutAccountService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $this->gateway = $gateway;
    $this->accounts = app(PayoutAccountService::class);
    $this->seller = Seller::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bankAccountPayload(array $overrides = []): array
{
    return [
        'method' => PayoutMethod::Bank->value,
        'label' => 'Main account',
        'beneficiary_name' => 'Kabwata Motor Spares Limited',
        'account_number' => '0123456789',
        'bank_code' => '01',
        'bank_branch' => 'Cairo Road Branch',
        'swift_code' => 'ZANAZMLU',
        'tpin' => '1002003004',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mobileMoneyPayload(array $overrides = []): array
{
    return [
        'method' => PayoutMethod::MobileMoney->value,
        'mobile_number' => '0967123456',
        'network' => MobileNetwork::Mtn->value,
        ...$overrides,
    ];
}

it('stores the name the bank gave and the recipient payouts are addressed to', function () {
    $this->gateway->stubAccountName('0123456789', 'KABWATA MOTOR SPARES LTD');

    $account = $this->accounts->add($this->seller, bankAccountPayload());

    expect($account->resolved_name)->toBe('KABWATA MOTOR SPARES LTD')
        ->and($account->lenco_recipient_id)->toStartWith('fake_rcp_')
        ->and($account->isResolved())->toBeTrue()
        ->and($account->last_four)->toBe('6789');
});

it('blocks the save when the gateway does not recognise a bank account', function () {
    $this->gateway->resolutionFails();

    expect(fn () => $this->accounts->add($this->seller, bankAccountPayload()))
        ->toThrow(PayoutAccountUnresolved::class, 'We could not find that account number at Zanaco.');

    expect($this->seller->payoutAccounts()->count())->toBe(0);
});

it('blocks the save when the gateway does not recognise a mobile-money number', function () {
    $this->gateway->resolutionFails();

    expect(fn () => $this->accounts->add($this->seller, mobileMoneyPayload()))
        ->toThrow(PayoutAccountUnresolved::class, 'We could not find that MTN mobile-money number.');

    expect($this->seller->payoutAccounts()->count())->toBe(0);
});

it('refuses a bank the gateway cannot pay out to', function () {
    expect(fn () => $this->accounts->add($this->seller, bankAccountPayload(['bank_code' => '99'])))
        ->toThrow(PayoutAccountUnresolved::class, 'Choose one from the list.');
});

it('resolves a mobile-money number in E.164 whatever the seller typed', function () {
    $account = $this->accounts->add($this->seller, mobileMoneyPayload());

    expect($account->mobile_number)->toBe('+260967123456')
        ->and($account->network)->toBe(MobileNetwork::Mtn)
        ->and($this->gateway->calls('resolveMobileMoney'))->toHaveCount(1);
});

it('shows a clear error on the form when resolution fails', function () {
    $this->gateway->resolutionFails();

    $this->actingAs($this->seller->user)
        ->post(route('seller.payout-accounts.store'), bankAccountPayload())
        ->assertSessionHasErrors(['account_number' => 'We could not find that account number at Zanaco. Check the number and the bank, then try again.']);
});

it('makes the first account the default whatever was ticked', function () {
    $account = $this->accounts->add($this->seller, bankAccountPayload(['is_default' => false]));

    expect($account->is_default)->toBeTrue();
});

it('keeps exactly one account as the default', function () {
    $first = $this->accounts->add($this->seller, bankAccountPayload());
    $second = $this->accounts->add($this->seller, mobileMoneyPayload(['is_default' => true]));

    expect($second->refresh()->is_default)->toBeTrue()
        ->and($first->refresh()->is_default)->toBeFalse();
});

it('hands the default on when the default account is removed', function () {
    $first = $this->accounts->add($this->seller, bankAccountPayload());
    $second = $this->accounts->add($this->seller, mobileMoneyPayload());

    $this->accounts->remove($first);

    expect($this->seller->payoutAccounts()->count())->toBe(1)
        ->and($second->refresh()->is_default)->toBeTrue();
});

it('nulls the fields belonging to the other method', function () {
    $account = $this->accounts->add($this->seller, mobileMoneyPayload());

    expect($account->account_number)->toBeNull()
        ->and($account->bank_code)->toBeNull()
        ->and($account->bank_branch)->toBeNull();
});

it('audits an added account without writing the number into the trail', function () {
    $account = $this->accounts->add($this->seller, bankAccountPayload(), $this->seller->user);

    $log = DB::table('audit_logs')->where('action', 'seller.payout_account.added')->sole();

    expect($log->after)->toContain($account->last_four)
        ->and($log->after)->not->toContain('0123456789');
});

it('stores every sensitive payout column as ciphertext', function () {
    $account = $this->accounts->add($this->seller, bankAccountPayload());

    $raw = DB::table('payout_accounts')->where('id', $account->id)->sole();

    foreach (PayoutAccount::ENCRYPTED_COLUMNS as $column) {
        $stored = $raw->{$column};

        if ($stored === null) {
            continue;
        }

        /* Ciphertext is base64 of a JSON envelope; the plaintext must not survive anywhere in it. */
        expect($stored)->not->toBe($account->{$column})
            ->and($stored)->not->toContain((string) $account->{$column})
            ->and(base64_decode($stored, true))->toBeString();
    }

    /* And the cast reads them all back intact. */
    expect($account->fresh()->account_number)->toBe('0123456789')
        ->and($account->fresh()->swift_code)->toBe('ZANAZMLU')
        ->and($account->fresh()->tpin)->toBe('1002003004');
});

it('keeps the account number out of the resource the seller is shown', function () {
    $this->accounts->add($this->seller, bankAccountPayload());

    $this->actingAs($this->seller->user)
        ->get(route('seller.payout-accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.masked_number', '•••• 6789')
            ->missing('accounts.0.account_number'));
});

it('will not let one seller touch another seller\'s account', function () {
    $account = $this->accounts->add($this->seller, bankAccountPayload());
    $intruder = Seller::factory()->create();

    $this->actingAs($intruder->user)
        ->delete(route('seller.payout-accounts.destroy', $account))
        ->assertNotFound();

    expect(PayoutAccount::query()->whereKey($account->id)->exists())->toBeTrue();
});
