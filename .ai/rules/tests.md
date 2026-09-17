---
paths:
  - 'tests/**'
---

# Tests

## A one-row fixture hides a missing eager load
`Builder::hydrate()` only sets `$model->preventsLazyLoading` when the query returned MORE THAN ONE row, so a missing `with()` never throws on a fixture with a single record — it 500s the moment a real account has two. The seller listing portal shipped that way: `SellerProductResource` reads `seller.type` and the index query did not load it.

Any test covering a paginated/list screen must create at least two rows, or it proves nothing about N+1.
