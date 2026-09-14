<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Models\LedgerAdjustment;
use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Modules\Ledger\Services\LedgerAdjustmentService;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->finance = User::factory()->withTwoFactor()->create();
    $this->finance->assignRole(Role::Finance->value);

    $this->admin = User::factory()->withTwoFactor()->create();
    $this->admin->assignRole(Role::PlatformAdmin->value);

    $this->moderator = User::factory()->withTwoFactor()->create();
    $this->moderator->assignRole(Role::Moderator->value);
});

describe('the ledger browser', function (): void {
    it('shows entries and balances to finance', function (): void {
        $order = Order::factory()->paid()->create([
            'items_total_ngwee' => 100_000,
            'delivery_fee_ngwee' => 0,
            'total_ngwee' => 100_000,
        ]);
        app(OrderPostingService::class)->recordPayment($order);

        $this->actingAs($this->finance)
            ->get(route('admin.finance.ledger.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/finance/ledger/Index')
                ->has('entries.data', 1)
                ->where('entries.data.0.recipe', 'escrow_payment')
                ->where('entries.data.0.total_ngwee', 100_000)
                ->has('accounts', count(LedgerAccountCode::cases()))
                ->where('discrepancies', 0));
    });

    it('refuses a moderator, who has no business reading revenue', function (): void {
        $this->actingAs($this->moderator)
            ->get(route('admin.finance.ledger.index'))
            ->assertForbidden();
    });

    it('filters by account', function (): void {
        $order = Order::factory()->paid()->create([
            'items_total_ngwee' => 100_000,
            'delivery_fee_ngwee' => 0,
            'total_ngwee' => 100_000,
        ]);
        app(OrderPostingService::class)->recordPayment($order);

        $this->actingAs($this->finance)
            ->get(route('admin.finance.ledger.index', ['account' => LedgerAccountCode::EscrowHeld->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 1));

        $this->actingAs($this->finance)
            ->get(route('admin.finance.ledger.index', ['account' => LedgerAccountCode::RefundsExpense->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 0));
    });

    it('shows an entry with its lines', function (): void {
        $order = Order::factory()->paid()->create([
            'items_total_ngwee' => 100_000,
            'delivery_fee_ngwee' => 0,
            'total_ngwee' => 100_000,
        ]);
        $entry = app(OrderPostingService::class)->recordPayment($order);

        $this->actingAs($this->finance)
            ->get(route('admin.finance.ledger.show', $entry->uuid))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/finance/ledger/Show')
                ->has('entry.lines', 2)
                ->where('entry.idempotency_key', 'escrow-payment:order:'.$order->id));
    });
});

describe('the policy manager', function (): void {
    it('lists policies for finance but only lets an administrator write', function (): void {
        MonetisationPolicy::factory()->default()->create();

        $this->actingAs($this->finance)
            ->get(route('admin.finance.policies.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/finance/policies/Index')
                ->has('policies', 1)
                ->where('platform.vat_on_commission_percent', '16.00'));

        $this->actingAs($this->finance)
            ->post(route('admin.finance.policies.store'), [
                'name' => 'Bespoke',
                'commission_type' => 'percentage',
                'commission_percent' => '4.00',
            ])
            ->assertForbidden();
    });

    it('creates a policy', function (): void {
        $this->actingAs($this->admin)
            ->post(route('admin.finance.policies.store'), [
                'name' => 'Breaker terms',
                'commission_type' => 'percentage',
                'commission_percent' => '4.50',
                'addon_fee' => '2.50',
            ])
            ->assertRedirect();

        $policy = MonetisationPolicy::query()->where('slug', 'breaker-terms')->sole();

        expect($policy->commission_percent)->toBe('4.50')
            /* Typed in kwacha, stored in ngwee, with nothing rounded on the way. */
            ->and($policy->addon_fee_ngwee)->toBeMoney(250);
    });

    it('rejects a percentage with more precision than money has', function (): void {
        $this->actingAs($this->admin)
            ->post(route('admin.finance.policies.store'), [
                'name' => 'Too precise',
                'commission_type' => 'percentage',
                'commission_percent' => '7.125',
            ])
            ->assertSessionHasErrors('commission_percent');
    });

    it('assigns a seller to a policy', function (): void {
        $policy = MonetisationPolicy::factory()->create();
        $seller = Seller::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.finance.policies.assign', $seller), [
                'policy' => $policy->slug,
                'reason' => 'Negotiated on renewal.',
            ])
            ->assertRedirect();

        expect($seller->fresh()->monetisation_policy_id)->toBe($policy->getKey());

        /* And back onto the default. */
        $this->actingAs($this->admin)
            ->put(route('admin.finance.policies.assign', $seller), ['policy' => null])
            ->assertRedirect();

        expect($seller->fresh()->monetisation_policy_id)->toBeNull();
    });

    it('shows sellers with what they are owed', function (): void {
        MonetisationPolicy::factory()->default()->create();
        $order = Order::factory()->directSettlement()->create([
            'items_total_ngwee' => 100_000,
            'delivery_fee_ngwee' => 0,
            'total_ngwee' => 100_000,
        ]);
        app(OrderPostingService::class)->recordPayment($order);

        $this->actingAs($this->finance)
            ->get(route('admin.finance.policies.sellers'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/finance/policies/Sellers')
                ->where('sellers.data.0.payable_ngwee', 81_300)
                ->where('sellers.data.0.reserve_ngwee', 10_000)
                ->where('sellers.data.0.on_default', true));
    });
});

describe('the adjustment queue', function (): void {
    it('lets finance draft but not approve', function (): void {
        $seller = Seller::factory()->create();

        $this->actingAs($this->finance)
            ->post(route('admin.finance.adjustments.store'), [
                'description' => 'Goodwill credit',
                'reason' => 'Agreed with the seller after a delivery failure.',
                'lines' => [
                    [
                        'account' => LedgerAccountCode::RefundsExpense->value,
                        'direction' => EntryDirection::Debit->value,
                        'amount' => '50.00',
                    ],
                    [
                        'account' => LedgerAccountCode::SellerPayable->value,
                        'direction' => EntryDirection::Credit->value,
                        'amount' => '50.00',
                        'subject_id' => $seller->getKey(),
                    ],
                ],
            ])
            ->assertRedirect();

        $adjustment = LedgerAdjustment::query()->sole();

        expect($adjustment->total_ngwee)->toBeMoney(5_000)
            ->and(app(LedgerBalances::class)->sellerPayable($seller))->toBeMoney(0);

        /* Finance cannot decide, even somebody else's draft. */
        $this->actingAs($this->finance)
            ->post(route('admin.finance.adjustments.approve', $adjustment))
            ->assertForbidden();
    });

    it('turns an unbalanced draft into a field error', function (): void {
        $this->actingAs($this->finance)
            ->post(route('admin.finance.adjustments.store'), [
                'description' => 'Wonky',
                'reason' => 'Agreed with the seller after a delivery failure.',
                'lines' => [
                    [
                        'account' => LedgerAccountCode::RefundsExpense->value,
                        'direction' => EntryDirection::Debit->value,
                        'amount' => '50.00',
                    ],
                    [
                        'account' => LedgerAccountCode::VatOnCommissionPayable->value,
                        'direction' => EntryDirection::Credit->value,
                        'amount' => '40.00',
                    ],
                ],
            ])
            ->assertSessionHasErrors('lines');

        expect(LedgerAdjustment::query()->count())->toBe(0);
    });

    it('lets an administrator approve somebody else\'s draft', function (): void {
        $seller = Seller::factory()->create();
        $adjustment = app(LedgerAdjustmentService::class)->draft(
            [
                [
                    'account' => LedgerAccountCode::RefundsExpense->value,
                    'direction' => EntryDirection::Debit->value,
                    'amount_ngwee' => 5_000,
                ],
                [
                    'account' => LedgerAccountCode::SellerPayable->value,
                    'direction' => EntryDirection::Credit->value,
                    'amount_ngwee' => 5_000,
                    'subject_id' => $seller->getKey(),
                ],
            ],
            'Goodwill credit',
            'Agreed with the seller after a delivery failure.',
            $this->finance,
        );

        $this->actingAs($this->admin)
            ->post(route('admin.finance.adjustments.approve', $adjustment), ['note' => 'Checked.'])
            ->assertRedirect();

        expect(app(LedgerBalances::class)->sellerPayable($seller))->toBeMoney(5_000);
    });

    it('hides the decide action from the person who drafted it', function (): void {
        $adjustment = LedgerAdjustment::factory()->create(['created_by' => $this->admin->getKey()]);

        $this->actingAs($this->admin)
            ->get(route('admin.finance.adjustments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/finance/adjustments/Index')
                ->where('adjustments.data.0.can_decide', false));

        expect($adjustment->isDecidableBy($this->admin))->toBeFalse();
    });

    it('refuses a moderator entirely', function (): void {
        $this->actingAs($this->moderator)
            ->get(route('admin.finance.adjustments.index'))
            ->assertForbidden();
    });
});

it('reaches the money section of the console from the sidebar routes', function (): void {
    expect(route('admin.finance.ledger.index'))->toContain('/admin/finance/ledger')
        ->and(route('admin.finance.policies.index'))->toContain('/admin/finance/policies')
        ->and(route('admin.finance.adjustments.index'))->toContain('/admin/finance/adjustments');
});
