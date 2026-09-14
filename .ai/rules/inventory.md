---
paths:
  - 'app/Modules/Inventory/**'
---

# Inventory

## Inventory: one writer for quantities, one writer for freshness
`StockLedger` is the ONLY thing that writes `product_variants.quantity`. It re-reads the row under `lockForUpdate()` inside the transaction that writes it, then appends a `stock_movements` row — so a `$variant->quantity = x; save()` anywhere else silently opts out of the oversell lock, the ledger and the low-stock/back-in-stock reactions. `decrement()` throws `InsufficientStock` (the reservation path); `applyOrderPaid()` clamps at zero and audits `stock.oversold`, because the money has already arrived and refusing would leave the platform claiming stock it just sold.

Idempotency lives in the database, not in a flag: `stock_movements` is unique on (order_id, product_variant_id, reason), so a webhook or retried listener that fires `OrderPaid` twice changes nothing. `applyOrderCancelled()` restores what the sale actually took (read back off the OrderPaid movements), not what the event says was ordered.

`FreshnessService` is the only writer of `products.freshness_state` / `freshness_confirmed_at`. States are assigned by the daily `EvaluateStockFreshness` sweep, never derived in a query — the sweep is what fires `ProductFreshnessChanged` when a listing crosses a boundary. Day thresholds come from `settings('freshness.*')`; the day-N reminder cutoff is `startOfDay()->subDays($day - 1)`, not `subDays($day)`, or every reminder slips a day late.

`stock_movements` is append-only in both layers (AppendOnly trait + AppendOnlyTable triggers). No new dependency was added for XLSX: `Support/Spreadsheet/{SpreadsheetWriter,SpreadsheetReader}` build and parse it with ZipArchive + SimpleXML.
