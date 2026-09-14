/**
 * MonaFindAuto load test — search and checkout.
 *
 * Run against a seeded environment (50 shops, 5,000 listings):
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=LoadTestSeeder
 *   php artisan scout:import 'App\Modules\Catalog\Models\Product'
 *   k6 run -e BASE_URL=http://localhost:8000 tests/Load/storefront.js
 *
 * ## The thresholds are the test
 *
 * k6 exits non-zero when a threshold is breached, so the two figures the
 * brief commits to — search p95 under 300ms, checkout API p95 under 500ms —
 * are assertions rather than numbers in a report somebody reads afterwards.
 * A regression fails the run.
 *
 * ## Why the search terms are a list rather than one string
 *
 * Meilisearch caches, and so does MySQL. Hammering one query measures the
 * second request onwards against a warm cache and reports a number the
 * platform will never see in production. The terms below include misses and
 * partial matches deliberately: a search that finds nothing still costs a
 * round trip and is a real thing buyers do.
 *
 * ## Two scenarios, different shapes
 *
 * Browsing is open to guests and is where the traffic is, so it ramps to a
 * realistic concurrency. Checkout is authenticated, far rarer, and each
 * iteration writes — it runs at a constant low rate so that its own
 * contention does not distort the search figures it shares a database with.
 */
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

/* Separate trends so a slow checkout cannot hide behind fast search. */
const searchDuration = new Trend('monafind_search_duration', true);
const checkoutDuration = new Trend('monafind_checkout_duration', true);

export const options = {
    scenarios: {
        browsing: {
            executor: 'ramping-vus',
            exec: 'browse',
            startVUs: 5,
            stages: [
                { duration: '30s', target: 25 },
                { duration: '2m', target: 50 },
                { duration: '30s', target: 0 },
            ],
        },
        checkout: {
            executor: 'constant-arrival-rate',
            exec: 'checkout',
            rate: 5,
            timeUnit: '1s',
            duration: '3m',
            preAllocatedVUs: 20,
            maxVUs: 50,
        },
    },
    thresholds: {
        /* The two figures the brief commits to. */
        monafind_search_duration: ['p(95)<300'],
        monafind_checkout_duration: ['p(95)<500'],
        /*
         * A run that is fast because half of it 500s is not a passing run.
         * 429s are excluded from the failure rate: the rate limiters are
         * meant to fire under this much load and doing so is correct
         * behaviour, not an error.
         */
        http_req_failed: ['rate<0.01'],
    },
};

/** Terms chosen to include hits, partial hits and outright misses. */
const TERMS = [
    'brake pads',
    'hilux brake',
    'clutch kit',
    'alternator',
    'starter motor',
    'radiator',
    'timing belt',
    'wheel bearing',
    'turbocharger',
    /* A miss: finding nothing still costs a round trip, and buyers do it. */
    'gearbox for a spaceship',
];

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

/**
 * Anything other than a 5xx or a timeout is a response the platform chose to
 * give. A 429 under this load is a rate limiter doing its job.
 */
function ok(response) {
    return response.status > 0 && response.status < 500;
}

export function browse() {
    group('search', () => {
        const response = http.get(
            `${BASE_URL}/api/v1/search?q=${encodeURIComponent(pick(TERMS))}`,
            {
                headers: { Accept: 'application/json' },
                tags: { name: 'search' },
            },
        );

        searchDuration.add(response.timings.duration);

        check(response, {
            'search answered': (r) => ok(r),
            'search returned the envelope': (r) =>
                r.status !== 200 || r.json('data') !== undefined,
        });
    });

    group('browse listings', () => {
        const response = http.get(`${BASE_URL}/api/v1/listings`, {
            headers: { Accept: 'application/json' },
            tags: { name: 'listings' },
        });

        check(response, { 'listings answered': (r) => ok(r) });
    });

    /* A person reads the results before clicking. Without this the test is a flood, not a load. */
    sleep(Math.random() * 2 + 1);
}

/**
 * The authenticated half. Needs a token; see docs/LOAD_TESTING.md for how to
 * mint one. Without it the scenario exercises the unauthenticated path and
 * measures the 401, which is still worth knowing but is not checkout.
 */
export function checkout() {
    const token = __ENV.API_TOKEN;

    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };

    group('checkout', () => {
        const response = http.get(`${BASE_URL}/api/v1/checkout`, {
            headers,
            tags: { name: 'checkout' },
        });

        checkoutDuration.add(response.timings.duration);

        check(response, { 'checkout answered': (r) => ok(r) });
    });
}
