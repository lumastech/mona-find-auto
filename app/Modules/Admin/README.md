# Admin

The staff console: the parts of it that belong to no single module.

Every other module mounts its own queue into `/admin` — Catalog the listing
queue, Payments the payout runs, Ratings the review queue. What lives here is
the six screens that read or configure all of them: the dashboard, platform
settings, the consolidated reference-data console, the pages MonaFind
publishes about itself, staff management, and the audit viewer.

## The two registries, and why Admin knows nothing

The dashboard has to show nine counts owned by eight modules, and the
reference console has to edit six curated lists owned by three. Doing either
from inside Admin would mean importing those modules' models and re-deriving
their definitions of "pending" or "in use" — and every later change to what
pending means would have to be made twice.

So Admin asks, and the modules answer:

| Registry | Lives in | A module contributes | Admin reads |
| --- | --- | --- | --- |
| `ConsoleCounters` | `App\Support\Console` | a `ProvidesConsoleCounters` class, from its own service provider | `DashboardController` |
| `ReferenceRegistry` | `App\Support\Reference` | a `ReferenceList` it owns, plus a `link()` per column of ITS OWN tables that points at any list | `ReferenceDataController`, `ReferenceMerger` |

Adding a tenth queue or a seventh list is a change inside the module that owns
it. Adding a column that points at a town is a one-line `link()` in the module
that added the column — miss it and a merge leaves orphans, which is exactly
why the declaration belongs to the module best placed to keep it honest.

One provider throwing costs one dashboard tile, not the whole console.

## Permission matrix

`B` = buyer, `S` = seller. Everything below is additionally behind the `admin`
middleware group (web + auth + verified + staff role) and
`EnsureStaffTwoFactor`, so an unenrolled staff account reaches none of it.

### The Admin module's own screens

| Screen / action | Moderator | Finance | Platform admin |
| --- | :---: | :---: | :---: |
| Dashboard (`admin.dashboard`) | ✅ | ✅ | ✅ |
| — moderation tiles | ✅ | — | ✅ |
| — money tiles | — | ✅ | ✅ |
| Platform settings, read or write | ❌ | ❌ | ✅ |
| Reference data, read | ✅ | ✅ | ✅ |
| Reference data, add / rename / retire | ✅ | ❌ | ✅ |
| Reference data, **merge** | ❌ | ❌ | ✅ |
| Content pages, read | ✅ | ❌ | ✅ |
| Publish About / FAQ / Contact | ✅ | ❌ | ✅ |
| Publish **Platform terms / Privacy** | ❌ | ❌ | ✅ |
| Announcements | ✅ | ❌ | ✅ |
| Staff management | ❌ | ❌ | ✅ |
| Audit trail, read | ✅ | ✅ | ✅ |
| Audit trail, **CSV export** | ❌ | ❌ | ✅ |

### The rest of the console, for context

| Screen / action | Moderator | Finance | Platform admin |
| --- | :---: | :---: | :---: |
| Listing / seller / mechanic queues, read | ✅ | ✅ | ✅ |
| Listing / seller / mechanic **decisions** | ✅ | ❌ | ✅ |
| Review queue and seller trust | ✅ | ❌ | ✅ |
| Disputes, read and resolve | ✅ | ✅ | ✅ |
| Ledger, policies, adjustments | ❌ | ✅ | ✅ |
| Payouts, refunds, reconciliation | ❌ | ✅ | ✅ |
| Post a manual adjustment | ❌ | draft | approve |
| Horizon | ❌ | ❌ | ✅ |

Four rows in that table are the ones worth arguing about.

**Finance can read the moderation queues but decide nothing in them.** A
finance staffer chasing a refund has to be able to open the listing it was for
and the shop that sold it. Publishing, verifying and approving are a different
act, and each is gated at Moderator + PlatformAdmin in its own module's
policy. The review queue is the one queue finance cannot even read: what the
public reads about a Zambian business is not finance work in any sense.

**Both jobs can resolve a dispute.** Resolving one releases escrow or refunds
a buyer, so it is as much a money decision as a moderation one. A platform
where only moderators could settle them would have finance watching money move
on somebody else's judgement.

**Merging reference data is platform-admin only**, though renaming is not.
Renaming "Toyata" fixes a label; merging it rewrites foreign keys across
several modules' tables and deletes a row, and there is no undo.

**Exporting the audit trail is platform-admin only**, though reading it is
open to every staff role — deliberately, because a record its own subjects
cannot inspect is worth very little. A CSV of it is a different act: a file of
names, email addresses, IP addresses and money movements that leaves the
platform and is never seen again. The export writes its own audit row.

## Reasons, everywhere

Every destructive or financial action in this module takes a mandatory reason
of at least five characters, enforced by `ReasonedRequest` rather than by each
action remembering. It reaches the audit row, and a person who has to write a
sentence takes fewer actions they merely disagree with.

## Content, and the one page that is not just a page

`ContentPage` carries a page's identity — slug, footer placement, whether it
is live. Its *words* live in `ContentPageVersion`, and publishing writes a new
version rather than overwriting the old one.

That exists for the platform terms. Every `TermsAcceptance` records the terms
version the buyer agreed to, so `ContentPageService::publish()` bumps
`policies.platform_terms_version` and copies the body into
`policies.platform_terms_body` in the same transaction. If the two ever
disagreed, buyers would be recorded as accepting a version whose text nobody
could produce. The same mechanism gives About and the FAQ a history for free.

Those two settings are consequently **not editable from the settings screen** —
`SettingsSchema::READ_ONLY` refuses them however a form is posted, and the
screen says where to find them instead.

## Settings, and what a legal value is

The `settings` table is untyped beyond a `SettingType`, which leaves nothing
saying that a ranking weight cannot be 900 or that the Ageing window cannot be
shorter than the Fresh window it follows. Both are quietly catastrophic and
neither is visible where somebody types the number.

`SettingsSchema` holds the ranges and the panel layout; `SettingsEditor`
applies them, including the cross-field freshness rule. A key with no panel is
not editable from the console at all. The bounds rendered beside each field
come from the same schema the server enforces, so what the form invites and
what it will keep cannot drift.

## Staff and invitations

An invitation is the only way into a staff role. Tokens are stored as a SHA-256
hash and expire after seven days; the plaintext is shown once on screen because
the mail queue can be minutes behind a new starter standing next to you.

`StaffDirectory` refuses to remove the last platform administrator — from
anybody, including themselves. It is the one mistake on that screen with no way
back: there would be nobody left who could grant the role again. Stepping down
*is* allowed once a successor holds it, which is why this screen checks the
lockout guard rather than `UserPolicy::assignRoles`.

Deactivating is a suspension rather than a role removal, so live sessions end
immediately. Two-factor enrolment is enforced by middleware on every request
platform-wide; the console's count only exists so somebody can chase the person
rather than wait for them to notice.
