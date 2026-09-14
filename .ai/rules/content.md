---
paths:
  - 'app/Support/Content/**'
---

# Content

## ContentScreen is shared kernel, and reports rather than decides
`App\Support\Content\{ContentScreen,ScreenResult,ScreenFlag}` moved out of Ratings when Messaging needed the same screen — two modules screening free text should not have two definitions of what a Zambian phone number looks like (the reasoning that put `ContactMask` here). Bound as a singleton in `AppServiceProvider`. The word list is `settings('content.profanity_terms')`, renamed from `ratings.moderation.profanity_terms`.

`ScreenResult` reports what was found; it does NOT decide the consequence. Ratings maps it with `RatingStatus::forScreen()` (profanity → PendingReview); Messaging reads `wasRedacted()` and delivers the message anyway. Do not put a `status()` back on the shared value object — the two callers genuinely differ.

Phone detection requires a Zambian prefix (leading 0, or 260) rather than a digit count, so a part number like `90919-01253` survives. See the docblock for the trade.
