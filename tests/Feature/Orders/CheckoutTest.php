<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderGroupStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Modules\Orders\Exceptions\CheckoutUnavailable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Orders\Models\TermsAcceptance;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Support\CheckoutSelection;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerPolicyService;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Services\CartService;
use App\Support\Database\ImmutableRecordException;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->checkout = app(CheckoutService::class);
    $this->cart = app(CartService::class);
    $this->policies = app(SellerPolicyService::class);

    $this->buyer = User::factory()->create();
});

/**
 * A shop with its four policies published and stock on the shelf.
 */
function shopWithStock(array $sellerAttributes = [], int $price = 45_000): array
{
    $seller = Seller::factory()->create($sellerAttributes);

    /** @var SellerPolicyService $policies */
    $policies = test()->policies;

    foreach (PolicyType::cases() as $type) {
        $policies->publish($seller, $type, 'Version one of the '.$type->value.' policy.');
    }

    $product = Product::factory()->ofSeller($seller)->create();
    $variant = $product->variants()->first();
    $variant->forceFill(['price' => $price, 'quantity' => 10])->save();

    return [$seller, $variant->fresh()];
}

/**
 * Every selection a checkout needs, accepting whatever is currently in force.
 *
 * @return array<int, CheckoutSelection>
 */
function acceptingSelections(FulfilmentMethod $method = FulfilmentMethod::Pickup, ?int $addressId = null): array
{
    $view = test()->checkout->view(test()->buyer);
    $selections = [];

    foreach ($view->groups as $group) {
        $selections[$group->seller->getKey()] = new CheckoutSelection(
            sellerId: $group->seller->getKey(),
            method: $method,
            addressId: $addressId,
            accepted: true,
            acceptedPolicies: array_map(
                static fn (array $policy): array => [
                    'policy_id' => $policy['policy_id'],
                    'version' => $policy['version'],
                ],
                $group->policyFingerprint(),
            ),
        );
    }

    return $selections;
}

it('turns a four-shop cart into one payment and four orders', function (): void {
    foreach (range(1, 4) as $index) {
        [$seller, $variant] = shopWithStock();
        $this->cart->add($this->buyer, $variant, $index);
    }

    $group = $this->checkout->place(
        $this->buyer,
        acceptingSelections(),
        PaymentMethod::MobileMoney,
        Request::create('/checkout', 'POST'),
    );

    expect(OrderGroup::query()->count())->toBe(1)
        ->and($group->orders)->toHaveCount(4)
        ->and($group->status)->toBe(OrderGroupStatus::PendingPayment);

    /* One payment, and its total is the sum of the four. */
    $sum = $group->orders->sum(fn (Order $order): int => $order->total_ngwee->ngwee);
    expect($group->total_ngwee->ngwee)->toBe($sum);

    /* Four independent lifecycles: nothing is shared but the payment. */
    expect($group->orders->pluck('status')->unique()->all())
        ->toBe([OrderStatus::PendingPayment])
        ->and($group->orders->pluck('number')->unique())->toHaveCount(4);
});

it('holds no stock until the money arrives', function (): void {
    [, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 3);

    $this->checkout->place($this->buyer, acceptingSelections(), PaymentMethod::Card, Request::create('/'));

    expect($variant->fresh()->quantity)->toBe(10);
});

it('empties the cart so the same parts cannot be ordered twice', function (): void {
    [, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 1);

    $this->checkout->place($this->buyer, acceptingSelections(), PaymentMethod::Card, Request::create('/'));

    expect(CartItem::query()->count())->toBe(0);
});

it('records the exact policy versions the buyer was shown', function (): void {
    [$seller, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 1);

    $shownRefund = $seller->currentPolicy(PolicyType::Refund);

    $group = $this->checkout->place($this->buyer, acceptingSelections(), PaymentMethod::Card, Request::create('/'));

    /* The seller rewrites the refund policy the week after the sale. */
    $this->policies->publish($seller, PolicyType::Refund, 'Version two: no refunds on electrical parts.');

    $acceptance = TermsAcceptance::query()->where('order_id', $group->orders->first()->getKey())->firstOrFail();

    expect($acceptance->versionOf(PolicyType::Refund))->toBe(1)
        ->and($seller->fresh()->currentPolicy(PolicyType::Refund)->version)->toBe(2);

    /* And the text the buyer accepted is still recoverable, word for word. */
    $accepted = $acceptance->acceptedPolicies()->firstWhere('id', $shownRefund->getKey());

    expect($accepted->body)->toBe('Version one of the refund policy.')
        ->and($accepted->is_current)->toBeFalse();
});

it('records who agreed, from where, and when', function (): void {
    [, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 1);

    $request = Request::create('/checkout', 'POST', server: [
        'REMOTE_ADDR' => '196.44.1.9',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 10)',
    ]);

    $group = $this->checkout->place($this->buyer, acceptingSelections(), PaymentMethod::Card, $request);

    $acceptance = TermsAcceptance::query()->where('order_id', $group->orders->first()->getKey())->firstOrFail();

    expect($acceptance->ip_address)->toBe('196.44.1.9')
        ->and($acceptance->user_agent)->toContain('Android 10')
        ->and($acceptance->accepted_at)->not->toBeNull()
        ->and($acceptance->minimum_refund_days)->toBe((int) settings('policies.minimum_refund_days'))
        ->and($acceptance->minimum_refund_statement)->toBe((string) settings('policies.minimum_refund_statement'));
});

it('will not let an acceptance record be edited afterwards', function (): void {
    [, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 1);

    $group = $this->checkout->place($this->buyer, acceptingSelections(), PaymentMethod::Card, Request::create('/'));
    $acceptance = TermsAcceptance::query()->where('order_id', $group->orders->first()->getKey())->firstOrFail();

    expect(fn () => $acceptance->update(['ip_address' => '0.0.0.0']))
        ->toThrow(ImmutableRecordException::class);
});

it('refuses an order whose terms were not accepted', function (): void {
    [$seller, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 1);

    $selections = [
        $seller->getKey() => new CheckoutSelection(
            sellerId: $seller->getKey(),
            method: FulfilmentMethod::Pickup,
            accepted: false,
        ),
    ];

    expect(fn () => $this->checkout->place($this->buyer, $selections, PaymentMethod::Card, Request::create('/')))
        ->toThrow(CheckoutUnavailable::class);

    expect(Order::query()->count())->toBe(0);
});

it('refuses an order accepting a version the seller has since replaced', function (): void {
    [$seller, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 1);

    $selections = acceptingSelections();

    /* The seller republishes between the modal opening and the form posting. */
    $this->policies->publish($seller, PolicyType::Refund, 'Version two: stricter.');

    expect(fn () => $this->checkout->place($this->buyer, $selections, PaymentMethod::Card, Request::create('/')))
        ->toThrow(CheckoutUnavailable::class);

    expect(Order::query()->count())->toBe(0)
        ->and(TermsAcceptance::query()->count())->toBe(0);
});

it('refuses delivery from a shop that does not deliver', function (): void {
    [, $variant] = shopWithStock(['offers_delivery' => false]);
    $this->cart->add($this->buyer, $variant, 1);

    $address = UserAddress::factory()->create(['user_id' => $this->buyer->getKey()]);

    expect(fn () => $this->checkout->place(
        $this->buyer,
        acceptingSelections(FulfilmentMethod::Delivery, $address->getKey()),
        PaymentMethod::Card,
        Request::create('/'),
    ))->toThrow(CheckoutUnavailable::class);
});

it('charges the shop\'s own flat delivery fee and snapshots the address', function (): void {
    [, $variant] = shopWithStock([
        'offers_delivery' => true,
        'delivery_fee_ngwee' => 7_500,
    ]);
    $this->cart->add($this->buyer, $variant, 2);

    $address = UserAddress::factory()->create([
        'user_id' => $this->buyer->getKey(),
        'street' => 'Kafue Road',
        'recipient_name' => 'Chanda Mwale',
    ]);

    $group = $this->checkout->place(
        $this->buyer,
        acceptingSelections(FulfilmentMethod::Delivery, $address->getKey()),
        PaymentMethod::Card,
        Request::create('/'),
    );

    $order = $group->orders->first();

    expect($order->delivery_fee_ngwee->ngwee)->toBe(7_500)
        ->and($order->total_ngwee->ngwee)->toBe($order->items_total_ngwee->ngwee + 7_500)
        ->and($order->delivery_address['street'])->toBe('Kafue Road');

    /* The buyer edits their address book; the order does not move. */
    $address->update(['street' => 'Great East Road']);

    expect($order->fresh()->delivery_address['street'])->toBe('Kafue Road');
});

it('will not send a parcel to somebody else\'s address', function (): void {
    [, $variant] = shopWithStock(['offers_delivery' => true, 'delivery_fee_ngwee' => 5_000]);
    $this->cart->add($this->buyer, $variant, 1);

    $strangersAddress = UserAddress::factory()->create(['user_id' => User::factory()->create()->getKey()]);

    expect(fn () => $this->checkout->place(
        $this->buyer,
        acceptingSelections(FulfilmentMethod::Delivery, $strangersAddress->getKey()),
        PaymentMethod::Card,
        Request::create('/'),
    ))->toThrow(CheckoutUnavailable::class);
});

it('refuses an empty cart', function (): void {
    expect(fn () => $this->checkout->place($this->buyer, [], PaymentMethod::Card, Request::create('/')))
        ->toThrow(CheckoutUnavailable::class);
});

it('copies the listing description onto the line so a rename cannot rewrite it', function (): void {
    [, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 1);

    $group = $this->checkout->place($this->buyer, acceptingSelections(), PaymentMethod::Card, Request::create('/'));
    $item = $group->orders->first()->items->first();
    $originalName = $item->product_name;

    $variant->product->forceFill(['name' => 'Something else entirely'])->save();

    expect($item->fresh()->product_name)->toBe($originalName)
        ->and($item->condition)->not->toBeNull()
        ->and($item->inspection_status)->not->toBeNull();
});

it('gives every order group a public id the payment reference can be built from', function (): void {
    [, $variant] = shopWithStock();
    $this->cart->add($this->buyer, $variant, 1);

    $group = $this->checkout->place($this->buyer, acceptingSelections(), PaymentMethod::Card, Request::create('/'));

    expect($group->public_id)->toHaveLength(26)
        ->and($group->nextPaymentAttempt())->toBe(1);
});
