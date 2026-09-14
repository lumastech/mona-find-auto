# Data retention schedule

**Controller:** MonaFind Auto Limited, Lusaka, Zambia.
**Legislation:** Data Protection Act No. 3 of 2021 (Zambia).
**Contact for data-subject requests:** the address in `settings('privacy.controller_contact')`.
**Last reviewed:** 13 September 2026.

## The shape of this document

Every table that holds personal data appears below with a lawful basis, a retention
period and what happens on erasure. If a column holding personal data is not in this
table, the schedule is wrong and should be corrected rather than the data quietly kept.

The periods here are not decoration. `privacy.financial_retention_years` and
`privacy.search_history_days` are settings the application actually reads, and
`privacy.erasure_grace_days` decides how long a deletion request waits. **Changing one
of those settings without changing this document makes the published privacy notice
untrue.**

## The one decision everything else follows from

**An erased account's `users` row is anonymised in place. It is never deleted.**

This is forced by the schema rather than chosen for convenience. Almost every table
that references `users` does so with `cascadeOnDelete` — `orders` and
`terms_acceptances` among them. Deleting the row would take the platform's financial
and legal records with it, and the append-only triggers on `terms_acceptances` would
abort the statement part-way through, leaving a failure whose cause is three joins from
its symptom.

So the row survives holding tombstones from `App\Modules\Privacy\Support\Anonymiser`,
with its id intact. Every order, journal line, payment and audit entry still points at a
real account; none of them points at a person. The Act asks that the data subject become
unidentifiable, not that referential integrity be destroyed, and those are different
things.

## Retention by table

### Identity and account

| Table | What it holds | Lawful basis | Retention | On erasure |
|---|---|---|---|---|
| `users` | Name, email, phone, address, password hash, 2FA secrets | Contract (art. 12(1)(b)) | Life of the account | **Anonymised in place.** Name, email, phone, address, password, 2FA secrets and verification stamps are all replaced or nulled. The id survives |
| `user_addresses` | Delivery addresses, recipient names and numbers | Contract | Life of the account | **Deleted** |
| `phone_verifications` | Hashed OTPs, request IP | Legitimate interest — account security | 90 days after use | **Deleted** |
| `social_accounts` | Provider id, encrypted OAuth tokens | Consent | Life of the link | **Deleted** |
| `personal_access_tokens` | Hashed API tokens | Contract | Until revoked | **Deleted** |
| `passkeys` | Public-key credentials, device names | Contract | Until removed | **Deleted** |
| `sessions` | Session payload, IP, user agent | Contract | `SESSION_LIFETIME` (120 min) | **Deleted** — erasure closes the account, which revokes every session |
| `notifications` | Delivered notification bodies | Contract | 12 months | **Deleted** |

### Consent and data-subject rights

| Table | What it holds | Lawful basis | Retention | On erasure |
|---|---|---|---|---|
| `consent_records` | Every grant and withdrawal, with document version, IP and user agent | **Legal obligation** — the Act puts the burden of proving consent on the controller | 7 years after the account closes | **Kept.** Append-only in both layers; there is nothing to strip. It is the evidence that consent was properly obtained |
| `erasure_requests` | The request, its status and a per-table report of what was removed | **Legal obligation** — demonstrating the erasure was carried out | 7 years | **Kept.** It is the record of the erasure and must outlive it |

### Commerce

| Table | What it holds | Lawful basis | Retention | On erasure |
|---|---|---|---|---|
| `orders` | Totals, timestamps, delivery address snapshot, delivery instructions | **Legal obligation** — Zambian tax and company law | `privacy.financial_retention_years` (10) | **Kept, stripped.** `delivery_address`, `delivery_instructions` and `user_address_id` are nulled. Amounts, timestamps and the order number are untouched |
| `order_items` | Part name, variant, quantity, price | Legal obligation | 10 years | **Kept** — no personal data; it describes a part, not a person |
| `order_groups` | Totals and payment status | Legal obligation | 10 years | **Kept** — no personal data |
| `order_status_events` | Transitions and actor | Legal obligation | 10 years | **Kept.** Append-only |
| `order_disputes` | Buyer's narrative, resolution, refund amount | Legal claims (art. 12(1)(f)) | 10 years | **Kept, stripped.** `details` becomes `[redacted]`; the outcome and refund amount stay — they are part of the money trail |
| `terms_acceptances` | Which policy versions were accepted, IP, user agent | **Legal claims** | 10 years | **Kept in full, IP address included.** Append-only in both layers. This is the table that settles a dispute brought years later, and the IP is part of what makes it evidence rather than an assertion |
| `carts`, `cart_items` | What is in a cart | Contract | 90 days after last activity | **Deleted** |
| `wishlist_items` | Saved listings and the price at save | Legitimate interest | Life of the account | **Deleted** |
| `quotations` | The buyer's message and the seller's price | Contract | 24 months | **Deleted** |
| `seller_enquiries` | Free text a buyer wrote to a shop | Contract | 24 months | **Deleted** |

### Money

| Table | What it holds | Lawful basis | Retention | On erasure |
|---|---|---|---|---|
| `payments` | Reference, amount, channel, gateway id | **Legal obligation** | 10 years | **Kept.** Append-only. No card data is ever stored — see below |
| `journal_entries`, `journal_lines` | The double-entry ledger | **Legal obligation** | 10 years | **Kept.** Append-only |
| `refunds` | Amount, reason, destination | Legal obligation | 10 years | **Kept** |
| `payout_batches`, `payout_lines` | Seller payouts | Legal obligation | 10 years | **Kept** |
| `payout_accounts` | Bank and mobile-money details | Contract | Life of the shop + 10 years | **Kept, encrypted.** Every field that could redirect a payout is encrypted at rest. A seller's account closure is a separate process from a buyer's — see the gap noted at the end |
| `lenco_webhook_events` | Raw gateway payloads | Legal obligation — reconciliation evidence | 24 months | **Kept** |
| `reconciliation_runs`, `reconciliation_exceptions` | Daily gateway-versus-ledger comparison | Legal obligation | 10 years | **Kept** |

### Content people wrote

| Table | What it holds | Lawful basis | Retention | On erasure |
|---|---|---|---|---|
| `ratings` | Star, body, seller reply | Legitimate interest — buyers rely on reviews | Life of the listing + 24 months | **Kept, stripped.** The body becomes `[redacted]`; **the star stays.** It is already in the seller's public average and in the trust score that ranks their listings, and removing it would make erasure a way to launder a bad review away. Reviews *about* the erased person are not touched at all — they are the seller's words about their own trading experience |
| `rating_reports` | A complaint about a review | Legitimate interest | 24 months | **Deleted** |
| `messages` | Thread bodies | Contract | 24 months after the thread closes | **Kept, redacted.** The body becomes `[redacted]` and the author becomes an erased account. The row survives because the thread is **shared**: deleting one side's messages would rewrite a seller's record of an exchange they were party to, leaving replies answering questions that no longer appear to have been asked. That is not erasure, it is falsifying somebody else's records |
| `message_thread_participants` | Who is in a thread | Contract | With the thread | **Deleted** — removes the thread from the erased account's inbox |
| `notification_preferences` | Channel choices | Consent | Life of the account | **Deleted** |

### Sellers and mechanics

| Table | What it holds | Lawful basis | Retention | On erasure |
|---|---|---|---|---|
| `sellers` | Business name, address, contact person, phone, email | Contract | Life of the shop + 10 years (tax) | See the gap below |
| Seller documents (media) | NRC numbers, company records, addresses | Legal obligation — KYC | 10 years after the shop closes | Private disk, no URL, streamed only after a policy check, every read audited |
| `mechanic_profiles` and children | Name, bio, qualifications, address, phone, **and referees' contact details** | Consent | Life of the profile | **Deleted entirely**, work history and referees with it. A profile is a public advertisement, not a record of a transaction, so there is no basis for keeping one for somebody who has left. The referees never consented to anything themselves, which makes their details the most important thing here to remove |
| `mechanic_endorsements` | A shop's endorsement of a mechanic | Consent | With the profile | **Deleted** — an endorsement is a statement about a specific profile and means nothing once that profile does not exist |

### Operational

| Table | What it holds | Lawful basis | Retention | On erasure |
|---|---|---|---|---|
| `audit_logs` | Actor, action, subject, before/after, reason | **Legal obligation** — accountability | 10 years | **Kept.** Append-only in both layers. The actor id points at an anonymised account |
| `search_queries` | Query text, filters, result count | Legitimate interest | `privacy.search_history_days` (90) before the account link is dropped | **Detached, not deleted.** `user_id` becomes null; the text stays. "toyota hilux brake pads" is not personal data once it belongs to nobody, and these rows are what tell MonaFind which parts buyers ask for and never find. Deleting them would quietly distort the zero-results report that decides what the platform tries to stock |
| `stock_movements` | Shelf changes and the staff or seller who made them | Legitimate interest | 10 years | **Kept.** Append-only; it references an actor, not a buyer |
| `back_in_stock_subscriptions` | A standing request to be contacted | Consent | Until notified, or 12 months | **Deleted.** The whole point of the row is that it produces a message later, so an erased account must not keep one |
| `activity_log` (spatie) | Non-financial change history | Legitimate interest | 24 months | Rows naming the erased account point at an anonymised id |

## What is never stored at all

- **Card numbers, CVVs and expiry dates.** Collection goes through the Lenco inline
  widget, which posts to Lenco's own origin. There is no card field anywhere in
  `resources/js` and no card column in any migration. This is checked as part of the
  ASVS assessment.
- **Plaintext passwords or OTPs.** Both are hashed.
- **Precise location history.** Coordinates are stored per saved address, not per visit.

## Data-subject rights, and where each one is exercised

| Right | Article | Where |
|---|---|---|
| Access | 24 | `/settings/privacy` → JSON or PDF, and `GET /api/v1/privacy/export` |
| Portability | 30 | The same JSON export — structured, commonly used, machine-readable |
| Rectification | 25 | `/settings/profile`, `PATCH /api/v1/profile` |
| Erasure | 26 | `/settings/privacy` → delete account. A configurable grace period, then erasure |
| Objection / withdrawal of consent | 27 | `/settings/privacy` → the marketing switch. Required consents cannot be withdrawn separately; the page says so and offers deletion instead |
| Complaint to the Data Protection Commissioner | 36 | Published on the privacy notice |

## When an erasure waits

An erasure does not run while the account is part-way through something. `ErasureGuard`
asks every module, and Orders answers when there is an order in flight or an open
dispute. The Act permits processing that is necessary to perform a contract, and an
order awaiting collection is exactly that: the seller is holding a part, the money is in
escrow, and nobody is left to confirm receipt.

The hold is temporary and self-clearing. The person is told what is holding it up, in a
sentence they can act on — `/settings/privacy` shows it **before** they press the
button, not in an email a fortnight later.

## Gaps, stated plainly

1. **Seller account erasure is not implemented.** A seller is a business with KYC
   documents, payout accounts and a ten-year tax obligation, and erasing one is a
   different process from erasing a buyer — it has to close the shop, settle outstanding
   payouts and preserve the company records. The erasure flow today covers buyers,
   mechanics and staff. A seller who asks is handled manually and the request is
   recorded. **Owner: Sellers. Target: before public launch.**
2. **Retention periods are not yet enforced by a sweep.** Everything above is
   implemented for erasure-on-request; the time-based expiries (90-day carts, 24-month
   quotations, 90-day search history) are documented and not yet swept. `ProcessDueErasures`
   is the shape the sweep should take. **Owner: Privacy. Target: first post-pilot release.**
3. **No formal DPIA.** The Act requires one for large-scale processing. The pilot is
   invite-only and below that threshold; a DPIA is on the launch checklist for public
   launch.

## Verifying this document

```bash
vendor/bin/pest tests/Feature/Privacy/AccountErasureTest.php
```

That file asserts, row by row, what this schedule promises: the account row anonymised
and not deleted, the ledger whole and balanced, `terms_acceptances` byte-for-byte intact,
the delivery address gone, the message redacted rather than removed, the review's star
kept and its words gone, and the search history detached rather than destroyed.
