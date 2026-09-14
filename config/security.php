<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Built per request by App\Http\Middleware\SecurityHeaders and delivered
    | with a fresh nonce, so the one inline script in resources/views/app.blade.php
    | runs without opening the page to every other inline script.
    |
    | The third-party hosts below are the complete list of origins a MonaFind
    | page is allowed to talk to. Adding an integration means adding its host
    | here — which is the point: the list is a readable inventory of who the
    | browser trusts, and a reviewer can check it against what we actually use.
    |
    */

    'csp' => [

        /*
         * Off in local development. Vite's dev server injects its own inline
         * scripts and hot-update styles, and a policy strict enough to be
         * worth having in production is one that breaks `npm run dev`.
         */
        'enabled' => (bool) env('CSP_ENABLED', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

        /*
         * Report-only sends the policy without enforcing it, so a deployment
         * can collect violations for a week before turning the switch. Set
         * CSP_REPORT_ONLY=true for that first week.
         */
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        /*
         * Where the browser posts violations. Null omits the directive.
         */
        'report_uri' => env('CSP_REPORT_URI'),

        /*
         * Third-party origins, grouped by what they are needed for so that
         * removing an integration means removing one block.
         */
        'allow' => [

            /* Cloudflare Turnstile: the challenge widget and its iframe. */
            'captcha' => [
                'script' => ['https://challenges.cloudflare.com'],
                'frame' => ['https://challenges.cloudflare.com'],
            ],

            /*
             * Lenco: the inline payment widget. Both hosts are listed because
             * a deployment runs against exactly one of them and which one is
             * an environment decision, not a policy decision.
             */
            'payments' => [
                'script' => ['https://pay.lenco.co', 'https://pay.sandbox.lenco.co'],
                'frame' => ['https://pay.lenco.co', 'https://pay.sandbox.lenco.co'],
                'connect' => ['https://api.lenco.co', 'https://api.sandbox.lenco.co'],
            ],

            /* Google Maps JS API: script, tiles, and its own XHR. */
            'maps' => [
                'script' => ['https://maps.googleapis.com'],
                'connect' => ['https://maps.googleapis.com'],
                'image' => ['https://maps.googleapis.com', 'https://maps.gstatic.com', 'https://*.googleapis.com', 'https://*.ggpht.com'],
            ],

            /* Bunny Fonts, which Laravel's @fonts directive serves from. */
            'fonts' => [
                'style' => ['https://fonts.bunny.net'],
                'font' => ['https://fonts.bunny.net'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Sent on HTTPS responses only — an HSTS header over plain HTTP is ignored
    | by browsers and would be a lie in local development.
    |
    | `preload` is deliberately off by default. Submitting a domain to the
    | preload list is close to irreversible and should be a decision someone
    | makes on the launch checklist, not a default inherited from a config file.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', true),
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('HSTS_PRELOAD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storefront Caching
    |--------------------------------------------------------------------------
    |
    | Applied by App\Http\Middleware\CacheStorefrontResponses to signed-out
    | storefront pages. Authenticated responses are always private and never
    | stored, whatever is set here.
    |
    | `shared_seconds` is OFF by default and turning it on is a promise about
    | infrastructure: it marks guest pages `public`, which is only safe if the
    | CDN in front of MonaFind strips session cookies from guest requests and
    | refuses to store a response carrying Set-Cookie. See the middleware's
    | docblock, and the line about it on docs/LAUNCH.md.
    |
    | Keep any window short. A listing's stock and price change during the
    | day, and a stale card sends a buyer across Lusaka for a part that sold
    | this morning.
    |
    */

    'cache' => [
        'browser_seconds' => (int) env('STOREFRONT_BROWSER_CACHE_SECONDS', 30),
        'shared_seconds' => (int) env('STOREFRONT_SHARED_CACHE_SECONDS', 0),
        'stale_seconds' => (int) env('STOREFRONT_STALE_CACHE_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational Alerts
    |--------------------------------------------------------------------------
    |
    | Where the platform shouts when money stops moving: failed queue jobs,
    | payment failures, reconciliation variances.
    |
    | These are not routed through the notification preference matrix and are
    | not meant to be — an operator must not be able to switch off the alert
    | that says payouts have stopped. Comma-separated, or an array.
    |
    | Empty means alerts are logged and not mailed, which is right for local
    | development. Setting this is on the launch checklist.
    |
    */

    'alerts' => [
        'recipients' => env('ALERT_RECIPIENTS', ''),

        /*
         * Failed jobs are alerted per job class rather than per job, so one
         * crash-looping worker sends one email rather than one per attempt.
         * Past this many in a cooldown window the alert escalates.
         */
        'failed_job_burst' => (int) env('ALERT_FAILED_JOB_BURST', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Endpoint Token
    |--------------------------------------------------------------------------
    |
    | The deep health report at /health names every dependency the platform
    | has and which of them are broken — useful to an operator and just as
    | useful to somebody choosing a moment to attack.
    |
    | Empty leaves the endpoint open, which is right for a local install. The
    | launch checklist carries the line that sets one in production.
    |
    */

    'health_token' => env('HEALTH_CHECK_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Permissions Policy
    |--------------------------------------------------------------------------
    |
    | Features the page does not use, switched off so that an injected script
    | cannot reach for them. `geolocation=(self)` is the one exception: the
    | "Nearest first" sort asks the browser where the buyer is.
    |
    */

    'permissions_policy' => env('PERMISSIONS_POLICY', implode(', ', [
        'accelerometer=()',
        'autoplay=()',
        'camera=()',
        'display-capture=()',
        'encrypted-media=()',
        'geolocation=(self)',
        'gyroscope=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'payment=()',
        'usb=()',
    ])),

];
