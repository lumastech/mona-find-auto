<?php

declare(strict_types=1);

use App\Support\Money\Money;

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Every monetary amount in the platform is stored as an integer number of
    | ngwee (ZMW minor units) and only converted to a decimal string when it
    | is rendered. Never introduce a float into a money path.
    |
    */

    'currency' => Money::currencyDescriptor(),

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC (see config/app.php) and rendered in the
    | timezone below. Keeping storage in UTC keeps ledger and audit ordering
    | unambiguous across DST-free but offset-shifting deployments.
    |
    */

    'display_timezone' => env('MONAFIND_DISPLAY_TIMEZONE', 'Africa/Lusaka'),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Named queues consumed by Horizon. Jobs should always be dispatched onto
    | one of these rather than hard-coding a queue name at the call site.
    |
    */

    'queues' => [
        'default' => 'default',
        'payments' => 'payments',
        'media' => 'media',
        'notifications' => 'notifications',
        'search' => 'search',
    ],

];
