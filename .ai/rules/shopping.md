---
paths:
  - 'app/Modules/Shopping/**'
---

# Shopping

## Shopping: the cart re-prices itself on every read
`CartService::view()` is the only correct way to read a cart. It re-checks every line against its listing on every request — never on a schedule, never cached — because a MonaFind cart sits for days while a buyer assembles a repair across shops, and discovering a moved price at the payment page is discovering it too late.

`cart_items.unit_price_ngwee` is what the buyer was last SHOWN, not what they will pay. The stored price exists only so the cart can say "was K450"; the price rendered is always the listing's. `view()` writes back what it reports, so a "price changed" notice appears exactly once — a notice that never clears is one nobody reads.

The one exception is a line carrying `quotation_id`: a quoted price is an offer with a date on it and governs until `valid_until` passes, after which the line falls back to the shelf price and flags `CartLineIssue::QuoteExpired`. That id rides on to the order line so money can be traced to the offer.

Dead lines (unpublished, freshness-hidden, suspended seller) are REMOVED and reported in `CartView::removed`; changed lines (price, clamped quantity) are kept. `CartLineIssue::blocksCheckout()` is what separates the two — a price the buyer has now seen is payable, an empty shelf is not.

Lines carry a denormalised `seller_id`: a cart is grouped by shop everywhere and becomes one order per shop.

## Shopping: wishlist snapshots are written once; quote expiry is time, not status
`wishlist_items.price_ngwee_at_save` / `in_stock_at_save` are written on save and NEVER refreshed. They are the baseline a price drop is measured against — a baseline that followed the listing would report that nothing ever changes. Hence `WishlistService::add()` is idempotent: a second press of the heart is not a second save. The comparison lives in `Support\WishlistChange`, not on either model.

Quotation expiry is the passage of time. `Quotation::hasExpired()` / `isAcceptable()` decide it from `valid_until` (end of day — a quote is good THROUGH its date); `ExpireStaleQuotations` only writes it down so lists and counts stop lying. Never trust the status column alone: `QuotationService::accept()` re-checks the clock and expires-then-refuses a stale quote. Resources send `is_acceptable`, and the UI must gate the Accept button on that, not on `status`.

`QuotationService` is the only writer of `Quotation::$status`; legal moves live on `QuotationStatus::allowedTransitions()`. Authorisation is separate and belongs to `QuotationPolicy`: only the ADDRESSED seller may quote (checked against `quotation->seller_id`, not against holding the seller role), and only the buyer who asked may accept.

`SellerEnquiryChannel` is a consumer-owned contract (the pattern Search uses for `SellerReputationProvider`). `StoredEnquiryChannel` was the stub; Messaging now binds `MessagingEnquiryChannel`, so "Contact seller" opens a real thread the shop can reply in — and the storefront did not change, which was the point. `SellerEnquired` is still dispatched, still carrying an `EnquiryReference` rather than a model. The `seller_enquiries` table is vestigial: nothing writes to it any more.

Shopping shares `shopping.{cart_count,wishlist_count,saved_product_ids}` onto storefront pages so Catalog/Search/Sellers need not learn wishlists exist to draw a filled heart. `StorefrontLayout` must NOT default those count props to 0 — `0` beats the `??` fallback and pins both badges at zero.
