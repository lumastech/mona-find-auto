---
paths:
  - 'app/Modules/Catalog/**'
---

# Catalog

## Catalog: two independent badges, forced condition, one lifecycle writer
Every listing carries TWO independent badges and the storefront never renders one without the other. `condition` (Condition) says what the part is; `inspection_status` (InspectionStatus, default Uninspected) says whether MonaFind looked at it. A brand-new part can be uninspected and a car-breaker part can be inspected. The Vue side pairs them once in `components/catalog/ListingBadges.vue` — render that, never ConditionBadge or InspectionBadge alone.

Condition is derived from the seller's type, never trusted from the payload: `ProductService::resolveCondition()` forces Car Breaker for a CB seller and THROWS `ConditionNotAllowed` on a mismatch rather than correcting it quietly (a wrong "Brand New" is a claim a buyer acts on). Seeders and factories must ask `Condition::forcedFor($seller->type)` rather than asserting one.

The inspection badge is staff-only: only `ListingInspectionService` writes it, it checks `Gate::allows('inspect')`, and every change audits with a required reason. It is absent from `ProductService::fillable()` by design.

`ListingModerationService` is the only thing that writes `Product::$status`; legal moves live on `ListingStatus::allowedTransitions()`. Rejections carry per-field reasons (`rejection_fields`, keyed by form field) so a seller is told what to fix. A suspended seller's stock comes down through `UnpublishListingsOfSuspendedSeller` listening to `SellerVerificationChanged` — Catalog's only dependency on the Sellers workflow.

## Catalog: category tree, media pipeline and slugs
Category is a parent pointer plus derived `depth` and `path` ("/1/7/23/"). `CategoryTree` is the ONLY thing that writes those two columns — set `parent_id` and let it place the node. Subtree queries are `where('path','like',$c->subtreePattern())`, which is why browsing "Engine" returns injectors. Max depth is 3 levels (`Category::MAX_DEPTH = 2`); deeper placement throws `InvalidCategoryPlacement`.

Media: photo originals live on the private `local` disk, conversions on `public` (`storeConversionsOnDisk`). Conversion options (`nonQueued()`, `withResponsiveImages()`) must come BEFORE the image manipulations — past the first manipulation the fluent chain is an ImageDriver, not a Conversion, and PHPStan will catch it. Media library has no watermark step, so `WatermarkListingImage` listens for `ConversionHasBeenCompletedEvent` and burns `resources/images/watermark.png` into the sizes in `Product::DISPLAY_CONVERSIONS`; a missing asset logs and moves on rather than failing the pipeline. Video transcoding goes through the `VideoProcessor` contract — `NullVideoProcessor` is bound wherever ffmpeg is absent (CI included), so the upload still succeeds.

`ProductService` sets the slug itself rather than relying on the `creating` model event, because seeders run `WithoutModelEvents` and would otherwise insert a listing with no slug. The model hook stays as the backstop for factories.

Test fixtures: `UploadedFile::fake()->create()` writes an empty file and media library reads the real mime, so use the `fakeVideoUpload()` helper in tests/Pest.php. Staff HTTP tests need `User::factory()->withTwoFactor()->withRole(...)` — `actingAsRole()` alone is held at /settings/security. `Seller::factory()` picks a random type, so pin `->ofType()` whenever the test asserts a condition.

## Catalog: freshness columns live on products but belong to Inventory
`products.freshness_*` and `product_variants.low_stock_threshold` / `*_alerted_at` are added by Inventory migrations and written only by Inventory services. Catalog reads them, and only in two places: `Product::scopePublished()` (which now also excludes hidden listings — every storefront and API query goes through it) and `Product::isVisibleToBuyers()`. Catalog may import Inventory's `FreshnessState` / `StockLevel` enums; they are value types, not a workflow dependency.

`Product::$attributes` defaults `freshness_state` to `fresh` so an unrefreshed in-memory model is never null. `ProductFactory` sets `freshness_confirmed_at` too — use `->stockConfirmedDaysAgo(n)` or `->stockHidden()` rather than setting either column alone, or the daily sweep immediately contradicts the fixture.

Storefront resources carry availability as `StockLevel` (In stock / Low stock / Out of stock), never a raw quantity; `ProductVariantResource.quantity` is for seller screens only.
