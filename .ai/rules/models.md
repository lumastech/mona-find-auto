---
paths:
  - app/Modules/Catalog/Models/Product.php
---

# Models

## Catalog: Product carries Search's trait, and that is the whole dependency
`Product` uses `App\Modules\Search\Concerns\SearchableListing` (which pulls in Scout's `Searchable`). Scout indexes Eloquent models, so a searchable listing has to say so on the model — but what is indexed, under what name, and when a listing belongs in the index at all is decided inside Search. Do not add `toSearchableArray()`, `searchableAs()` or `shouldBeSearchable()` to Product; change `SearchableListing` / `Search\Services\ProductDocument` instead.

Anything added to Product that the storefront filters or ranks on has to reach `ProductDocument::for()` and `ProductIndex::filterableAttributes()`, or search will quietly never see it.
