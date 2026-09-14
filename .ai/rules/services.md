---
paths:
  - app/Modules/Catalog/Services/StorefrontNavigation.php
---

# Services

## Header nav is one cached shared prop, flushed by model events
`StorefrontNavigation::payload()` builds the header's category menu and the make/model/year picker, cached for a day and shared as the `nav` Inertia prop by `CatalogServiceProvider::shareNavigation()` — null inside /seller and /admin, same gate as `shopping`. Pages must not fetch or pass it; the header is on every screen.

The menu is roots + children only (two of the tree's three levels) because a third fly-out tier is unusable with a thumb. Models ship with the payload rather than behind an endpoint so choosing a make costs no round trip on mobile data — `MODEL_LIMIT` (600) is the guard; past it the picker has to become an endpoint.

The cache is knocked down by `saved`/`deleted` on Category, Make and VehicleModel, hung off the models rather than the three admin controllers because `ReferenceMerger` writes these rows too. Seeders running `WithoutModelEvents` will not flush it.
