# `/api/v1` coverage audit

**Audited:** 13 September 2026, at milestone M15.
**Purpose:** the mobile app consumes this API. Any buyer or seller journey the web can
complete and the API cannot is a journey the app cannot ship.

**Result: 84 endpoints. Every buyer journey is complete. The seller portal is partial and
the gap is itemised below.**

## How this was audited

`php artisan route:list` for the `web`, `seller` and `api/v1` groups, compared route by
route. A web route counts as covered when the API can reach the same outcome, not when it
has a similarly-named endpoint — `POST /messages/{thread}/reply` and
`POST /api/v1/threads/{thread}/messages` are the same capability under different names
and are counted as covered.

## What the audit found, and what was fixed

Nine gaps. Eight are now closed; the ninth is the seller portal and is scoped below.

### The blocking one

**The mobile app could not complete a registration.**

`POST /api/v1/auth/register` created an account in `Pending`, and an account becomes
`Active` only when its phone number is proven. There was no endpoint anywhere under
`/api/v1` that could prove one. A client could create an account and then had nowhere to
go — and no way to notice, because registration returned 201 and a working token.

Closed by `App\Modules\Identity\Http\Controllers\Api\PhoneVerificationController`:

| Method | Endpoint | What it does |
|---|---|---|
| GET | `/api/v1/phone` | Whether the number is verified, the masked number, resend countdown |
| POST | `/api/v1/phone` | Set or correct the number (a social sign-in arrives without one) |
| POST | `/api/v1/phone/resend` | Send another code |
| POST | `/api/v1/phone/verify` | Check the code; activates the account |

Proven end to end by `tests/Feature/Api/MobileJourneyTest.php` — register, receive,
verify, active.

### The other seven

| Gap | Why it mattered | Closed by |
|---|---|---|
| No password reset | A person who forgot their password had to open a browser | `POST /api/v1/auth/forgot-password`, `POST /api/v1/auth/reset-password` |
| No content pages | Registration records consent against the **current** version of the terms and privacy notice. An app that cannot fetch them asks people to agree to something it cannot show them | `GET /api/v1/pages`, `GET /api/v1/pages/{slug}` |
| No category browsing | A buyer who does not know what the part is called browses to it, and search cannot help them | `GET /api/v1/categories`, `GET /api/v1/categories/{slug}` |
| No "empty cart" | An app could remove lines one at a time or offer no way to start over | `DELETE /api/v1/cart` |
| No receipt | A buyer could not get proof of purchase | `GET /api/v1/orders/{order}/receipt` (PDF) |
| No "contact seller" | The most common thing a buyer does short of buying | `POST /api/v1/sellers/{seller}/enquiries` |
| No review reporting | An app could show somebody a review calling them a thief and offer no way to object | `POST /api/v1/ratings/{rating}/report` |
| No profile editing | Name, email, phone and password could not be changed from the app | `GET`/`PATCH /api/v1/profile`, `PUT /api/v1/profile/password` |

## Buyer journeys — complete

| Journey | Endpoints | Status |
|---|---|---|
| Register and activate | `auth/register` → `phone` → `phone/verify` | ✅ |
| Sign in and out | `auth/login`, `auth/logout`, `auth/me` | ✅ |
| Recover an account | `auth/forgot-password`, `auth/reset-password` | ✅ |
| Read the terms and privacy notice | `pages`, `pages/{slug}` | ✅ |
| Search | `search` | ✅ |
| Browse by category | `categories`, `categories/{slug}` | ✅ |
| Browse listings and shops | `products`, `products/{id}`, `sellers`, `sellers/{id}` | ✅ |
| Wishlist | `wishlist` ×4 | ✅ |
| Cart | `cart` ×5 including clear | ✅ |
| Ask for a price | `quotations` ×5 | ✅ |
| Contact a seller | `sellers/{seller}/enquiries` | ✅ |
| Check out | `checkout`, `orders` (POST) | ✅ |
| Pay | `payment-intent`, `pay/mobile-money`, `payment-status` | ✅ |
| Track and receive an order | `orders`, `orders/{order}`, `confirm-receipt`, `receipt` | ✅ |
| Raise a dispute | `orders/{order}/disputes` | ✅ |
| Review, and report a review | `orders/{order}/ratings`, `ratings`, `ratings/{rating}/report` | ✅ |
| Message a seller | `threads` ×5 | ✅ |
| Notifications and preferences | `notifications` ×5 | ✅ |
| Addresses | `addresses` ×5 | ✅ |
| Stock alerts | `stock-alerts` ×2 | ✅ |
| Find a mechanic | `mechanics` ×3 | ✅ |
| Manage the account | `profile` ×3, `account` | ✅ |
| Exercise data-protection rights | `privacy` ×5 | ✅ |

## Seller journeys — partial, and deliberately so

| Journey | API | Status |
|---|---|---|
| Confirm stock | `seller/stock` ×3 | ✅ |
| Answer a quotation | `quotations/{q}/respond`, `/decline` | ✅ |
| Read messages | `threads` ×5 | ✅ |
| Reply to a review | — | ❌ |
| Manage listings (CRUD, photos, submit, unpublish) | — | ❌ |
| Seller order queue and its transitions | — | ❌ |
| Earnings and statements | — | ❌ |
| Payout accounts | — | ❌ |
| Policies and fulfilment settings | — | ❌ |
| Documents and verification | — | ❌ |
| Endorsements | — | ❌ |

### Why this is a scope decision rather than an omission

The first release of the mobile app is a **buyer** app. A seller runs a shop from a
counter with a laptop or a tablet browser: uploading ten photographs of a gearbox,
filling in a fitment table and reconciling a payout statement are not phone-shaped
tasks, and building them twice before anybody has asked is how a second surface starts
rotting.

The two seller capabilities that **are** phone-shaped are already here, and they are the
two that are time-sensitive: confirming stock — which the freshness rules demand every
three to five days and which the whole module is designed around being one tap — and
answering a quotation while the buyer is still deciding.

The order queue is the strongest candidate for the next increment: a seller wants to
know an order has arrived while they are away from the counter. It needs seven endpoints
(index, show, confirm, ready, handed-over, cancel, packing slip) and is tracked as a
post-pilot item.

## Conventions the API keeps

- **One envelope.** Every response is `ApiResponse::ok()/created()/paginated()/error()`.
  Success is `{"data": …, "meta": {…}}`, failure is
  `{"error": {"code", "message", "details"}}`. The one deliberate exception is the
  receipt, which is a PDF — wrapping the bytes in JSON would stop it being a document
  somebody can save or hand over a counter.
- **One rulebook.** Every endpoint calls the same service the web controller calls. A
  rule enforced in one is enforced in the other, because there is only one of it.
- **Ids, not slugs.** The app holds ids. Orders are the exception and are bound by
  number, because that is what the buyer reads off their receipt.
- **Rate limited by journey.** `checkout`, `payments`, `search`, `register`, `uploads`
  and `webhooks` are named limiters, counted per account where there is one.

## Regenerating this audit

```bash
php artisan route:list --path=api/v1     # 84 endpoints
php artisan route:list --except-vendor   # compare against web and seller
composer run openapi                     # storage/api/openapi.json
```

The OpenAPI document is generated by Scramble from the controllers and Form Requests and
is uploaded as a CI artifact on every build. A Bruno collection for driving the API by
hand lives in `docs/bruno/`.
