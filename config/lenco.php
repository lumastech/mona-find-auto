<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Sandbox everywhere except production, per CLAUDE.md. This is deliberately
    | not derived from app.env inside the gateway: a staging box pointed at
    | live keys by mistake is the kind of error that costs real money, so the
    | choice is written down here where it can be read at a glance.
    |
    */

    'environment' => env('LENCO_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The secret key authenticates every server-to-server call and must never
    | reach the browser. The public key is the only one the inline widget
    | needs, and is the only one any Inertia prop or API response may carry.
    |
    */

    'secret_key' => env('LENCO_SECRET_KEY'),
    'public_key' => env('LENCO_PUBLIC_KEY'),

    /*
     * Lenco signs webhooks with the "webhook hash key", documented as the
     * SHA-256 of the API token. It is derived rather than configured so the
     * two cannot drift apart, but stays overridable because a derived secret
     * that turns out to be wrong is otherwise impossible to correct.
     */
    'webhook_secret' => env('LENCO_WEBHOOK_SECRET'),

    /*
     * The Lenco account transfers are debited from. Required by
     * /transfers/bank-account and /transfers/mobile-money; a payout run
     * cannot start without it.
     */
    'account_id' => env('LENCO_ACCOUNT_ID'),

    /* Zambia. Sent on resolve, transfer and bank-list calls. */
    'country' => env('LENCO_COUNTRY', 'zm'),

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    |
    | Keyed by environment so that switching one env var moves the API calls
    | and the widget together, and neither is ever chosen on its own.
    |
    | The widget really does have two hosts. The REST API does not: Lenco
    | serves both environments from the same host and tells them apart by the
    | API token, so the sandbox row below is not a typo. Both stay
    | env-overridable in case that changes.
    |
    */

    'endpoints' => [

        'sandbox' => [
            'api' => env('LENCO_API_URL', 'https://api.lenco.co/access/v2'),
            'widget' => env('LENCO_WIDGET_URL', 'https://pay.sandbox.lenco.co/js/v1/inline.js'),
        ],

        'live' => [
            'api' => env('LENCO_API_URL', 'https://api.lenco.co/access/v2'),
            'widget' => env('LENCO_WIDGET_URL', 'https://pay.lenco.co/js/v1/inline.js'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | `bearer` decides who pays Lenco's charge on a collection. MonaFind bears
    | it by default: a buyer quoted K450 at checkout pays K450, and the cost of
    | taking the money is the platform's business.
    |
    | This value is the DEFAULT the `payments.fee_bearer` setting is seeded
    | from, not the value in force — the staff console owns it, because the
    | brief asks for it to be switchable without a deployment. Read it through
    | settings('payments.fee_bearer') and fall back to this.
    |
    */

    'collections' => [
        'bearer' => env('LENCO_FEE_BEARER', 'merchant'),
        'channels' => ['card', 'mobile-money'],
        'stuck_after_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Retries cover a dropped connection, never a refusal: the gateway client
    | retries idempotent reads and lets writes fail up to the caller, because
    | a retried transfer that actually succeeded the first time pays a seller
    | twice.
    |
    */

    'http' => [
        'timeout' => (int) env('LENCO_HTTP_TIMEOUT', 30),
        'retry_times' => (int) env('LENCO_HTTP_RETRIES', 2),
        'retry_sleep_ms' => (int) env('LENCO_HTTP_RETRY_SLEEP', 200),
    ],

];
