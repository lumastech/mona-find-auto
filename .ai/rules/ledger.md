---
paths:
  - 'app/Modules/Ledger/**'
---

# Ledger

## Ledger: one writer, keys name events, balances are checkable
`LedgerService::post()` is the ONLY thing that may create a `JournalEntry` or `JournalLine`. `PostingGuard` makes the models refuse creation outside a posting, because an entry inserted straight through Eloquent skips the balance check, skips its idempotency key and never reaches `ledger_balances` — and being append-only it can never be corrected. This is why neither model has a factory; tests build entries the way production does.

Every `Posting` carries an idempotency key naming the BUSINESS EVENT ("escrow-release:order:4012"), not the moment it ran. The unique index on `journal_entries.idempotency_key` is what makes a twice-delivered webhook, a retried job and a double click post once — the database loses that race safely, a select-then-insert does not. Refunds take the key from their caller, because only the caller knows what the event was.

`ledger_balances` is a cache. `LedgerBalances::recompute()` sums the lines and is the truth; `discrepancies()` compares the two and should always be empty (surfaced on the ledger browser). Two rows per line: the subject's and the account-wide one. `balance_ngwee` is SIGNED against the account's normal balance, so a negative seller payable means the seller owes the platform after a clawback.

`PolicyCalculator` never rounds the seller's net — it is defined as gross minus the platform's take, so no ngwee is lost. Fees are charged against the GOODS, never the delivery fee. Rounding is half-up so a seller can re-derive a VAT invoice by hand. Refund figures are derived by DIFFERENCE (take on the retained value vs the value before), never by scaling a percentage twice.

Recipes read `orders.payment_mode` and `orders.monetisation_snapshot` — never the seller's live mode or policy. Ledger depends on Orders and Sellers models; nothing in those modules may depend back on Ledger (that is why the seller→policy assignment is read as a plain column, not a relation).
