---
paths:
  - app/Providers/AppServiceProvider.php
---

# Providers

## Strict Eloquent mode is the N+1 gate — use loadMissing, not a bare read
`Model::preventLazyLoading()` and `preventSilentlyDiscardingAttributes()` are on everywhere except production. A missing `with()` therefore fails the test suite rather than reaching a buyer as a slow page.

Consequences worth knowing:
- Loading a relation on demand is fine, but say so with `loadMissing()`. A bare `$model->relation` read throws.
- `Model::create()`/`fill()` with a non-fillable key now throws instead of silently dropping it. Guarded columns (`User::$name`, `status`, verification stamps) need `forceFill()->save()`.
- `Product::scopeWithCardRelations()` is the single definition of what a listing card reads — including `make` and `vehicleModel`, reached indirectly through `fitmentSummary()`. Paginating cards without it is 25 extra queries a page.
- `FreshnessService::sweepable()` eager-loads `seller` because every freshness transition is reindexed by Search, which reads it.
