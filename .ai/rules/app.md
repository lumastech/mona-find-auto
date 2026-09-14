---
paths:
  - 'app/**'
---

# App

## Money is always integer ngwee via App\Support\Money\Money
Never use a float in a money path. `Money` is a self-contained value object (no container access, constants for ZMW/K/100/2) holding an integer number of ngwee.

- `Money::ofNgwee(1075)` and `Money::ofKwacha('10.75')` are the same amount. `Money::from()` reads ints as ngwee, strings as kwacha — the same rule the `MoneyCast` follows.
- Shrinking an amount always requires an explicit `RoundingMode`: `multiplyByRatio()` / `percentage()`. Use `allocate()` to split without losing a ngwee.
- Store on models as an integer column cast with `App\Support\Money\MoneyCast`.
- `config('monafind.currency')` is derived from `Money::currencyDescriptor()`, not the other way round.
- Frontend mirror: `resources/js/lib/money.ts` + `<Money :amount="ngwee" />`.

## Configurable values live in settings(), not env()
Anything the brief calls "configurable in admin" — escrow windows, freshness thresholds, ranking weights, commission defaults, reserve percent, dispute threshold — belongs in the `settings` table, seeded by `Database\Seeders\SettingsSeeder`, read with `settings('escrow.pickup_window_days')`.

- Writes go through `settings()->set($key, $value, $actor, $reason)`, which flushes the cache and writes an audit row automatically.
- New keys are declared with `settings()->define()` and a `SettingType`; mark `is_public` only for values safe to hand the browser.
- Re-running SettingsSeeder refreshes definitions but keeps an administrator's changed value.
- Every staff action and money movement writes `audit($actor, $action, $subject, $before, $after, $reason)`.
