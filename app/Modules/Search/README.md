# Search

How a buyer finds a part, and the order the parts come back in.

That order is the most consequential product decision the platform makes: it
decides which Zambian shop gets the phone call. It is therefore configured
rather than coded — the six weights behind every listing's quality score live
in `settings` under `ranking.weight.*`, and an administrator moving one of them
re-ranks the catalogue on the next rebuild.

## The index

One Meilisearch index, `{prefix}products`, holding only listings a buyer may
actually open: published, stock not gone stale, seller still standing. The
question is asked in one place — `Product::isVisibleToBuyers()` — and the
module never indexes anything that answers no.

Every document is flat. Facts that belong to the seller (name, type, town,
GPS, verification) are copied onto each of its listings, because a facet
sidebar cannot join and a buyer filtering "verified sellers in Kitwe" is asking
one question rather than two. The price of that is `Jobs\ReindexSellerListings`:
change the shop and its whole catalogue has to be rewritten.

The settings themselves are in `Support\ProductIndex`, which `config/scout.php`
points at so that `php artisan scout:sync-index-settings` and this module's
tests can never configure different things.

## Ranking rules — where the match tiers actually live

```
words → typo → proximity → attribute → sort → exactness → quality_score:desc → price_ngwee:desc
```

Read top to bottom, that list *is* the brief's tiering. There is no second
query and no manual bucketing:

| Rule | What it buys |
| --- | --- |
| `words` | A listing holding every word the buyer typed outranks one holding fewer. **This is tier 1 above tier 2.** |
| `typo` | A real match beats one that needed a correction. |
| `proximity` | "brake pads" beats "brake … pads". |
| `attribute` | A hit in the name beats the same string buried in a description. |
| `sort` | Where an alternative sort (price, newest, nearest) takes effect. |
| `exactness` | A whole-word match beats a prefix match. |
| `quality_score:desc` | The scored ordering inside a tier. |
| `price_ngwee:desc` | The brief's tiebreaker. |

Two of these are worth arguing about.

**`sort` sits below relevance, not above it.** Choosing "price: low to high"
does not drag a wiper blade above the brake pads somebody searched for; it
orders within each relevance group. With nothing typed — a buyer browsing a
category — every document ties above that line and the sort is global, which is
what they expect. Moving `sort` to the top would make a text search sorted by
price nearly useless.

**Price descending is deliberate and is the rule that surprises people.**
Cheapest-first is what a buyer would choose for themselves, and the default is
not trying to be that. Among parts that match equally well, from shops the
platform rates equally, the dearer listing is more often the complete, boxed,
warrantied one. Buyers who want the cheapest have a sort control that is on
screen at every width.

### How tier 2 is reached

`matchingStrategy` is `last`. Meilisearch returns the documents matching every
term first and then, if the page is not full, drops trailing terms one at a
time and appends what those matched — so a single result set can contain both
tiers, ordered correctly by the `words` rule. Nothing in this module
de-duplicates or re-sorts that.

### Tier 3

Only when the whole thing comes back empty *and* the buyer typed something.
`Services\FallbackCategoryFinder` picks the deepest active category matching any
of their words, the search is re-run with the free text dropped and that
category forced, and the page says `No exact matches — showing related parts`.
A buyer who had already picked a category is not shown the category they
picked.

### Labelling each result

The page tier is one thing; the badge on each card is another.
`Support\SearchTokens` decides, in PHP, whether a given listing holds every word
the buyer typed, judged against `ProductDocument::matchText()` — the listing's
name, its part numbers and what it fits. Deliberately narrower than what
Meilisearch searches: the category name and the description are searchable, and
should be, but a brake disc filed under "Brake pads" must not be badged an
*exact* match for "hilux brake pads". That is the one label a buyer acts on
without reading further.

## Quality score

`Services\QualityScore`, computed at index time, out of the weights added up
(100 by default):

| Component | Default | Notes |
| --- | --- | --- |
| Seller rating | 30 | Bayesian: pulled towards a 3.5 prior worth five reviews, so one delighted cousin cannot outrank forty real customers. |
| Review count | 10 | Log-scaled, saturating at 50 reviews. |
| Verified seller | 20 | |
| Inspected listing | 15 | Independent of condition. |
| Stock freshness | 15 | Scaled by `FreshnessState::rankingMultiplier()` — Inventory's own answer, not a second one. |
| Low disputes | 10 | Banded, against `risk.dispute_rate_threshold_percent`. |

Rounded to two decimals: ties are wanted, because a tie is what hands the
decision to price descending.

Rating, review count and dispute rate come from
`Contracts\SellerReputationProvider`. Ratings and disputes do not exist yet, so
`Support\NeutralSellerReputation` answers and every seller scores the same on
those three. That is correct rather than a placeholder — with no reviews
anywhere, nobody has earned a place above anybody else.

## Distance

Never in the default order. "Nearest first" is a sort a buyer chooses and a
radius is a filter they set; both need a location they shared or typed, and
asking for Nearest without one falls back to Recommended rather than erroring.
A shop that has never been placed on the map carries no `_geo` and so drops out
of a radius search entirely, which is the honest answer to "what is within 10km
of me".

## Keeping the index honest

Scout's model observer covers ordinary saves. Three things it never sees have
listeners here: Inventory's nightly freshness sweep (a bulk update), a
moderator's inspection decision, and a seller being verified or suspended.

On top of that, `Jobs\RebuildSearchIndex` runs at 02:30 Africa/Lusaka — after
Inventory's 02:00 sweep, so scores are computed against the states that sweep
just assigned. It is also the only thing that recomputes *every* quality score
against the current weights, which is what makes an administrator's change show
up across the catalogue rather than only on listings that happened to be
touched. `php artisan search:reindex --settings` does the same by hand.

The rebuild is two passes — index the visible, then remove the rest — rather
than flush-then-import. Flushing is simpler and guarantees no orphans, but it
would leave every buyer searching an empty catalogue for the length of the
rebuild.

## Analytics

Every search that had a query or a filter is written to `search_queries`;
paging deeper is not counted again, because that would rank the searches people
scroll through as though they were the searches people run. The zero-result
rows are the point — they are the reference-data backlog written by buyers, and
`/admin/search-insights` is where staff read it.

## Tests

`tests/Feature/Search` runs against a real Meilisearch (`compose.yaml` provides
one) via the `usingMeilisearch()` helper, and skips with a message when there is
none. Asserting Meilisearch's ranking against Scout's collection driver would
only prove that a stub does what the stub was told.
