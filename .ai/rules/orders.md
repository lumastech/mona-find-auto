---
paths:
  - 'app/Modules/Orders/**'
---

# Orders

## Orders: one writer for status, terms frozen at payment, deadlines stored
`OrderStateMachine` is the ONLY thing that writes `orders.status`. It re-reads the row under `lockForUpdate()`, checks `OrderStatus::allowedTransitions()` AND `OrderStatus::actorsAllowedToEnter()`, stamps the lifecycle column, writes the append-only `order_status_events` row, audits, and fires the events — all in one transaction. A `$order->status = x; save()` anywhere else opts out of every one of those. The two guards that matter: a seller can never enter Completed (they would release their own escrow) and Paid is System-only.

Entering Paid runs `Actions\SnapshotOrderTerms`, which freezes `payment_mode`, `monetisation_snapshot`, both window lengths and `confirm_due_at` onto the row. Nothing reads a seller's live commission or a settings escrow window for an order that has already been paid — that is what stops an admin changing a default from rewriting settled sales. The snapshot is written once; a webhook arriving twice finds `snapshot_at` set and leaves it.

`confirm_due_at` / `auto_complete_at` are stored, not computed, so the hourly sweeps (`AutoCancelUnconfirmedOrders`, `AutoCompleteFulfilledOrders`) are index scans and a lengthened window cannot move a deadline an order is already running against. `scopeReadyToAutoComplete` excludes orders with an OPEN DISPUTE ROW rather than checking the order's status — a moderator may move an order out of Disputed while still deciding.

`OrderCancelled` is only dispatched when the previous status was paid; an order cancelled before payment never took stock. `CheckoutService::place()` compares the policy ids+versions the buyer posted against what is current and refuses (`CheckoutUnavailable::policiesChanged`) rather than recording consent for text nobody read. Seams for Prompt 09: `OrderPaymentService::settle()/abandon()` and the `MonetisationPolicyProvider` interface (Finance rebinds it); `DeliveryFeeStrategy` is the seam for 1.x zone/distance pricing.
