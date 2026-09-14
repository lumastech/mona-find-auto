# MonaFindAuto

A multi-vendor marketplace for vehicle parts in Zambia. Buyers find parts from
verified sellers; mechanics hold endorsed public profiles; MonaFind staff
verify sellers, moderate listings and handle disputes and money.

Currency is ZMW (stored as integer ngwee), timezone Africa/Lusaka, English only.

## Getting started

```bash
composer setup     # install, key, migrate, seed, npm install, build
composer dev       # serve + queue + vite
```

`composer setup` seeds the platform's roles, its configurable settings, and the
Zambian province and town reference lists. In `local` it also creates one
account per role — see below.

## Local development accounts

Created by `Database\Seeders\DatabaseSeeder` in the `local` environment only.
Every one of them uses the password **`password`**.

| Email | Roles | What it is for |
| --- | --- | --- |
| `admin@monafindauto.test` | platform-admin, buyer | Full access, including money movement and platform settings |
| `moderator@monafindauto.test` | moderator, buyer | Seller verification, listing moderation, disputes |
| `finance@monafindauto.test` | finance, buyer | Payouts, refunds, reconciliation, the ledger |
| `seller@monafindauto.test` | seller, buyer | The seller portal at `/seller` |
| `seller-staff@monafindauto.test` | seller-staff | Additional staff on a seller account |
| `mechanic@monafindauto.test` | mechanic, buyer | A mechanic who also buys parts — the common case |
| `buyer@monafindauto.test` | buyer | The storefront |

Phone numbers run `+260977000001` upwards; all are pre-verified, so these
accounts skip the SMS step.

**Two-factor authentication is mandatory for `moderator`, `finance` and
`platform-admin`.** The seeded staff accounts have not enrolled, so their first
login lands on `/settings/security` and nothing else opens until they set up
TOTP. That is the intended behaviour, not a broken seed.

## The three areas

| Area | Route prefix | Who |
| --- | --- | --- |
| Storefront | `/` | Guests and buyers. SSR is enabled here for SEO. |
| Seller portal | `/seller` | `seller`, `seller-staff` |
| Staff console | `/admin` | `moderator`, `finance`, `platform-admin` |

Every buyer and seller capability is mirrored under `/api/v1` (Sanctum) for the
mobile app, in the `ApiResponse` envelope.

## Checks

```bash
composer test         # Pest
composer lint         # Pint
composer types:check  # PHPStan level 8
npm run check         # ESLint + formatting
npm run types:check   # vue-tsc
composer test:browser # Dusk
```

`composer ci:check` runs the lot, plus the SSR build and the OpenAPI export.

## Where things live

Modules are in `app/Modules/<Name>`, each with its own models, controllers,
services, migrations, factories and route files, listed in `config/modules.php`.
They talk to each other through domain events and service interfaces only.

Project conventions that are settled decisions live in `.ai/rules`; read
`.ai/rules/index.md` before changing code.
# mona-find-auto
