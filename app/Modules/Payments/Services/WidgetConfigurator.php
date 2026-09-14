<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Orders\Models\OrderGroup;

/**
 * What the browser is told about a payment.
 *
 * ## The one job
 *
 * This class is the ONLY place that builds the props the Lenco widget is
 * handed, and it reads exactly one key from config: `lenco.public_key`. It
 * never touches `lenco.secret_key`. Concentrating that here means the promise
 * "the secret key never leaves the server" is a property of a single small
 * class with a single test pointed at it, rather than a habit spread across
 * every controller that renders a pay button.
 *
 * ## Why the amount is a string
 *
 * Lenco's widget wants decimal kwacha — 1234.56, not 123456 — and the moment
 * that becomes a PHP float somebody loses a ngwee to binary rounding. It
 * leaves here as a string from Money and the JavaScript hands it straight on.
 * The ngwee figure travels alongside for our own rendering, which is integer
 * throughout.
 */
class WidgetConfigurator
{
    /**
     * The full widget payload for an attempt.
     *
     * @param  array{reference: string, attempt: int, amount_ngwee: int, amount: string}  $attempt
     * @return array<string, mixed>
     */
    public function forAttempt(OrderGroup $group, array $attempt, User $buyer): array
    {
        $environment = (string) config('lenco.environment', 'sandbox');

        return [
            'publicKey' => (string) config('lenco.public_key', ''),
            'widgetUrl' => (string) config("lenco.endpoints.{$environment}.widget"),
            'environment' => $environment,

            'reference' => $attempt['reference'],
            'attempt' => $attempt['attempt'],
            'amount' => $attempt['amount'],
            'amountNgwee' => $attempt['amount_ngwee'],
            'currency' => 'ZMW',
            'channels' => array_values((array) config('lenco.collections.channels', ['card', 'mobile-money'])),
            'bearer' => (string) settings('payments.fee_bearer', config('lenco.collections.bearer', 'merchant')),
            'label' => __('MonaFind order :id', ['id' => $group->public_id]),

            'customer' => $this->customer($buyer),
            'billing' => $this->billing($group),

            /* Where onSuccess posts, and where the buyer waits afterwards. */
            'verifyUrl' => route('payments.verify', $group->public_id),
            'statusUrl' => route('payments.status', $group->public_id),
        ];
    }

    /**
     * Prefill, split the way Lenco wants it.
     *
     * The buyer's name arrives as one field on MonaFind and the widget wants
     * two, so a single-word name becomes a first name with no surname rather
     * than a surname repeated — which is what a naive split produces and what
     * a buyer then sees printed on their receipt.
     *
     * @return array<string, string>
     */
    private function customer(User $buyer): array
    {
        $parts = preg_split('/\s+/', trim((string) $buyer->name), 2) ?: [];

        return array_filter([
            'email' => (string) $buyer->email,
            'firstName' => $parts[0] ?? '',
            'lastName' => $parts[1] ?? '',
            'phone' => (string) ($buyer->phone ?? ''),
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * Billing address, taken from a delivery order in the group if there is
     * one. Card issuers use it for verification; it is prefill, not a record.
     *
     * @return array<string, string>
     */
    private function billing(OrderGroup $group): array
    {
        $order = $group->orders->first(
            static fn ($order): bool => filled($order->delivery_address),
        );

        /** @var array<string, mixed> $address */
        $address = is_array($order?->delivery_address) ? $order->delivery_address : [];

        return array_filter([
            'streetAddress' => $this->stringValue($address, 'line_one') ?: $this->stringValue($address, 'street'),
            'city' => $this->stringValue($address, 'city'),
            'state' => $this->stringValue($address, 'province') ?: $this->stringValue($address, 'state'),
            'country' => 'ZM',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function stringValue(array $address, string $key): string
    {
        $value = $address[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
