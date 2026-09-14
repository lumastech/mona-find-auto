<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Orders\Contracts\DeliveryFeeStrategy;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderGroupStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Exceptions\CheckoutUnavailable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\TermsAcceptance;
use App\Modules\Orders\Support\CheckoutSelection;
use App\Modules\Orders\Support\CheckoutSellerGroup;
use App\Modules\Orders\Support\CheckoutView;
use App\Modules\Orders\Support\DeliveryAddress;
use App\Modules\Orders\Support\PlatformTerms;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerPolicy;
use App\Modules\Shopping\Services\CartService;
use App\Modules\Shopping\Support\CartLine;
use App\Modules\Shopping\Support\CartSellerGroup;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Turning a cart into orders.
 *
 * Two jobs, and the second is the delicate one. `view()` assembles the
 * checkout page off a re-priced cart. `place()` writes one OrderGroup, one
 * Order per shop, the lines, and the terms acceptance — all inside a single
 * transaction, because a cart that half-became an order is a buyer who has
 * been charged for something nobody is going to send.
 *
 * Nothing here moves money or stock. The orders come out PendingPayment and
 * stay that way until a collection settles: stock that came down when a
 * basket was filled would let anybody empty a shop's shelves for free.
 *
 * The policy re-read in `place()` is worth understanding. The buyer accepted
 * specific versions of specific documents in a modal a moment ago; if the
 * seller published a new refund policy in between, placing the order against
 * the new one would record consent the buyer never gave. So placement checks
 * the fingerprint it was shown against what is current now, and refuses
 * rather than papers over the difference.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly DeliveryFeeStrategy $deliveryFees,
    ) {}

    /**
     * The checkout page: the cart, per shop, with each shop's options and
     * terms attached.
     */
    public function view(User $user): CheckoutView
    {
        $cart = $this->cart->view($user);

        $groups = array_map(
            fn (CartSellerGroup $group): CheckoutSellerGroup => $this->describe($group),
            $cart->groups,
        );

        $addresses = UserAddress::query()
            ->where('user_id', $user->getKey())
            ->with(['city', 'province'])
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return new CheckoutView($cart, $groups, $addresses, PlatformTerms::current());
    }

    /**
     * Place the order.
     *
     * @param  array<int, CheckoutSelection>  $selections  Keyed by seller id.
     *
     * @throws CheckoutUnavailable
     */
    public function place(
        User $user,
        array $selections,
        PaymentMethod $paymentMethod,
        Request $request,
    ): OrderGroup {
        $checkout = $this->view($user);

        if ($checkout->isEmpty()) {
            throw CheckoutUnavailable::emptyCart();
        }

        if ($checkout->blocksCheckout()) {
            throw CheckoutUnavailable::cartHasBlockingIssues();
        }

        /*
         * Validate every shop before writing anything. A buyer who chose
         * delivery from a shop that does not deliver should be told so with
         * an intact cart, not left with three of their four orders placed.
         */
        $plans = [];

        foreach ($checkout->groups as $group) {
            $plans[] = $this->planFor($group, $selections, $user);
        }

        $platformTerms = $checkout->platformTerms;
        $acceptedAt = now();

        $group = DB::transaction(function () use ($user, $plans, $paymentMethod, $platformTerms, $acceptedAt, $request): OrderGroup {
            $itemsTotal = Money::zero();
            $deliveryTotal = Money::zero();

            foreach ($plans as $plan) {
                $itemsTotal = $itemsTotal->plus($plan['items_total']);
                $deliveryTotal = $deliveryTotal->plus($plan['delivery_fee']);
            }

            $orderGroup = OrderGroup::query()->create([
                'user_id' => $user->getKey(),
                'status' => OrderGroupStatus::PendingPayment,
                'payment_method' => $paymentMethod,
                'items_total_ngwee' => $itemsTotal,
                'delivery_total_ngwee' => $deliveryTotal,
                'total_ngwee' => $itemsTotal->plus($deliveryTotal),
                'placed_at' => $acceptedAt,
            ]);

            foreach ($plans as $plan) {
                $this->writeOrder($orderGroup, $user, $plan, $platformTerms, $acceptedAt, $request);
            }

            /*
             * The cart is emptied here rather than on payment. A buyer whose
             * card is declined retries the same order group; leaving the cart
             * full would let them place a second set of orders for the same
             * parts and pay for one of them.
             */
            $this->cart->clear($user);

            return $orderGroup;
        });

        $group->load(['orders.items', 'orders.seller']);

        OrderPlaced::dispatch($group);

        return $group;
    }

    /**
     * Attach a shop's options and terms to its part of the cart.
     */
    private function describe(CartSellerGroup $group): CheckoutSellerGroup
    {
        $seller = $group->seller;

        $methods = [];

        if ($seller->offersPickup()) {
            $methods[] = FulfilmentMethod::Pickup;
        }

        if ($seller->offersDelivery()) {
            $methods[] = FulfilmentMethod::Delivery;
        }

        /*
         * A shop that has switched both off would otherwise be unorderable
         * from with nothing on screen explaining why. Collection is the
         * fallback because it is what every shop can do: the buyer walks in.
         */
        if ($methods === []) {
            $methods[] = FulfilmentMethod::Pickup;
        }

        return new CheckoutSellerGroup(
            cart: $group,
            seller: $seller,
            policies: $this->currentPolicies($seller),
            availableMethods: $methods,
            deliveryFee: $this->deliveryFees->feeFor($seller, $group->subtotal()),
            deliveryFeeIsFinal: $this->deliveryFees->isFinalWithoutAddress(),
        );
    }

    /**
     * The versions of a seller's policies in force right now, keyed by type.
     *
     * Keyed rather than listed because the acceptance modal renders them by
     * kind — the refund policy has to be the one the platform minimum sits
     * beside.
     *
     * @return Collection<string, SellerPolicy>
     */
    private function currentPolicies(Seller $seller): Collection
    {
        return $seller->policies()
            ->where('is_current', true)
            ->get()
            ->keyBy(static fn (SellerPolicy $policy): string => $policy->type->value);
    }

    /**
     * Check one shop's selection and work out what its order will hold.
     *
     * @param  array<int, CheckoutSelection>  $selections
     * @return array{group: CheckoutSellerGroup, selection: CheckoutSelection, address: DeliveryAddress|null, address_id: int|null, items_total: Money, delivery_fee: Money}
     *
     * @throws CheckoutUnavailable
     */
    private function planFor(CheckoutSellerGroup $group, array $selections, User $user): array
    {
        $seller = $group->seller;
        $selection = $selections[$seller->getKey()] ?? null;

        if ($selection === null) {
            throw CheckoutUnavailable::missingSellerGroup($seller->getKey());
        }

        if (! $group->supports($selection->method)) {
            throw $selection->method === FulfilmentMethod::Delivery
                ? CheckoutUnavailable::sellerDoesNotDeliver($seller)
                : CheckoutUnavailable::sellerDoesNotOfferPickup($seller);
        }

        /*
         * The terms modal is blocking on screen; this is what makes it
         * blocking in fact. A post that skipped it, or that carries consent
         * for versions the seller has since replaced, does not become an
         * order — because the acceptance row is evidence, and evidence for
         * text the buyer never saw is worse than none.
         */
        if (! $selection->accepted) {
            throw CheckoutUnavailable::termsNotAccepted($seller);
        }

        if ($selection->acceptedVersions() !== $this->currentVersions($group)) {
            throw CheckoutUnavailable::policiesChanged($seller);
        }

        $address = null;
        $addressId = null;

        if ($selection->method->needsAddress()) {
            if ($selection->addressId === null) {
                throw CheckoutUnavailable::addressRequired($seller);
            }

            /*
             * Scoped to the buyer. An address id is a plain integer in a form
             * post, and without this a buyer could have a parcel sent to a
             * stranger's address — or, worse, read one back off the order.
             */
            $record = UserAddress::query()
                ->where('user_id', $user->getKey())
                ->with(['city', 'province'])
                ->find($selection->addressId);

            if ($record === null) {
                throw CheckoutUnavailable::addressRequired($seller);
            }

            $address = DeliveryAddress::fromUserAddress($record);
            $addressId = $record->getKey();
        }

        return [
            'group' => $group,
            'selection' => $selection,
            'address' => $address,
            'address_id' => $addressId,
            'items_total' => $group->subtotal(),
            'delivery_fee' => $selection->method === FulfilmentMethod::Delivery
                ? $this->deliveryFees->feeFor($seller, $group->subtotal(), $address)
                : Money::zero(),
        ];
    }

    /**
     * The versions in force for this shop right now, as a comparable set.
     *
     * @return array<int, int>
     */
    private function currentVersions(CheckoutSellerGroup $group): array
    {
        $versions = [];

        foreach ($group->policyFingerprint() as $policy) {
            $versions[(int) $policy['policy_id']] = (int) $policy['version'];
        }

        ksort($versions);

        return $versions;
    }

    /**
     * Write one seller's order, its lines and the buyer's acceptance.
     *
     * @param  array{group: CheckoutSellerGroup, selection: CheckoutSelection, address: DeliveryAddress|null, address_id: int|null, items_total: Money, delivery_fee: Money}  $plan
     */
    private function writeOrder(
        OrderGroup $orderGroup,
        User $user,
        array $plan,
        PlatformTerms $platformTerms,
        CarbonInterface $acceptedAt,
        Request $request,
    ): Order {
        /** @var CheckoutSellerGroup $group */
        $group = $plan['group'];
        /** @var CheckoutSelection $selection */
        $selection = $plan['selection'];

        $order = Order::query()->create([
            'order_group_id' => $orderGroup->getKey(),
            'user_id' => $user->getKey(),
            'seller_id' => $group->seller->getKey(),
            'status' => OrderStatus::PendingPayment,
            'fulfilment_method' => $selection->method,
            'user_address_id' => $plan['address_id'],
            'delivery_address' => $plan['address']?->toArray(),
            'delivery_instructions' => $selection->instructions,
            'items_total_ngwee' => $plan['items_total'],
            'delivery_fee_ngwee' => $plan['delivery_fee'],
            'total_ngwee' => $plan['items_total']->plus($plan['delivery_fee']),
        ]);

        foreach ($group->cart->lines as $line) {
            $this->writeItem($order, $line);
        }

        $this->recordAcceptance($order, $group, $platformTerms, $acceptedAt, $request);

        return $order;
    }

    /**
     * One line, described as it was described at the time.
     */
    private function writeItem(Order $order, CartLine $line): OrderItem
    {
        $variant = $line->item->variant;
        $product = $variant->product;

        return OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_variant_id' => $variant->getKey(),
            'product_id' => $product->getKey(),
            'quotation_id' => $line->item->quotation_id,

            /* Frozen: a renamed listing must not rewrite an old receipt. */
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'condition' => $product->condition,
            'inspection_status' => $product->inspection_status,

            'unit_price_ngwee' => $line->unitPrice,
            'quantity' => $line->quantity,
            'total_ngwee' => $line->total(),
        ]);
    }

    /**
     * Write down exactly what the buyer agreed to.
     *
     * The policy ids and versions come from the group that was rendered into
     * the acceptance modal, so what is recorded is what was on screen. The
     * platform's minimum refund rule is copied as text because settings have
     * no version history and the sentence has to survive being edited.
     */
    private function recordAcceptance(
        Order $order,
        CheckoutSellerGroup $group,
        PlatformTerms $platformTerms,
        CarbonInterface $acceptedAt,
        Request $request,
    ): TermsAcceptance {
        return TermsAcceptance::query()->create([
            'order_id' => $order->getKey(),
            'order_group_id' => $order->order_group_id,
            'user_id' => $order->user_id,
            'seller_id' => $order->seller_id,
            'policies' => $group->policyFingerprint(),
            'platform_terms_version' => $platformTerms->version,
            'minimum_refund_days' => $platformTerms->minimumRefundDays,
            'minimum_refund_statement' => $platformTerms->minimumRefundStatement,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'accepted_at' => $acceptedAt,
            'created_at' => $acceptedAt,
        ]);
    }
}
