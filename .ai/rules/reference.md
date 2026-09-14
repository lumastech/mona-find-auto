---
paths:
  - 'app/Support/Reference/**'
---

# Reference

## Declare every column that points at a reference list, in the module that owns the column
`ReferenceRegistry` has two halves. A module `register()`s the `ReferenceList` it OWNS (Catalog: makes/vehicle-models/categories; Identity: provinces/cities; Mechanics: specialities), and EVERY module `link()`s the columns of its own tables that reference any list — Sellers declares `sellers.city_id`, Mechanics declares `mechanic_profiles.city_id`.

Adding a column that references a town and forgetting the `link()` leaves orphans the next time somebody merges that town. Links are keyed by string and may be registered before the list itself, so provider boot order never matters.

Pass `uniqueWith` for a pivot column (see `mechanic_profile_speciality`) or the merge collides on the unique key. `ReferenceMerger` repoints inside one transaction and deletes via the model, not the query builder, so tree models keep their derived columns honest.
