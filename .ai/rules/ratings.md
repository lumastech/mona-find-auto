---
paths:
  - 'app/Modules/Ratings/**'
---

# Ratings

## Ratings: direction decides privacy, sources are registered, trust is precomputed
`RatingDirection::isPublic()` is the privacy rule and it is applied in the QUERY, not the template. `Rating::scopePublic()` (public directions + Published) is what every storefront prop and `/api/v1/ratings` goes through; `RatingPolicy::view()` is the second line, not the first. Seller→Buyer and Mechanic→Buyer are visible to sellers, mechanics and staff — deliberately NOT to the buyer they are about, or buyers learn to trade a bad review for a good rating.

Nothing branches on "is this an order or an endorsement". Each rateable thing implements `Contracts\RatingSourceResolver` (has it finished, who was involved, which directions) and registers with `RatingSourceRegistry` from its own provider. Ratings ships `OrderRatingSource`; Mechanics adds endorsements without touching this module. Uniqueness is a DB unique index on (source_type, source_id, direction) — the service checks first only so the buyer gets a sentence.

`ContentScreen` now lives in `App\Support\Content` (Messaging screens messages with the same one — see .ai/rules/content.md). It does two different jobs: contact details are REDACTED and the review publishes (only the published text is stored — the original phone number is never kept anywhere); profanity QUEUES it, via `RatingStatus::forScreen()` — the mapping is this module's, not the shared value object's. A queued review still counts towards the seller's score (`RatingStatus::countsTowardsAggregate`), or reporting every bad review would suppress them. Phone detection requires a Zambian prefix (leading 0, or 260) rather than a digit count, so `90919-01253` survives — see the docblock for the trade. The word list is `settings('content.profanity_terms')`.

`seller_trust_scores` is a cache rebuilt nightly at 02:15 (before Search's 02:30). `RatingsSellerReputation` binds Search's `SellerReputationProvider` over the neutral one and reads only that table — `forMany()` is called with every seller in the catalogue. `SellerTrustScoreChanged` fires ONLY when average/count/dispute-rate moved, and Search re-indexes the seller's whole catalogue on it. A poor average caps the band at Watch however clean the rest of the record: the composite alone calls five one-star reviews "Fair".
