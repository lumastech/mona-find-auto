<?php

declare(strict_types=1);

namespace App\Modules\Orders\Privacy;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\TermsAcceptance;
use App\Modules\Privacy\Contracts\PersonalDataSource;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;

/**
 * Orders: kept as records, stripped of the person who placed them.
 *
 * This is where erasure and the law pull hardest against each other, and
 * where the line is drawn.
 *
 * ## What is kept, and on what basis
 *
 * Every order row, item, total and timestamp stays. Zambian tax and company
 * law require a business to keep records of its sales, and the Act's own
 * exemption for processing necessary to comply with a legal obligation is
 * what permits it. The seller on the other side of the sale has the same
 * obligation and the same records.
 *
 * ## What is removed
 *
 * The delivery address snapshot and the delivery instructions. Those are
 * there so a courier can find a door — they are not part of the sale's
 * accounting and nothing needs them once the order is closed. The dispute
 * narrative goes the same way: it is free text a buyer wrote about their
 * circumstances.
 *
 * ## Terms acceptances are not touched at all
 *
 * `terms_acceptances` is append-only in both layers — Eloquent throws and the
 * database trigger aborts — so there is nothing to strip even if we wanted
 * to. That is the right outcome rather than an obstacle: the table is the
 * platform's proof of what a buyer agreed to, it is the evidence that would
 * settle a dispute brought years later, and the IP address in it is part of
 * what makes it evidence. It is retained under the legal-claims basis and
 * documented as such in docs/DATA_RETENTION.md.
 *
 * The `user_id` on those rows continues to point at the erased account, which
 * by then identifies nobody.
 */
class OrderPersonalData implements PersonalDataSource
{
    public function key(): string
    {
        return 'orders';
    }

    /**
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array
    {
        $orders = Order::query()
            ->where('user_id', $user->getKey())
            ->with(['seller:id,business_name', 'items'])
            ->latest('id')
            ->get();

        return [
            PersonalDataSection::make(
                'Your orders',
                $orders->map(static fn (Order $order): array => [
                    'Order number' => $order->number,
                    'Shop' => $order->seller->business_name,
                    'Status' => $order->status->value,
                    'How you got it' => $order->fulfilment_method->value,
                    'Items total' => $order->items_total_ngwee,
                    'Delivery fee' => $order->delivery_fee_ngwee,
                    'Total' => $order->total_ngwee,
                    'Delivery instructions' => $order->delivery_instructions,
                    'Placed on' => $order->created_at?->toDateTimeString(),
                    'Paid on' => $order->paid_at?->toDateTimeString(),
                    'Completed on' => $order->completed_at?->toDateTimeString(),
                ])->all(),
                'Every order you have placed. Amounts are in ngwee — 100 ngwee to the kwacha.',
            ),

            PersonalDataSection::make(
                'What you ordered',
                $orders->flatMap(static fn (Order $order): array => $order->items
                    ->map(static fn (OrderItem $item): array => [
                        'Order number' => $order->number,
                        'Part' => $item->product_name,
                        'Variant' => $item->variant_name,
                        'Condition' => $item->condition,
                        'Quantity' => $item->quantity,
                        'Unit price' => $item->unit_price_ngwee,
                        'Line total' => $item->total_ngwee,
                    ])->all())->all(),
            ),

            PersonalDataSection::make(
                'Your delivery addresses on orders',
                $orders
                    ->filter(static fn (Order $order): bool => is_array($order->delivery_address) && $order->delivery_address !== [])
                    ->map(fn (Order $order): array => [
                        'Order number' => $order->number,
                        'Address' => $this->flattenAddress($order->delivery_address ?? []),
                    ])->all(),
                'The address recorded on each delivery order at the time it was placed.',
            ),

            PersonalDataSection::make(
                'Disputes you raised',
                OrderDispute::query()
                    ->where('opened_by', $user->getKey())
                    ->with('order:id,number')
                    ->latest('id')
                    ->get()
                    ->map(static fn (OrderDispute $dispute): array => [
                        'Order number' => $dispute->order->number,
                        'Reason' => $dispute->reason->value,
                        'What you told us' => $dispute->details,
                        'Status' => $dispute->status->value,
                        'Outcome' => $dispute->resolution?->value,
                        'Raised on' => $dispute->created_at?->toDateTimeString(),
                    ])->all(),
            ),

            PersonalDataSection::make(
                'Terms you accepted at checkout',
                TermsAcceptance::query()
                    ->where('user_id', $user->getKey())
                    ->with(['order:id,number', 'seller:id,business_name'])
                    ->latest('id')
                    ->get()
                    ->map(static fn (TermsAcceptance $acceptance): array => [
                        'Order number' => $acceptance->order->number,
                        'Shop' => $acceptance->seller->business_name,
                        'Platform terms version' => $acceptance->platform_terms_version,
                        'Minimum refund window (days)' => $acceptance->minimum_refund_days,
                        'Accepted from' => $acceptance->ip_address,
                        'Accepted on' => $acceptance->accepted_at->toDateTimeString(),
                    ])->all(),
                'A legal record of what you agreed to each time you checked out. We are required to keep these, and they are not removed when an account is deleted.',
            ),
        ];
    }

    /**
     * `order_groups` is absent from the tally on purpose: the table holds
     * totals, a status and a public id, and nothing personal to strip.
     *
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array
    {
        $orderIds = Order::query()->where('user_id', $user->getKey())->pluck('id');

        /*
         * The courier's copy of where somebody lives. Nulled rather than
         * redacted: the column is JSON, and a string saying "[redacted]"
         * where an address object belongs would break every reader of it.
         */
        $orders = Order::query()
            ->whereIn('id', $orderIds)
            ->update([
                'delivery_address' => null,
                'delivery_instructions' => null,
                'user_address_id' => null,
                'updated_at' => now(),
            ]);

        /*
         * The narrative, not the outcome. `resolution` and
         * `refund_amount_ngwee` are part of the money trail and stay.
         */
        $disputes = OrderDispute::query()
            ->where('opened_by', $user->getKey())
            ->update([
                'details' => $anonymiser->text(),
                'updated_at' => now(),
            ]);

        return [
            /*
             * Reported as rows touched, not rows removed. Both tables keep
             * every row they had; see the class docblock.
             */
            'orders' => $orders,
            'order_disputes' => $disputes,
        ];
    }

    /**
     * A stored address object as one readable line.
     *
     * @param  array<string, mixed>  $address
     */
    private function flattenAddress(array $address): string
    {
        $parts = array_filter(
            [
                $address['recipient_name'] ?? null,
                $address['recipient_phone'] ?? null,
                $address['street'] ?? null,
                $address['plot_number'] ?? null,
                $address['city'] ?? null,
                $address['province'] ?? null,
            ],
            static fn (mixed $part): bool => is_string($part) && $part !== '',
        );

        return implode(', ', $parts);
    }
}
