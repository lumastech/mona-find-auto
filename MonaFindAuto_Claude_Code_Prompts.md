# MonaFindAuto — Claude Code Implementation Prompt Pack

**Source:** Project Development Concept Document V3 (reviewed & approved), Kasmona Corporation, ticket 2026/08/0001.
**Change applied:** Odoo integration removed entirely (former M14). Monetisation policy engine (Q2), VAT model (Q4), configurable escrow windows (Q5), mandatory inspection badge (Q11) and blurred guest contact details (Q13) are incorporated per the client's answers.

**How to use this pack**

1. Put **Part A** into the repository as `CLAUDE.md` before the first session. It is the standing context every prompt relies on; do not paste it repeatedly.
2. Run **Prompt 00** first to scaffold the project.
3. Then run the module prompts in the order given in Part B. Each prompt assumes the previous ones are merged. Run one prompt per session/branch; review and merge before starting the next.
4. Every prompt ends with acceptance criteria. Tell the agent: "Do not report done until all acceptance criteria pass and `composer test` + `npm run build` are green."

---

## PART A — Standing project context (save as `CLAUDE.md`)

```markdown
# MonaFindAuto — Project Context for Claude Code

You are building MonaFindAuto, a multi-vendor automotive-parts marketplace for the Zambian market (currency ZMW, timezone Africa/Lusaka, English only). Buyers find and buy vehicle parts from verified sellers (auto-parts sellers, spare-parts shops, garages, car breakers, automotive retailers, workshop operators, car dealers). Mechanics have endorsed public profiles. MonaFind staff verify sellers, moderate listings, manage disputes and money.

## Stack (fixed — do not substitute)

- Laravel 13.x, PHP. MySQL. Redis (cache, queues). Laravel Horizon.
- Inertia.js 2 + Vue 3 (Composition API, TypeScript) + Tailwind CSS + Vite. SSR enabled
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

## PART B — Build order

| #   | Prompt                                         | Depends on         | Concept ref    |
| --- | ---------------------------------------------- | ------------------ | -------------- |
| 00  | Scaffold & platform foundations                | —                  | M15, §9        |
| 01  | Identity & Access                              | 00                 | M01            |
| 02  | Seller Onboarding & Verification               | 01                 | M02            |
| 03  | Reference Data, Catalogue & Listings           | 02                 | M04            |
| 04  | Inventory & Stock Freshness                    | 03                 | M05            |
| 05  | Search, Filter & Discovery                     | 03                 | M06            |
| 06  | Wishlist, Cart & Quotations                    | 03                 | M07            |
| 07  | Checkout, Orders & Fulfilment                  | 04, 06             | M08            |
| 08  | Ledger & Monetisation Policies                 | 07                 | M09/M12 (part) |
| 09  | Lenco Payments, Escrow, Payouts & Refunds      | 08                 | M09, §7        |
| 10  | Ratings, Reviews & Trust                       | 07                 | M10            |
| 11  | Mechanic Directory & Endorsement               | 02                 | M03            |
| 12  | Notifications & Messaging                      | 01 (stubs earlier) | M13            |
| 13  | Admin Console & Moderation (consolidation)     | all above          | M11            |
| 14  | Finance Dashboard, Statements & Reconciliation | 09                 | M12, §7        |
| 15  | Hardening, Security, API & Launch checklist    | all                | M15, §10       |

(Former M14 Odoo Integration is **removed** — do not build any ERP connector, outbox, or sync log.)

---

## PART C — The prompts

---

### PROMPT 00 — Scaffold & platform foundations (M15 core)

```text
Read CLAUDE.md first. Task: scaffold the MonaFindAuto repository and cross-cutting foundations.

DELIVERABLES
1. Laravel 13.x app with Inertia 2 + Vue 3 + TypeScript + Tailwind + Vite, SSR configured for
   the Storefront area. Pest, PHPStan (level 8, larastan), Pint, ESLint, vue-tsc wired into
   composer/npm scripts and a GitHub Actions CI workflow (lint → static analysis → tests → build).
2. Docker-based dev environment (compose: app, mysql:8, redis, meilisearch, mailpit, horizon
   worker). `.env.example` covering all services, Lenco sandbox keys, Google Maps, Zamtel Sms, with comments.
3. Modular-monolith skeleton: `app/Modules/{Identity,Sellers,Catalog,Inventory,Search,Shopping,
   Orders,Payments,Ledger,Ratings,Mechanics,Messaging,Admin,Finance}` each with a ServiceProvider
   auto-registered via composer merge or a ModuleServiceProvider; per-module routes files loaded
   under the correct middleware groups (`web`, `seller`, `admin`, `api/v1`).
4. Three Inertia layout shells with shared component library (`resources/js/Components`):
   StorefrontLayout (header with search bar, wishlist heart, cart, auth menu), SellerLayout
   (sidebar), AdminLayout (sidebar). Placeholder dashboard page per area. Mobile-first.
5. Cross-cutting services:
   - `Money` value object (integer ngwee) + ZMW formatter + Vue `<Money>` component + cast.
   - `AuditLog` model/table (append-only; DB trigger or model guard preventing update/delete)
     with `Auditable` helper: `audit($actor,$action,$subject,$before,$after,$reason)`.
   - `Setting` model (typed key/value with cache) + `settings()` helper — this backs every
     "configurable" value in later prompts. Seed defaults: escrow windows (pickup 3d,
     delivery 7d), freshness thresholds (3/5/14 days), ranking weights, commission defaults,
     reserve percent (10), dispute-rate threshold (2%), seller confirm window (24h).
   - Interfaces + fakes: PaymentGateway, SmsProvider, MapsProvider (fakes log to array/file).
   - Horizon installed, queues: default, payments, media, notifications, search.
6. Base API plumbing: `/api/v1` route group with Sanctum, standard JSON envelope, exception
   renderer, OpenAPI generation (e.g. vyuldashev/laravel-openapi or scribe) with CI artifact.

ACCEPTANCE
- `docker compose up` gives a working app; CI pipeline green.
- Money object test: constructing from "10.75" ZMW yields 1075 ngwee; arithmetic never floats.
- AuditLog rows cannot be updated or deleted (test proves it).
- Settings are editable in tinker and cached reads reflect changes after cache clear.
- PHPStan level 8 passes on the skeleton.
```

---

### PROMPT 01 — Identity & Access (M01)

```text
Read CLAUDE.md. Build the Identity module in app/Modules/Identity.

SCOPE
Roles: buyer, seller_owner, seller_staff (placeholder), mechanic, moderator, finance, admin —
via spatie/laravel-permission. One User can be a buyer and a mechanic simultaneously.

DELIVERABLES
1. Registration (Fortify): first/last name, email, phone, password, address (province, city,
   street, plot/house number — province and city from seeded Zambian reference lists).
   Phone stored E.164, validated against Zambian mobile prefixes (096/076 MTN, 097/077 Airtel,
   095/075 Zamtel); this matters later for mobile-money resolution.
2. Phone OTP verification via SmsProvider (6-digit, 10-min expiry, 3 attempts, resend
   throttle) and email verification. Account status: pending, active, suspended, closed;
   middleware blocks suspended/closed with the recorded reason; suspension forces logout
   (session invalidation) and is audited.
3. Login with email OR phone + password, remember me, rate limiting, password reset via
   email and via SMS OTP. Two-factor (TOTP) MANDATORY for moderator/finance/admin roles —
   enforced at login, with recovery codes.
4. Buyer profile area (Storefront): edit profile, manage multiple saved delivery addresses
   (label, recipient, phone, province/city/street/plot, optional GPS pin via MapsProvider),
   change password, device/session list with revoke.
5. Admin: user search/list with filters (role, status), detail view with activity, actions
   warn/suspend/reinstate/close — each requires a reason and writes AuditLog.
6. `/api/v1/auth/*`: register, login (token), logout, me, addresses CRUD.
7. Policies/gates consumed by later modules: `staff`, `admin-only`, ownership checks.
8. login/signup via google/facebook.

ACCEPTANCE
- Pest: registration happy path + each validation branch; OTP expiry/attempt limits;
  suspended user is logged out on next request; 2FA enforced for staff; API token flow.
- Dusk or feature test proves a suspended user cannot act anywhere.
- Seeder creates one user per role for local dev (documented credentials in README).
```

---

### PROMPT 02 — Seller Onboarding & Verification (M02)

```text
Read CLAUDE.md. Build app/Modules/Sellers.

SELLER TYPES (enum): APS auto-parts seller, SPS spare-parts shop, G garage, CB car breaker,
AMR automotive retailer, WO workshop operator, CD car dealer. Business name REQUIRED for all.
Registration number optional at sign-up, REQUIRED before the Verified badge.

DELIVERABLES
1. Multi-step seller sign-up wizard (Inertia, resumable drafts):
   step 1 seller type → step 2 business details (business name, registration number, province,
   city, street, plot number, phone, email, contact person, GPS via Google Map pin with
   reverse-geocoded address prefill) → step 3 policies → step 4 payout details → step 5
   document uploads → review & submit.
2. Policies: SellerPolicy model, four types (delivery, refund, warranty, terms), rich-text
   body, VERSIONED with effective_from; editing creates a new version; buyers will accept
   the current version at checkout (Prompt 07). Platform minimum refund rule stored as a
   Setting and displayed alongside seller policy: wrong or damaged item refundable within
   3 days regardless of seller policy.
3. PayoutAccount: bank (beneficiary, account number, bank, branch, bank address, SWIFT
   optional, TPIN optional) or mobile money (number, network MTN/Airtel). Fields encrypted
   at rest. On save, validate via PaymentGateway->resolveBankAccount()/resolveMobileMoney()
   (Lenco resolve endpoints; fake in tests) and store the resolved name + lenco_recipient_id
   (create transfer recipient). A seller may hold several accounts; one is default.
4. Verification workflow: submitted → under_review → inspection_scheduled → verified |
   rejected(reason). Admin queue with document viewer (private media disk), checklist notes,
   badge grant. Registration number presence enforced at the verified transition. Every
   transition audited. Verified badge appears on profile + listings; unverified sellers get
   "Not yet verified" label (ranking effect handled in Prompt 05).
5. Payment mode + monetisation placeholders on the Seller model: payment_mode ESCROW|DIRECT
   (default ESCROW, admin-editable later with audit), monetisation_policy_id nullable
   (wired in Prompt 08). Sellers can SEE both, never edit.
6. Seller public page (Storefront, SSR): description, opening hours, location map, badges,
   rating placeholder, policies, product grid placeholder. CONTACT RULE: guests see field
   labels with blurred values + "Log in to view"; logged-in buyers see real values.
7. Seller dashboard shell: profile editor, policy manager, payout accounts, verification
   status card. Admin: seller list/filters, detail, verify/reject, suspend.
8. `/api/v1/sellers/{id}` public profile endpoint honouring the contact-blur rule.

ACCEPTANCE
- Pest: wizard step validation per seller type; policy versioning (old version retained);
  payout resolution failure blocks save with a clear error; verified transition blocked
  without registration number; contact blur verified for guest vs buyer in both Inertia
  and API responses.
- Encrypted payout columns proven unreadable raw in DB (test on the cast).
```

---

### PROMPT 03 — Reference Data, Catalogue & Listings (M04)

```text
Read CLAUDE.md. Build app/Modules/Catalog.

DELIVERABLES
1. Reference data with admin CRUD + seeders: Make, VehicleModel (belongsTo Make), part
   Category tree (nestedset), and enum lists for condition, OEM/aftermarket, fuel type,
   transmission, drive type, body type. Seed a realistic starter set (20 makes, common
   models, 3-level category tree).
2. Product + ProductVariant models. Product fields per the concept: name, category, make,
   model, year (single or range), condition (brand_new | used | car_breaker — car_breaker
   forced when the seller type is CB), oem_or_aftermarket, description, warranty text,
   delivery_available, part/OEM number, engine size, engine code, fuel type, transmission,
   drive type, trim/variant, body type, chassis/VIN compatibility. Variant carries SKU,
   price (Money, VAT-INCLUSIVE), quantity. Single-variant products auto-create one variant.
3. Media: photos required (min 1, max 10), video optional (max 60s). Queued pipeline:
   resize, WebP conversion, watermark with MonaFindAuto logo, responsive conversions;
   video transcode (ffmpeg) + poster frame. Private originals, public conversions.
4. Inspection flag: uninspected (default) | inspected, settable ONLY by staff, audited.
   Product cards and listing pages ALWAYS render both the condition badge and the
   Inspected/Uninspected badge (client requirement Q11) — build the badge components once
   in the shared library.
5. Listing lifecycle: draft → pending_review → published → unpublished | rejected(reason)
   | archived. Sellers submit; moderators review in an admin queue with side-by-side photo
   viewer, per-field reject reasons returned to the seller; re-submission allowed.
   Only VERIFIED-or-pending sellers may submit; suspended sellers' listings auto-unpublish.
6. Seller portal: product list with status filters, create/edit form (Vue, client-side
   validation mirroring server rules), media manager, duplicate warning on same part number
   + seller (non-blocking warning in R1).
7. Storefront: listing detail page (SSR) — gallery, badges, spec table, seller card with
   contact-blur rule, policies accordion, price, stock state placeholder, wishlist heart,
   "Report listing" stub. Category browse pages.
8. `/api/v1/products` index + show (published only).

ACCEPTANCE
- Pest: CB seller cannot create non-car_breaker condition; moderation transitions +
  permissions; media pipeline produces watermark + WebP (fixture image assert); inspection
  flag change requires staff and writes AuditLog; price stored as integer ngwee.
- Vitest: badge components render both badge families from props.
```

---

### PROMPT 04 — Inventory & Stock Freshness (M05)

```text
Read CLAUDE.md. Build app/Modules/Inventory.

DELIVERABLES
1. Stock ledger per variant: quantity, decrement on paid order, restore on cancellation
   (listen to Orders events — define the event contracts now, Orders module fires them in
   Prompt 07). Concurrency-safe (SELECT ... FOR UPDATE or atomic decrement); never negative.
2. Freshness engine: freshness_confirmed_at per product. Scheduled daily job assigns states:
   fresh ≤3d, ageing 4–5d, unconfirmed >5d (adds "Stock unconfirmed" label), hidden >14d
   (thresholds from Settings). State changes fire events consumed by Search (Prompt 05)
   and Notifications (Prompt 12 — queue the notification jobs now against the SmsProvider/
   mail fakes).
3. Confirmation UX: seller dashboard banner + one-tap "All stock accurate" (bulk confirm),
   per-product confirm/update on the product list; reminder schedule at day 3 and day 5
   via in-app + SMS + email.
4. Bulk stock update: downloadable XLSX/CSV template (SKU, quantity, price), upload with
   row-level validation report, queued apply. NO external system sync — Odoo is out of scope.
5. Low-stock (threshold per variant, default 2) and out-of-stock seller alerts.
   Buyer "Notify me when back in stock" subscription (fires when quantity rises from 0).
6. Storefront stock display: In stock / Low stock / Out of stock + freshness label.

ACCEPTANCE
- Pest: freshness state machine across simulated days (time travel); hidden products
  excluded from storefront queries; concurrent order decrements cannot oversell (parallel
  test or lock assertion); bulk upload rejects bad rows with per-row messages and applies
  good rows; back-in-stock fires exactly once per subscriber.
```

---

### PROMPT 05 — Search, Filter & Discovery (M06, §6)

```text
Read CLAUDE.md. Build app/Modules/Search on Laravel Scout + Meilisearch.

DELIVERABLES
1. Index published, non-hidden products with: name, part number, make, model, year,
   category path, condition, inspection flag, oem/aftermarket, price, seller id/name/type,
   verified, seller rating, review count, freshness state, dispute-rate band, province/city,
   _geo (seller GPS). Sync on model events + nightly full re-index command.
2. Quality score computed at index time from Settings weights (rating 30, review count
   log-scaled 10, verified 20, inspected 15, freshness 15, low disputes 10) — recompute
   nightly and on rating/verification/freshness events.
3. Default "Recommended" ranking per §6: tier 1 exact match on all searched tokens/filters,
   tier 2 partial (Meilisearch relevance), tier 3 fallback to category with the notice
   "No exact matches — showing related parts". Within tier: quality score desc, then
   PRICE DESC. Implement tiers via ranking rules + sortable attributes; document the chosen
   Meilisearch configuration in the module README.
4. Alternative sorts: price asc, price desc, nearest first (_geo, only when buyer shares
   or enters a location), newest. Distance NEVER in the default sort.
5. Facet filters: make, model, year, category, condition, inspection, oem, price range,
   seller type, verified only, delivery available, province/city, radius.
6. Storefront search page: instant results, facet sidebar (bottom-sheet on mobile), applied-
   filter chips, sort control always visible, empty-state with tier-3 fallback, pagination.
   "Why am I seeing this?" tooltip on badges.
7. Search analytics: log query, filters, result count, zero-result flag; admin report of
   top zero-result queries (feeds reference-data backlog).
8. `/api/v1/search` with the same parameters.

ACCEPTANCE
- Pest (against a real Meilisearch test container): exact beats partial; within tier higher
  quality first then higher price; unverified/unconfirmed demotion visible in ordering;
  hidden/unpublished absent; radius filter works; zero-result logged.
- Weight change in Settings + recompute changes ordering (test).
```

---

### PROMPT 06 — Wishlist, Cart & Quotations (M07)

```text
Read CLAUDE.md. Build app/Modules/Shopping.

DELIVERABLES
1. Wishlist: prominent heart on every card/listing (emphasised per the request form),
   guest clicks route to login with intended-url return. Wishlist page under buyer profile:
   price-drop and stock-change indicators since added, move-to-cart, remove.
2. Cart: per-buyer persistent cart, multi-seller, grouped by seller in the UI with a note
   that each seller ships separately; quantity edit with live stock re-validation; price
   refresh on view with "price changed" notice; totals per seller group.
3. RFQ (request for quotation): from listing or wishlist — quantity + message to seller.
   Seller portal: RFQ inbox, respond with quoted unit price, validity date, delivery note.
   Buyer accepts → converts to a cart line at the quoted price (quotation_id carried through
   to the order line in Prompt 07); expiry job voids stale quotes.
4. Contact seller: in-platform message thread stub (full messaging in Prompt 12) + reveal
   of phone/email for logged-in buyers only (reuse blur components for guests).
5. Compare drawer: up to 4 listings, spec table diff (client-side, sessionStorage).
6. `/api/v1/wishlist`, `/api/v1/cart`, `/api/v1/quotations`.

ACCEPTANCE
- Pest: wishlist indicators (price drop, out of stock) computed correctly; cart re-validation
  removes/flags dead lines; RFQ lifecycle open→quoted→accepted|expired with authorization
  (only the addressed seller may quote); accepted quote price wins over current price.
```

---

### PROMPT 07 — Checkout, Orders & Fulfilment (M08)

```text
Read CLAUDE.md. Build app/Modules/Orders. Payments are stubbed behind PaymentGateway until
Prompt 09; design the seams now.

DELIVERABLES
1. Checkout flow (Inertia, mobile-first):
   a. Delivery method per seller group: pickup (seller location on embedded Google Map with
      directions link) or delivery to a saved/new address (map pin). Delivery fee: seller-
      defined flat rate in R1 (per-zone and distance-based fees are 1.x — leave the
      strategy interface).
   b. Payment method selection: card | mobile money (channels passed to Lenco later).
   c. TERMS ACCEPTANCE modal (blocking): renders the CURRENT VERSIONS of the seller's
      delivery, refund, warranty, T&C policies + platform terms + the platform minimum
      refund rule. Acceptance stored per order: policy ids+versions, timestamp, IP,
      user agent. TermsAcceptance is append-only.
   d. Place order → OrderGroup (one payment) containing one Order per seller, each with
      OrderItems (variant, qty, unit price ngwee, quotation_id nullable).
2. Order state machine (use a tested transitions class, not scattered ifs):
   pending_payment → paid → seller_confirmed → ready_for_pickup | dispatched →
   collected | delivered → completed → closed. Branches: cancelled, disputed, refunded.
   Snapshot on payment (hooks for Prompt 09): payment_mode, monetisation policy, escrow
   windows. Guards: only the owning seller confirms/dispatches; only the buyer or
   auto-completion completes.
3. Timers (scheduled jobs, Settings-driven): seller must confirm within 24h of paid or
   auto-cancel + refund-request event; auto-complete after collected/delivered
   (pickup 3d / delivery 7d defaults, admin-configurable per Q5).
4. Disputes: buyer opens before completion (reason + photos); order → disputed; blocks
   auto-complete and (later) escrow release; moderator resolution actions release |
   partial_refund(amount) | full_refund — events consumed by Prompt 09. All audited.
5. Documents: buyer receipt/invoice PDF (order details, VAT-inclusive prices, "seller is
   responsible for VAT on goods" note), seller packing slip PDF.
6. Buyer order pages (list, detail with status timeline, confirm receipt, open dispute),
   seller order pages (inbox by status, confirm, mark ready/dispatched, mark collected/
   delivered), admin order search + detail + dispute queue.
7. Events for other modules: OrderPaid, OrderCompleted, OrderCancelled, DisputeOpened,
   DisputeResolved, OrderStateChanged (Inventory already listens; Ratings/Payments will).
8. `/api/v1/orders` buyer endpoints.

ACCEPTANCE
- Pest: every legal and illegal transition; timers via time-travel (auto-cancel at 24h,
  auto-complete at window, dispute blocks auto-complete); terms acceptance recorded with
  exact policy versions even after seller edits policies; multi-seller cart yields one
  OrderGroup + N orders with independent lifecycles; stock restored on cancel.
- Dusk: full checkout happy path with fake gateway.
```

---

### PROMPT 08 — Ledger & Monetisation Policies (M09/M12 foundation)

```text
Read CLAUDE.md. Build app/Modules/Ledger BEFORE touching Lenco, so money logic is testable
offline.

DELIVERABLES
1. Double-entry core: LedgerAccount (seeded chart from CLAUDE.md), JournalEntry (uuid,
   description, polymorphic reference: Payment/Order/Payout/Refund/Adjustment, created_by),
   JournalLine (account, debit|credit, amount ngwee). Invariants enforced in a
   LedgerService::post() API: lines balance to zero, entries append-only, no negative
   amounts, idempotency key per business event. Direct model writes forbidden (guard).
2. Balance queries: per account, per seller payable, escrow held per order, seller reserve
   balance — computed from lines, with a cached materialised balances table refreshed by
   the poster (consistency test between raw sum and cache).
3. MonetisationPolicy (per client answer Q2): named policy with three components —
   commission (percent and/or flat ngwee), addon_fee (flat, optional), referral_fee
   (percent, optional). Global default policy in Settings; admin CRUD + per-seller
   assignment (audited). PolicyCalculator: given an order gross → commission, addon,
   referral, VAT-on-commission (rate from Settings), seller net. Deterministic rounding
   (banker's or half-up — pick one, document, test) and snapshot serialisation stored on
   the order at payment time.
4. Posting recipes (pure ledger, gateway-agnostic) as an OrderPostingService:
   - ESCROW payment: Dr platform_cash / Cr escrow_held(order).
   - ESCROW release (completion): Dr escrow_held / Cr seller_payable(net), commission_revenue,
     addon_revenue, referral_revenue, vat_on_commission_payable.
   - DIRECT payment: Dr platform_cash / Cr seller_payable(net) + revenue/VAT lines, plus
     reserve split: Cr seller_reserve(reserve %) reducing payable.
   - Refund full/partial from escrow or wallet; DIRECT clawback creating negative payable.
   - Payout execution: Dr seller_payable / Cr platform_cash. Lenco fee: Dr lenco_fees_expense.
   - Reserve release schedule job.
5. Wire to Prompt 07 events: OrderPaid posts by snapshotted mode; OrderCompleted releases
   escrow; DisputeResolved posts the chosen outcome. Commission invoice record per seller
   per order (numbering series) — PDF in Prompt 14.
6. Admin: monetisation policy manager, per-seller assignment, ledger browser (filter by
   account/reference), manual adjustment entry (dual-control: finance creates, admin
   approves; both audited).

ACCEPTANCE
- Pest: unbalanced post throws; idempotent double-post ignored; every recipe's lines and
  balances asserted for both modes including reserve and clawback; PolicyCalculator
  property test — for random gross amounts, net + all fees + VAT == gross-side equation
  exactly in ngwee (no lost ngwee); policy snapshot on order unaffected by later edits;
  raw-sum vs cached balance equality after 1,000 random postings.
```

---

### PROMPT 09 — Lenco Payments, Escrow, Payouts & Refunds (M09, §7)

```text
Read CLAUDE.md and https://lenco-api.readme.io/v2.0 (Accept Payments, Collections, Transfers,
Transfer Recipients, Resolve, Settlements, Webhooks). Build app/Modules/Payments implementing
PaymentGateway with LencoGateway. Sandbox base URLs/keys from config; secret key never leaves
the server.

DELIVERABLES
1. Collection flow:
   - Checkout "Pay" renders the Lenco inline widget (pay.sandbox.lenco.co in non-prod) with
     public key, reference MFA-{orderGroupId}-{attempt}, amount (decimal from ngwee),
     currency ZMW, channels [card, mobile-money], customer + billing prefill, bearer from
     Settings (default merchant per Q3).
   - onSuccess → POST to our verify endpoint → server GET /collections/status/:reference →
     on `successful`, mark Payment succeeded, fire OrderPaid (ledger posts via Prompt 08).
     onClose/onConfirmationPending → pending screen with polling.
   - Alternative server-initiated mobile-money collection endpoint (USSD push) for the API.
2. Webhooks: POST /webhooks/lenco — verify signature, enqueue, process idempotently
   (unique lenco event id + our reference). collection.successful is authoritative;
   collection.failed marks failure and releases the cart/order per rules. Store raw payload.
   Poll-status fallback command for stuck pending payments (>15 min).
3. Payment model: reference, attempt, channel, amounts (gross, fee, settled), bearer,
   lenco ids, status, raw payloads. Retry = new attempt suffix, same OrderGroup.
4. Payout engine: aggregate positive seller payables into PayoutBatch (daily schedule +
   on-demand by Finance) → Finance approval screen (dual control) → execute lines via
   POST /transfers/bank-account | /transfers/mobile-money using stored lenco_recipient_id
   (re-resolve account first; block line on mismatch) → track via transfer webhooks +
   GET /transfers/status/:reference → post ledger on confirmation. Partial batch failure
   handling: failed lines revert payable, flagged for Finance.
5. Refunds: from dispute resolutions and auto-cancel. Mobile-money/bank refunds as Lenco
   transfers to the buyer (resolve buyer wallet from payment details); card refunds flagged
   for manual processing per Lenco process with a Finance task queue. Ledger via Prompt 08.
6. DIRECT-mode guardrails: rolling reserve % applied at posting (done in 08); scheduled
   job auto-reverts a seller to ESCROW when dispute rate > Settings threshold, notifying
   admin (audited). Admin screen to set seller payment mode shows the recommended
   eligibility (verified, ≥20 completed orders, <2% disputes, ≥60 days active) with
   override + reason.
7. Buyer-facing: payment pending/success/failure pages, receipts; seller-facing: earnings
   page (payable, reserve, next payout), payout history with statuses.
8. Reconciliation job (nightly): pull /collections, /settlements, /transactions for the day,
   compare against Payment rows and ledger platform_cash movements; write ReconciliationRun
   + exceptions (missing, amount mismatch, orphan). Surfaced in Prompt 14's dashboard.

ACCEPTANCE
- Pest with an HTTP-faked Lenco: verify-by-reference paths (successful, failed, pending);
  webhook signature rejection; duplicate webhook processed once; widget config never
  contains the secret key (assert on rendered props); payout batch happy path + line
  failure reverts payable; auto-revert to ESCROW triggers at threshold; reconciliation
  flags a seeded mismatch.
- One gated end-to-end test against the real Lenco sandbox (env-flagged, excluded from CI)
  using Lenco's documented test cards/accounts, covering collect → verify → payout.
```

---

### PROMPT 10 — Ratings, Reviews & Trust (M10)

```text
Read CLAUDE.md. Build app/Modules/Ratings.

DELIVERABLES
1. Rating model (polymorphic rater/ratee) with source: order or endorsement. Directions:
   Buyer→Seller, Buyer→Mechanic, Seller→Buyer, Seller→Mechanic, Mechanic→Buyer.
   Exactly one rating per direction per completed order/endorsement; only after
   OrderCompleted (verified-purchase label derives from this).
2. Stars 1–5, optional text and photos (media pipeline reuse). Seller may post ONE public
   reply per rating. Buyer ratings (Seller→Buyer, Mechanic→Buyer) are NOT public — visible
   to sellers/mechanics and staff only; enforce in queries, Inertia props and API.
3. Moderation: automatic screen (profanity list, phone/email pattern redaction) →
   auto-publish clean, queue flagged; report button → review queue; staff hide/restore
   with reason (audited).
4. Trust score per seller: weighted aggregate feeding the Search quality score (fire the
   event Prompt 05 listens to) and the admin low-rating review list (sellers/users under
   threshold or with rising dispute rate, with recommended actions).
5. UI: rating prompt on completed orders (buyer + seller sides), review list with photos
   and replies on listing/seller/mechanic pages, aggregate breakdown bars.
6. `/api/v1/ratings` submit + list (public directions only).

ACCEPTANCE
- Pest: cannot rate before completion; uniqueness per direction+order; privacy of
  buyer-directed ratings across web and API; redaction of embedded phone numbers;
  aggregate recompute event fires; reply limited to one.
```

---

### PROMPT 11 — Mechanic Directory & Endorsement (M03)

```text
Read CLAUDE.md. Build app/Modules/Mechanics.

DELIVERABLES
1. Mechanic sign-up (attachable to an existing buyer account): personal details, address,
   qualification, experience, speciality multi-select (seeded controlled list), work
   history, optional references, optional affiliation request to a listed seller.
2. Admin approval queue; profile publicly visible only after approval. Two-badge model:
   "MonaFind approved" (staff) and "Endorsed by <Seller>" (per endorsement). A mechanic
   may hold multiple endorsements; each listed.
3. Endorsement flow: mechanic requests → seller portal notification → endorse | decline;
   revocable by the seller; all audited.
4. Public directory: filter by speciality, province/city, rating; mechanic profile page
   with badges, ratings (from Prompt 10), contact via messaging (Prompt 12) — same
   guest blur rule for any contact details.
5. `/api/v1/mechanics` directory + profile.

ACCEPTANCE
- Pest: unapproved profiles hidden everywhere; endorsement authorization (only the
  addressed seller); revocation removes badge; directory filters correct.
```

---

### PROMPT 12 — Notifications & Messaging (M13)

```text
Read CLAUDE.md. Build app/Modules/Messaging and finish the notification layer the earlier
modules queued against fakes.

DELIVERABLES
1. Notification framework on Laravel Notifications with channels: database (in-app bell +
   unread count), mail, SMS via SmsProvider (ZamtelSmsProvider with sender ID from
   config; LogProvider elsewhere). Admin-editable event→channel matrix (Setting-backed)
   and per-user preference page; OTP/security events always send regardless of preferences.
2. Implement the full event catalogue: OTPs; registration/verification outcomes; listing
   moderation outcomes; new order; order state changes; payment received; payout sent;
   stock-confirmation reminders; RFQ received/answered; new message; new review; dispute
   opened/updated/resolved. Queued with retry/backoff on the notifications queue.
3. Messaging: MessageThread polymorphically attached to a listing, RFQ or order;
   participants buyer+seller (or buyer+mechanic); text + attachments (media rules);
   unread tracking; same automatic redaction screen as reviews before a thread's order is
   paid (anti-disintermediation is NOT required — redaction only pre-payment per the
   contact-visibility rule); staff can view threads on a reported dispute.
4. UI: bell dropdown + notifications page (all areas), thread UI on listing/RFQ/order pages,
   seller/mechanic inboxes.
5. `/api/v1/notifications`, `/api/v1/threads`.

ACCEPTANCE
- Pest: matrix routing (event X goes to configured channels only); preference opt-out
  respected except security events; SMS provider fake captures payload + sender ID;
  thread authorization (non-participants blocked, staff only via dispute); unread counts.
```

---

### PROMPT 13 — Admin Console & Moderation consolidation (M11)

```text
Read CLAUDE.md. Consolidate app/Modules/Admin: earlier prompts created queues; unify them.

DELIVERABLES
1. Admin home dashboard with live counters + links: pending seller verifications, pending
   listings, pending mechanics, open disputes, orders awaiting seller confirmation,
   stale-stock sellers, flagged reviews, reconciliation exceptions, payout batches awaiting
   approval.
2. Global configuration UI over Settings with validation and audit: commission/monetisation
   defaults, escrow auto-release windows (Q5: admin-configurable), freshness thresholds,
   ranking weights, reserve %, dispute threshold, seller confirm window, fee bearer,
   platform minimum refund text.
3. Reference-data managers (makes, models, categories, specialities, provinces/cities)
   with merge tooling for duplicates.
4. Content: CMS-lite pages (About, FAQ, Contact, Platform Terms, Privacy) with versioning
   (platform terms version feeds checkout acceptance), announcement banner scheduler.
5. Staff management: invite staff, assign moderator/finance/admin roles, enforce 2FA,
   deactivate. Permission matrix documented in the module README.
6. Cross-cutting admin UX: every destructive or financial action requires a reason field;
   AuditLog viewer with filters (actor, subject, date) and CSV export.

ACCEPTANCE
- Pest: settings validation ranges; platform-terms version bump reflected in next checkout
  acceptance; role separation (moderator cannot reach finance screens and vice versa —
  route + policy tests); audit viewer filters.
```

---

### PROMPT 14 — Finance Dashboard, Statements & Reconciliation (M12)

```text
Read CLAUDE.md. Build app/Modules/Finance on top of the ledger.

DELIVERABLES
1. Finance dashboard: GMV, order count, commission/addon/referral revenue, VAT on
   commission, escrow balance, payables outstanding, reserve held, refunds, Lenco fees,
   net revenue — by day/week/month, by seller type, category, province. Charts (lightweight,
   e.g. chart.js via vue-chartjs) + tables, all figures sourced from ledger queries, never
   recomputed from orders.
2. Seller monthly statements: sales, commissions and fees by monetisation component,
   refunds, payouts, closing payable/reserve — PDF + CSV, generated monthly by job,
   downloadable in the seller portal. Commission invoices (from Prompt 08 records) as
   numbered PDFs: MonaFind invoices COMMISSION ONLY, VAT on commission itemised; body notes
   product prices are VAT-inclusive and goods VAT is the seller's responsibility (Q4).
3. Payout batch approval screen (Finance role) with line detail, resolve-check status,
   approve/reject; execution status live view.
4. Reconciliation centre: runs list, exception queue with resolve/annotate actions
   (audited), daily balance check card: ledger platform_cash vs Lenco account balance
   (GET /accounts/:id/balance).
5. Exports: orders, payments, ledger journal, payouts as CSV/XLSX (no external
   accounting push — Odoo removed; exports are the hand-off to the client's accountant).
6. Tax config: VAT rate on commission with effective-date versioning; changing the rate
   affects only new orders (snapshot proves it).

ACCEPTANCE
- Pest: dashboard figures equal direct ledger sums for a seeded scenario covering both
  payment modes, a refund, a clawback and a payout; statement totals reconcile to ledger;
  invoice numbering gapless per series; VAT rate change does not alter existing snapshots.
```

---

### PROMPT 15 — Hardening, Security, API & Launch (M15, §10)

```text
Read CLAUDE.md. Final pass before pilot.

DELIVERABLES
1. Security: rate limiting on auth/checkout/webhooks; CAPTCHA (e.g. Turnstile) on public
   forms; CSP + security headers; signed URLs for private media; dependency audit in CI;
   verify payout-account encryption, secret handling, and that no card data ever touches
   our servers (Lenco widget only). Produce a short OWASP ASVS L2 self-assessment
   checklist in docs/ with pass/fail and remediation notes.
2. Performance: SSR + cache headers on storefront; image lazy-loading and responsive
   sources; query audit (n+1 detector in CI via beyondcode/laravel-query-detector or
   similar); k6 or artillery script proving search p95 <300ms and checkout API p95 <500ms
   on seeded data (50 sellers / 5,000 listings); document results.
3. Data protection (Zambia Data Protection Act 2021): consent capture at registration,
   privacy policy page wiring, data-subject export (user's data as JSON/PDF) and account
   deletion/anonymisation flow that preserves financial records (ledger, orders,
   TermsAcceptance) while stripping PII; retention schedule doc.
4. Reliability: backup strategy doc + restore drill script; Horizon monitoring + alerting
   on failed jobs; payment-failure and reconciliation-exception alerts to admin email;
   uptime/health endpoints (/up + deep health: db, redis, meilisearch, lenco sandbox ping).
5. API completeness for mobile: audit /api/v1 coverage of every buyer and seller journey,
   fill gaps, publish OpenAPI spec artifact, add Postman/Bruno collection in docs/.
6. Launch checklist in docs/LAUNCH.md: Lenco live keys rotation, webhook URL registration,
   Google Maps key restrictions, Zamtel Sms sender ID, DNS/SSL, seeding real
   reference data, admin accounts with 2FA, go/no-go criteria.
7. Accessibility pass on storefront (WCAG 2.1 AA): keyboard nav, contrast, labels; axe CI
   check on key pages.

ACCEPTANCE
- CI green including new security/quality gates; load-test report and ASVS checklist
  committed; deletion flow test proves PII gone but ledger intact; axe passes on home,
  search, listing, checkout pages.
```

---

## Traceability

| Concept module        | Prompt(s)                      | Notes                                                                    |
| --------------------- | ------------------------------ | ------------------------------------------------------------------------ |
| M01 Identity & Access | 01                             |                                                                          |
| M02 Seller Onboarding | 02                             |                                                                          |
| M03 Mechanics         | 11                             |                                                                          |
| M04 Catalogue         | 03                             | Q11: dual badges mandatory on cards                                      |
| M05 Inventory         | 04                             | Odoo sync removed; CSV/XLSX only                                         |
| M06 Search            | 05                             | §6 ranking spec                                                          |
| M07 Wishlist/Cart/RFQ | 06                             |                                                                          |
| M08 Checkout/Orders   | 07                             | Q5 windows configurable                                                  |
| M09 Payments/Escrow   | 08 + 09                        | Ledger split out first                                                   |
| M10 Ratings           | 10                             |                                                                          |
| M11 Admin             | 13 (+ queues built throughout) |                                                                          |
| M12 Finance           | 08 + 14                        | Q2 monetisation engine now R1; Q4 VAT model; Odoo journal push → exports |
| M13 Notifications     | 12 (fakes from 00)             | Q7 Zamtel Sms                                                            |
| ~~M14 Odoo~~          | —                              | **Removed in V3**                                                        |
| M15 Platform          | 00 + 15                        |                                                                          |
