<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Enums\OrderGroupStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Orders\Models\TermsAcceptance;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerPolicyService;
use App\Modules\Shopping\Services\CartService;
use App\Support\Roles\Role;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;

/*
 * Checkout in a real browser, end to end.
 *
 * The Pest suite proves what the services do; this proves the buyer can
 * actually get through the screen — which is a different claim, and the one
 * that breaks when a blocking modal will not close or a radio group is not
 * reachable. Payments are still stubbed behind PaymentGateway, so the run
 * ends where the money would start: orders written, terms recorded, cart
 * emptied, buyer waiting to pay.
 *
 * Run with `php artisan dusk`.
 */

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->buyer = User::factory()->create(['password' => bcrypt('password')]);
    $this->buyer->assignRole(Role::Buyer->value);

    $this->seller = Seller::factory()->create([
        'business_name' => 'Kabwata Motors',
        'offers_pickup' => true,
        'offers_delivery' => false,
    ]);

    $policies = app(SellerPolicyService::class);

    foreach (PolicyType::cases() as $type) {
        $policies->publish(
            $this->seller,
            $type,
            'The '.$type->value.' policy of Kabwata Motors, version one.',
        );
    }

    $product = Product::factory()->ofSeller($this->seller)->create(['name' => 'Alternator']);
    $variant = $product->variants()->first();
    $variant->forceFill(['price' => 120_000, 'quantity' => 5])->save();

    app(CartService::class)->add($this->buyer, $variant->fresh(), 1);
});

test('a buyer reads the seller terms and places an order', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->loginAs($this->buyer)
            ->visit('/checkout')
            ->assertSee('Checkout')
            ->assertSee('Kabwata Motors')
            ->assertSee('Alternator')

            /* Nothing can be paid before the terms have been read. */
            ->assertDisabled('@place-order')

            ->click('@review-terms-'.$this->seller->getKey())
            ->waitForText('Ordering from Kabwata Motors')
            ->assertSee('The refund policy of Kabwata Motors, version one.')
            /* MonaFind's floor, beside the seller's own wording. */
            ->assertSee('Whatever this policy says')

            ->click('@accept-terms')
            ->waitUntilMissingText('Ordering from Kabwata Motors')
            ->assertSee("You accepted this seller's terms")

            ->click('@place-order')
            /*
             * The order number cannot be known before the click, so wait on
             * what the page says rather than on a URL built from a row that
             * does not exist yet.
             */
            ->waitForText('Awaiting payment')
            ->assertPathBeginsWith('/orders/')
            ->assertSee('What you bought');
    });

    /* One payment, one order, and a consent record naming the versions. */
    $group = OrderGroup::query()->firstOrFail();
    $order = Order::query()->firstOrFail();
    $acceptance = TermsAcceptance::query()->firstOrFail();

    expect($group->status)->toBe(OrderGroupStatus::PendingPayment)
        ->and($group->orders()->count())->toBe(1)
        ->and($order->items()->count())->toBe(1)
        ->and($acceptance->versionOf(PolicyType::Refund))->toBe(1)
        ->and($acceptance->ip_address)->not->toBeNull()
        /* The cart is emptied so the same parts cannot be ordered twice. */
        ->and(app(CartService::class)->count($this->buyer))->toBe(0);
});
