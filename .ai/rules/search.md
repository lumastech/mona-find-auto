---
paths:
  - 'app/Modules/Search/**'
---

# Search

## Search: ranking rules are the tiers, one index, one query
The brief's exact/partial match tiers are NOT implemented in PHP. They are the Meilisearch ranking rules in `Support\ProductIndex` (`words → typo → proximity → attribute → sort → exactness → quality_score:desc → price_ngwee:desc`), with `matchingStrategy: last`. One query returns both tiers already ordered — do not add a second query or re-sort results. `sort` sits BELOW relevance deliberately: choosing "price low to high" orders within relevance groups, and only goes global when nothing was typed.

`config/scout.php` points at `ProductIndex::settings()` so `scout:sync-index-settings` and the tests cannot configure different things. Filtering on an attribute missing from `filterableAttributes()` fails silently and returns wrong results.

Tier 3 is a genuinely separate query (`FallbackCategoryFinder`), run only when the first came back empty AND the buyer typed something.

Per-card tier labels come from `SearchTokens` against `ProductDocument::matchText()` — name, part numbers, fitment only. Never widen it to the category name or description: a brake disc filed under "Brake pads" must not be badged an exact match for "hilux brake pads".

Quality scores are computed at index time from `ranking.weight.*` settings, so changing a weight needs a reindex (`php artisan search:reindex`) before it shows. `Services\ListingIndexer` is the only writer — Scout's `searchable()` ignores `shouldBeSearchable()`, so calling it directly indexes listings buyers may not see.

Distance is never in the default order. Rating/review/dispute data comes from `Contracts\SellerReputationProvider`; Ratings and disputes bind it when they land.

Tests in `tests/Feature/Search` need a real Meilisearch via the `usingMeilisearch()` helper and skip without one — the collection driver cannot reproduce ranking, geo or facets.
