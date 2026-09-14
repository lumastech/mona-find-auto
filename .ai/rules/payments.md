---
paths:
  - 'app/Modules/Payments/**'
---

# Payments

## Payments: the gateway decides, the unique index deduplicates, money out is never retried
A payment is settled because the SERVER asked Lenco and Lenco said yes. The widget's `onSuccess` and a signed webhook body are both only prompts to go and check — `CollectionService` re-reads the collection and ignores any status or amount a caller supplies. The verify endpoint deliberately accepts no input beyond the order group.

`payments` is an append-only LOG, one row per observed state of an attempt, not one mutable row per payment. CLAUDE.md makes Payment append-only, which rules out marching a row from pending to successful. Read the current state with `Payment::currentFor()`; ask "was it ever paid" with `Payment::isSettled()` (exists-a-successful-row, so a late `failed` webhook cannot unpay a paid order). Never update a Payment — `PaymentRecorder::record()` is the only writer.

The unique index on `(reference, status)` is what makes the browser verify call, the webhook and the poller race safely. `record()` returns NULL when the observation already existed, and every caller reads that as "somebody else got here first, do nothing" — that is what stops an order settling twice. Do not replace it with firstOrCreate/select-then-insert: two workers can both pass an existence check, only one can win an insert. `lenco_webhook_events.lenco_event_id` does the same job for redeliveries.

Money OUT is never retried automatically. `ExecutePayoutLine` and `SendRefund` are the only jobs on the platform with `$tries = 1`, because a transfer that timed out may have moved money. A gateway error becomes PayoutLineStatus::Unresolved (or RefundStatus::Manual), which explicitly does NOT revert the seller's payable — only `Failed`/`Blocked` do. Reverting a payable for money that really left pays the seller twice on the next run.

Payout ledger entries are posted on CONFIRMATION, never on send, so a failed line needs no reversal — nothing was posted and the payable was never reduced. Payout batches are dual control: `prepared_by` may never equal `approved_by` (enforced in both PayoutService and PayoutBatchPolicy). Lines re-resolve the destination account at the gateway before sending and block on a name mismatch (compared loosely — case/punctuation ignored).

Refunds have two owners and must not both post: a DISPUTE refund is posted by Ledger's `PostDisputeResolution`, so `RefundService::createForDispute()` moves cash and posts nothing; cancellations/admin refunds go through `createForOrder()`, which posts itself. Card refunds cannot be executed by API at all — they are created `CardManual` for the Finance queue. Refund destinations come off the original Payment's raw payload, never the buyer's current profile.

`WidgetConfigurator` is the ONLY place that builds browser-facing payment props and reads only `lenco.public_key`. Keep it that way so "the secret key never leaves the server" stays one small class with one test pointed at it.
