<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lenco (payments, API v2)
    |--------------------------------------------------------------------------
    |
    | The secret key must never leave the server. Only `public_key` is shared
    | with the browser for the inline checkout widget.
    |
    */

    'lenco' => [
        'environment' => env('LENCO_ENVIRONMENT', 'sandbox'),
        'base_url' => env('LENCO_BASE_URL', 'https://sandbox.lenco.co/access/v2'),
        'checkout_url' => env('LENCO_CHECKOUT_URL', 'https://pay.sandbox.lenco.co'),
        'public_key' => env('LENCO_PUBLIC_KEY'),
        'secret_key' => env('LENCO_SECRET_KEY'),
        'account_id' => env('LENCO_ACCOUNT_ID'),
        'webhook_secret' => env('LENCO_WEBHOOK_SECRET'),
        'timeout' => (int) env('LENCO_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Maps (JS API, Geocoding, Distance Matrix)
    |--------------------------------------------------------------------------
    */

    'google_maps' => [
        'browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),
        'region' => env('GOOGLE_MAPS_REGION', 'ZM'),
        'language' => env('GOOGLE_MAPS_LANGUAGE', 'en'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zamtel SMS
    |--------------------------------------------------------------------------
    */

    'zamtel_sms' => [
        'base_url' => env('ZAMTEL_SMS_BASE_URL', 'https://bulksms.zamtel.co.zm/api/v3'),
        'username' => env('ZAMTEL_SMS_USERNAME'),
        'password' => env('ZAMTEL_SMS_PASSWORD'),
        'api_key' => env('ZAMTEL_SMS_API_KEY'),
        'sender_id' => env('ZAMTEL_SMS_SENDER_ID', 'MonaFind'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social sign-in (Socialite)
    |--------------------------------------------------------------------------
    |
    | A provider is only offered on the login screen once both its client id
    | and secret are set, so an unconfigured deployment shows no button rather
    | than a button that fails.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', '/auth/facebook/callback'),
    ],

];
