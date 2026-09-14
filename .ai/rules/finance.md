---
paths:
  - 'app/Modules/Finance/**'
---

# Finance

## Finance reads the ledger and writes only the VAT schedule
Every dashboard, statement and export figure is a sum of `journal_lines` — never a total taken from orders, payments or payout_lines. Those three can disagree with what actually moved; the ledger cannot. `FinanceMetrics` is the only reader, and its tests assert each figure against a direct ledger sum.

Flows (GMV, commission, refunds) are bounded by the window; positions (escrow held, payables, reserve) are cumulative to the end of it. Two queries, deliberately — "escrow held in September" is not a question.

Dimensions hang off `journal_entries.reference` → Order, because revenue accounts are platform-wide and carry no subject. Seller type and province group exactly; category is APPORTIONED per order by item value with `Money::allocate()`, so rows still foot to the headline figures.

`posted_at` is UTC and readers are in Lusaka. Bucket by day/week/month through `FinanceMetrics::localDateExpression()`, which shifts by a fixed offset (Zambia has no DST) and is bound, not interpolated, so it stays a literal-string for `selectRaw`/`groupByRaw`.

Finance is downstream of every module and may read any of their services; nothing may depend on Finance. That is why `PlatformCashCheck` lives in Payments (it reads the gateway) and Finance merely renders it.
