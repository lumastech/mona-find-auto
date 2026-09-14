```markdown
# MonaFindAuto — Project Context for Claude Code

You are building MonaFindAuto, a multi-vendor automotive-parts marketplace for the Zambian market (currency ZMW, timezone Africa/Lusaka, English only). Buyers find and buy vehicle parts from verified sellers (auto-parts sellers, spare-parts shops, garages, car breakers, automotive retailers, workshop operators, car dealers). Mechanics have endorsed public profiles. MonaFind staff verify sellers, moderate listings, manage disputes and money.

## Stack (fixed — do not substitute)

- Laravel 13.x, PHP. MySQL. Redis (cache, queues). Laravel Horizon.
- Inertia.js 3 + Vue 3 (Composition API, TypeScript) + Tailwind CSS + Vite. SSR enabled
  for storefront listing/seller pages (SEO).
- Meilisearch via Laravel Scout for search.
- Laravel Fortify (web auth) + Sanctum (API tokens for the future mobile app).
- spatie/laravel-permission (roles), spatie/laravel-medialibrary (media),
  spatie/laravel-activitylog only for non-financial logs (financial audit is custom).
- Pest for backend tests, Vitest for component tests, Laravel Dusk for the checkout flow.
- Payments: Lenco API v2 (https://lenco-api.readme.io/v2.0) + Lenco inline widget.
  Sandbox (pay.sandbox.lenco.co / api sandbox keys) everywhere except production.
- Google Maps JS API + Geocoding + Distance Matrix. SMS: Zamtel sms.
- NO Odoo, NO external ERP/CRM. All finance lives in the internal double-entry ledger.

## Architecture rules

- Modular monolith. Each module lives in `app/Modules/<Name>` with its own Models,
  Http/Controllers, Services, Actions, Events, Listeners, Jobs, Policies, and
  `resources/js/Pages/<Area>/...` Inertia pages. Modules communicate through domain
  events and service interfaces, never by reaching into another module's internals.
- Three Inertia areas sharing a component library: Storefront (guests/buyers),
  Seller (seller portal), Admin (staff console). Route groups: `/`, `/seller`, `/admin`.
- Mirror every buyer/seller capability as a versioned JSON API under `/api/v1`
  (Sanctum), documented with OpenAPI annotations — the mobile app will consume it.
- Integrations behind interfaces with fake implementations for tests:
  `PaymentGateway` (LencoGateway), `SmsProvider` (ZamtelSmsProvider, LogProvider),
  `MapsProvider` (GoogleMapsProvider).
- All side effects (webhooks, notifications, media processing, payouts, reconciliation,
  freshness checks) run as queued jobs via Horizon. Webhook ingestion must be idempotent.

## Money rules (non-negotiable)

- Store all money as integer ngwee (ZMW minor units). Render with 2 decimals. Never float.
- Double-entry ledger: every movement is a balanced JournalEntry with JournalLines.
  Ledger accounts: platform_cash (Lenco), escrow_held, seller_payable, commission_revenue,
  addon_revenue, referral_revenue, vat_on_commission_payable, refunds_expense,
  lenco_fees_expense, seller_reserve.
- Product prices are VAT-INCLUSIVE. Sellers are responsible for their own VAT on goods.
  MonaFind invoices sellers for its commission only (commission + VAT on commission at the
  configured rate). Seller payout = gross order amount − commission − add-on/referral fees
  (per the seller's monetisation policy) − any reserve; the payout is tax-inclusive from
  the seller's perspective.
- Monetisation policy is PER SELLER with three configurable components: percentage/flat
  commission, add-on fee, referral fee. Global defaults; admin overrides per seller.
  The applicable policy is SNAPSHOTTED onto each order at payment time.
- Payment mode is PER SELLER: ESCROW (default) or DIRECT (admin-set, audited). The mode
  is snapshotted onto each order at payment time; later changes never affect in-flight
  orders. Escrow releases on buyer confirmation or auto-complete (windows configurable
  in admin: default 3 days pickup, 7 days delivery). DIRECT sellers carry a rolling
  reserve (default 10% of trailing 30-day sales, configurable) and auto-revert to ESCROW
  if dispute rate exceeds the threshold.
- Lenco secret key server-side only; browser gets the public key. Verify every collection
  server-side by reference AND consume `collection.successful` webhooks (signature-checked,
  idempotent). Payment references: `MFA-{orderGroupId}-{attempt}`, chars [A-Za-z0-9._-].

## Product & trust rules

- Condition badges: Brand New, Used, Car Breaker (automatic for car-breaker sellers).
  SEPARATELY, every product card and listing page MUST show the Inspected/Uninspected
  badge (admin-set flag, default Uninspected). These are two independent attributes.
- Stock freshness: sellers confirm stock at least every 3–5 days. States: Fresh ≤3d,
  Ageing 4–5d (small ranking demotion), Unconfirmed >5d (label + strong demotion),
  Hidden >14d (configurable). One-tap "All stock accurate" confirmation.
- Guests may browse everything. Seller contact details for guests: show the field LABELS
  (Phone, Email, Contact person) with the VALUES blurred and a "Log in to view" prompt.
  Logged-in buyers see the values.
- Search default ranking: exact-match tier → partial tier; within tier by quality score
  (weights admin-configurable: seller rating 30, review count 10, verified 20, inspected 15,
  freshness 15, low disputes 10) then PRICE DESCENDING. Distance is never part of the
  default sort but "Nearest first" sort + radius filter must exist.
- Ratings: one per counter-party per completed order/endorsement. Buyer→Seller,
  Buyer→Mechanic public; Seller→Buyer, Mechanic→Buyer visible to sellers only.

## Quality bar

- Pest feature tests for every service and controller; the payments/ledger module targets
  ~100% branch coverage on money paths. Factories + seeders for all models.
- PHPStan level 8, Laravel Pint, ESLint + vue-tsc in CI. No `env()` outside config.
- Every staff action and every money movement writes an immutable AuditLog row
  (actor, action, subject, before/after, reason). No updates/deletes on AuditLog,
  JournalEntry, JournalLine, TermsAcceptance, Payment — append-only.
- Mobile-first responsive UI, usable on low-end Android; WCAG 2.1 AA on storefront.
- Never store card data (Lenco widget handles it). Encrypt payout account fields at rest.
```

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
