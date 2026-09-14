# Load testing

**Targets (from the brief):** search p95 < 300 ms, checkout API p95 < 500 ms, on a corpus
of 50 sellers and 5,000 listings.

Both are **assertions**, not observations. `tests/Load/storefront.js` declares them as k6
thresholds, so k6 exits non-zero when either is breached and a performance regression
fails the run the same way a broken test does.

## Building the corpus

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=LoadTestSeeder          # 50 shops, 5,000 listings
php artisan scout:sync-index-settings
php artisan scout:import 'App\Modules\Catalog\Models\Product'
```

`LoadTestSeeder` refuses to run in production and is idempotent — running it twice does
not double the corpus.

### The shape of the corpus is the point

Five thousand identical listings would measure the query planner's luck rather than the
platform: every card would hit the same category, the same make and the same freshness
state, and Meilisearch would answer every query from one hot page of the index. So the
seeder spreads things deliberately:

| Spread | Why |
|---|---|
| Every seeded category and make | Facet counts have to be computed rather than guessed |
| All condition, inspection and freshness states | The ranking weights do real work instead of tying on every row and falling through to price |
| Prices across three orders of magnitude | "Price descending" within a tier actually sorts something |
| 1 shop in 10 unverified, 1 listing in 20 unpublished, 1 variant in 10 out of stock | `Product::scopePublished()` is the filter every storefront query carries, and a corpus where it excludes nothing would never exercise it |

`tests/Feature/Foundation/LoadTestSeederTest.php` asserts each of those properties, so
the corpus cannot be quietly "simplified" into one that flatters the numbers.

## Running it

```bash
# In one terminal — a real server, not `artisan serve`, for anything you intend to quote.
php artisan octane:start   # or the deployment's own stack

# In another
k6 run -e BASE_URL=http://localhost:8000 tests/Load/storefront.js
```

To exercise the authenticated half, mint a token and pass it:

```bash
php artisan tinker --execute '
    echo App\Models\User::query()->where("email", "buyer@monafind.test")
        ->sole()->createToken("k6")->plainTextToken;
'

k6 run -e BASE_URL=http://localhost:8000 -e API_TOKEN=<token> tests/Load/storefront.js
```

Without a token the checkout scenario measures the 401, which is worth knowing and is
not checkout.

## What the script does, and why

Two scenarios with different shapes, because the traffic has different shapes.

**Browsing** ramps to 50 virtual users over three minutes. It is open to guests and is
where the traffic is. Each iteration searches, lists, and then **sleeps one to three
seconds** — without that the test is a flood rather than a load, and a flood measures
queueing rather than the thing being tested.

**Checkout** runs at a constant five iterations a second regardless of how the browsing
scenario is doing. It is authenticated, far rarer, and every iteration writes; a constant
arrival rate keeps its own contention from distorting the search figures it shares a
database with.

Search terms are drawn from a list that includes partial matches and one outright miss.
Hammering a single query measures the second request onwards against a warm Meilisearch
cache and reports a number production will never see. A search that finds nothing still
costs a round trip and is a real thing buyers do.

429s are **not** counted as failures. The rate limiters are meant to fire under this much
load, and a limiter doing its job is correct behaviour rather than an error.

## Results

> Run the script and paste the summary here. The table below is the shape to fill in; it
> is deliberately empty rather than populated with numbers nobody measured.

| Date | Commit | Environment | Search p95 | Checkout p95 | Error rate | Verdict |
|---|---|---|---|---|---|---|
| | | | | | | |

Record the environment honestly — instance size, whether Octane was on, whether
Meilisearch was on the same host. A p95 from a developer laptop and a p95 from the pilot
instance are different claims and only one of them belongs on a go/no-go checklist.

### What was measured at M15

The corpus, the script and the thresholds are committed and the seeder is proven by
test. **The figures themselves have not been captured**, because that needs the pilot
instance rather than a development machine — a laptop number would be either
embarrassingly good (no network, warm caches, one user) or misleadingly bad, and neither
is evidence.

Capturing them against the pilot instance is a go/no-go item on `docs/LAUNCH.md`.

## If a target is missed

In the order worth checking:

1. **N+1 queries.** `Model::preventLazyLoading()` is on everywhere but production, so
   these fail the test suite rather than appearing here. If one reaches production it
   will show as a checkout p95 that rises with cart size.
2. **Meilisearch.** Confirm `scout:sync-index-settings` has run. Filtering on an
   attribute missing from `filterableAttributes()` fails **silently** and returns wrong
   results quickly, which looks like good performance.
3. **The quality score.** It is computed at index time, not per query. A search that
   slowed down after a ranking-weight change means a reindex was skipped.
4. **Eager loads on the card.** `Product::scopeWithCardRelations()` is the one
   definition of what a listing card reads. A resource that grew a relation without it
   is twenty-five extra queries per page.
5. **The cache.** `settings()` is cached; a cache miss on every request puts a database
   round trip in front of every page.
