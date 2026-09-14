---
paths:
  - 'app/Modules/Finance/**'
---

# Finance

## Finance reads the ledger and writes only the VAT schedule
Every dashboard, statement and export figure is a sum of `journal_lines` — never a total taken from orders, payments or payout_lines. Those three can disagree with what actually moved; the ledger cannot. `FinanceMetrics` is the only reader, and its tests assert each figure against a direct ledger sum.

Flows (GMV, commission, refunds) are bounded by the window; positions (escrow held, payables, reserve) are cumulative to the end of it. Two queries, deliberately — "escrow held in September" is not a question.

Dimensions hang off `journal_entries.reference` → Order, because revenue accounts are platform-wide and carry no subject. Seller type and province group exactly; category is APPORTIONED per order by item value with `Money::allocate()`, so rows still foot to the headline figures.

`posted_at` is UTC and readers are in Lusaka. Bucket by day/week/month through `FinanceMetrics::localDateExpression()`, which shifts by a fixed offset (Zambia has no DST) and is bound, not interpolated, so it stays a literal-string for `selectRaw` — see the grouping rule below.

Finance is downstream of every module and may read any of their services; nothing may depend on Finance. That is why `PlatformCashCheck` lives in Payments (it reads the gateway) and Finance merely renders it.

## Group the local-date bucket by its alias, never by a second copy of the expression
`FinanceMetrics::localDateExpression()` binds its offset as `?`. Laravel runs real prepared statements (`ATTR_EMULATE_PREPARES => false`), so MySQL sees a placeholder rather than a number, and under ONLY_FULL_GROUP_BY it cannot prove that two expressions each holding a separate `?` are the same one — the query dies with "'jl.posted_at' isn't in GROUP BY". Select the expression once as `local_date` and `groupBy('local_date')`; MySQL, SQLite and PostgreSQL all resolve the output name.

The suite runs on SQLite, which matches the duplicated expression happily, so this class of break passes tests and 500s in the browser. Any raw expression that is both selected and grouped needs the alias treatment.

Windows are Lusaka dates: `MetricWindow::fromStrings()` parses its strings in the display timezone, so a test passing `now()->toDateString()` (UTC) builds a window a whole day short between 22:00 and 24:00 UTC. Build test dates with `CarbonImmutable::now(config('monafind.display_timezone'))`.
