<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Services;

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\BankOption;
use App\Integrations\Payments\Data\ResolvedAccount;
use App\Models\User;
use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Identity\Support\ZambianPhone;
use App\Modules\Sellers\Enums\PayoutMethod;
use App\Modules\Sellers\Events\PayoutAccountRegistered;
use App\Modules\Sellers\Exceptions\PayoutAccountUnresolved;
use App\Modules\Sellers\Models\PayoutAccount;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\DB;

/**
 * Adding and removing the accounts a seller is paid into.
 *
 * Nothing is stored until the gateway confirms the account exists. A payout
 * addressed to a number nobody owns either bounces weeks later or lands in a
 * stranger's wallet, and both are far more expensive to unpick than refusing
 * the save.
 */
class PayoutAccountService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * The banks a seller may choose from.
     *
     * @return array<int, BankOption>
     */
    public function banks(): array
    {
        return $this->gateway->listBanks();
    }

    /**
     * Add an account, resolving it at the gateway first.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws PayoutAccountUnresolved
     */
    public function add(Seller $seller, array $attributes, ?User $actor = null): PayoutAccount
    {
        $method = $attributes['method'] instanceof PayoutMethod
            ? $attributes['method']
            : PayoutMethod::from((string) $attributes['method']);

        $resolved = $method->isBank()
            ? $this->resolveBank($attributes)
            : $this->resolveMobileMoney($attributes);

        $recipientId = $this->gateway->createTransferRecipient($resolved);

        $account = DB::transaction(function () use ($seller, $method, $attributes, $resolved, $recipientId): PayoutAccount {
            $account = $seller->payoutAccounts()->create([
                ...$this->columnsFor($method, $attributes, $resolved),
                'method' => $method,
                'label' => $attributes['label'] ?? null,
                'resolved_name' => $resolved->accountName,
                'lenco_recipient_id' => $recipientId,
                'resolved_at' => now(),
                /* The first account a seller adds is their default whatever they ticked. */
                'is_default' => false,
            ]);

            $wantsDefault = (bool) ($attributes['is_default'] ?? false);

            if ($wantsDefault || $seller->payoutAccounts()->count() === 1) {
                $this->makeDefault($account);
            }

            return $account;
        });

        audit(
            $actor,
            'seller.payout_account.added',
            $account,
            null,
            $this->auditableState($account),
            null,
            ['seller_id' => $seller->getKey()],
        );

        PayoutAccountRegistered::dispatch($account);

        return $account->refresh();
    }

    /**
     * Point future payouts at this account instead.
     */
    public function makeDefault(PayoutAccount $account, ?User $actor = null): PayoutAccount
    {
        DB::transaction(function () use ($account): void {
            PayoutAccount::query()
                ->where('seller_id', $account->seller_id)
                ->whereKeyNot($account->getKey())
                ->update(['is_default' => false, 'updated_at' => now()]);

            $account->forceFill(['is_default' => true])->save();
        });

        audit(
            $actor,
            'seller.payout_account.made_default',
            $account,
            null,
            $this->auditableState($account),
            null,
            ['seller_id' => $account->seller_id],
        );

        return $account;
    }

    /**
     * Remove an account, handing the default on if this was it.
     *
     * A seller is never left with accounts but no default: the next one takes
     * over, because a payout run that finds no default is a payout that
     * silently does not happen.
     */
    public function remove(PayoutAccount $account, ?User $actor = null): void
    {
        $before = $this->auditableState($account);
        $wasDefault = $account->is_default;
        $sellerId = $account->seller_id;

        DB::transaction(function () use ($account, $wasDefault, $sellerId): void {
            $account->delete();

            if (! $wasDefault) {
                return;
            }

            $successor = PayoutAccount::query()->where('seller_id', $sellerId)->oldest('id')->first();

            $successor?->forceFill(['is_default' => true])->save();
        });

        audit(
            $actor,
            'seller.payout_account.removed',
            $account,
            $before,
            null,
            null,
            ['seller_id' => $sellerId],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws PayoutAccountUnresolved
     */
    private function resolveBank(array $attributes): ResolvedAccount
    {
        $accountNumber = (string) ($attributes['account_number'] ?? '');
        $bankCode = (string) ($attributes['bank_code'] ?? '');

        $bank = collect($this->banks())->firstWhere('code', $bankCode);

        if ($bank === null) {
            throw PayoutAccountUnresolved::unknownBank();
        }

        return $this->gateway->resolveBankAccount($accountNumber, $bankCode)
            ?? throw PayoutAccountUnresolved::bankAccount($bank->name);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws PayoutAccountUnresolved
     */
    private function resolveMobileMoney(array $attributes): ResolvedAccount
    {
        $network = MobileNetwork::from((string) ($attributes['network'] ?? ''));

        /* The gateway addresses wallets in E.164, whatever the seller typed. */
        $phone = ZambianPhone::tryParse((string) ($attributes['mobile_number'] ?? ''))?->e164()
            ?? (string) ($attributes['mobile_number'] ?? '');

        return $this->gateway->resolveMobileMoney($phone, $network->value)
            ?? throw PayoutAccountUnresolved::mobileMoney($network->label());
    }

    /**
     * The columns each method fills. Fields belonging to the other method are
     * explicitly nulled rather than left out, so an account never carries a
     * stale bank branch from a form that also had mobile fields on it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function columnsFor(PayoutMethod $method, array $attributes, ResolvedAccount $resolved): array
    {
        if ($method->isBank()) {
            return [
                'beneficiary_name' => $attributes['beneficiary_name'] ?? $resolved->accountName,
                'account_number' => $resolved->accountNumber,
                'bank_code' => $resolved->bankCode,
                'bank_name' => $resolved->bankName,
                'bank_branch' => $attributes['bank_branch'] ?? null,
                'bank_address' => $attributes['bank_address'] ?? null,
                'swift_code' => $attributes['swift_code'] ?? null,
                'tpin' => $attributes['tpin'] ?? null,
                'mobile_number' => null,
                'network' => null,
            ];
        }

        return [
            'beneficiary_name' => $attributes['beneficiary_name'] ?? $resolved->accountName,
            'mobile_number' => $resolved->accountNumber,
            'network' => $resolved->network,
            'tpin' => $attributes['tpin'] ?? null,
            'account_number' => null,
            'bank_code' => null,
            'bank_name' => null,
            'bank_branch' => null,
            'bank_address' => null,
            'swift_code' => null,
        ];
    }

    /**
     * What goes in the audit row. Never the account number: the audit trail
     * is read by more people than the encrypted column is.
     *
     * @return array<string, mixed>
     */
    private function auditableState(PayoutAccount $account): array
    {
        return [
            'method' => $account->method->value,
            'institution' => $account->bank_name ?? $account->network?->label(),
            'last_four' => $account->last_four,
            'resolved_name' => $account->resolved_name,
            'lenco_recipient_id' => $account->lenco_recipient_id,
            'is_default' => $account->is_default,
        ];
    }
}
