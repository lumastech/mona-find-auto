<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Integration Drivers
    |--------------------------------------------------------------------------
    |
    | Every third-party integration sits behind an interface in App\Contracts
    | so tests can swap in a fake. The driver selected here decides which
    | implementation App\Providers\IntegrationServiceProvider binds.
    |
    */

    'payment_gateway' => [
        'driver' => env('PAYMENT_GATEWAY_DRIVER', 'fake'),
    ],

    'sms' => [
        'driver' => env('SMS_PROVIDER', 'log'),

        /*
         * The alphanumeric sender ID every message goes out under unless the
         * caller names another. Zamtel registers these per account; an
         * unregistered ID is silently replaced by the network with a short
         * code, which is how a platform ends up sending codes that look like
         * spam. Keep it short — eleven characters is the GSM limit.
         */
        'sender_id' => env('SMS_SENDER_ID', 'MonaFind'),

        'zamtel' => [
            'base_url' => env('ZAMTEL_SMS_URL', 'https://sms.zamtel.co.zm/api/v1'),
            'api_key' => env('ZAMTEL_SMS_API_KEY'),
            'username' => env('ZAMTEL_SMS_USERNAME'),
            'timeout' => (int) env('ZAMTEL_SMS_TIMEOUT', 15),
        ],
    ],

    'maps' => [
        'driver' => env('MAPS_PROVIDER_DRIVER', 'fake'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Captcha
    |--------------------------------------------------------------------------
    |
    | Proof-of-human on the public forms. The default driver challenges nobody
    | so that a fresh checkout has a working signup form; production sets
    | CAPTCHA_DRIVER=turnstile and supplies the key pair.
    |
    | The secret key is server-side only — the browser is handed the site key
    | and nothing else. See App\Support\Captcha\CaptchaGuard for the policy
    | that sits over the driver.
    |
    */

    'captcha' => [
        'driver' => env('CAPTCHA_DRIVER', 'null'),

        /*
         * Which forms carry a challenge. A form absent from this list is not
         * challenged even when the driver is on, so enabling Turnstile cannot
         * silently put a widget in front of a form with nowhere to show it.
         */
        'forms' => [
            'register',
            'password-reset',
            'seller-enquiry',
            'quotation',
        ],

        /*
         * What to do when Cloudflare cannot be reached. Open: accept the
         * submission and log it — an outage at Cloudflare should not close
         * MonaFind's registration and password-reset forms. A token that was
         * actually rejected is never failed open, only an unanswered one.
         */
        'fail_open' => (bool) env('CAPTCHA_FAIL_OPEN', true),

        'turnstile' => [
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret_key' => env('TURNSTILE_SECRET_KEY'),
            'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),

            /*
             * Optional: reject a token minted for another host. Leave null
             * unless the deployment serves one hostname.
             */
            'hostname' => env('TURNSTILE_HOSTNAME'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fake Transcript
    |--------------------------------------------------------------------------
    |
    | The fake providers keep an in-memory transcript of every call. When the
    | path below is set they additionally append a JSON line per call, which
    | is useful when driving the app by hand in a local environment.
    |
    */

    'fakes' => [
        'transcript_path' => storage_path('logs/integration-fakes.log'),
    ],

];
