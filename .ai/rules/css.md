---
paths:
  - 'resources/css/**'
---

# Css

## Brand tokens: gold is never text on a light ground
`resources/css/app.css` carries the whole brand: Navy #1F2A44, Warm Beige #E8DCC8, Soft Gold #C6A75E, a warm neutral ramp and semantic roles. Every component reads it through the shadcn variables, so a colour change is this file, never a hex in a `.vue`.

Soft Gold on a light ground is 2.3:1 and fails AA at every size. It is legal as a fill carrying navy text, or as text on navy. Where gold has to BE text on light, use `--trust-text` (Bronze #7A6224). `--trust-*` is also the "MonaFind vouched for it" role — inspection, verification, escrow — and must not be spent on a seller's own claim or a decorative accent, or the badge stops meaning anything.

Warning is burnt orange #B4530E, not amber: amber beside Soft Gold reads as a second trust mark rather than a caution. The Tailwind v4 border compat layer points at `--border`, not `--color-gray-200` — a cold grey next to the beige looks like a rendering bug.
