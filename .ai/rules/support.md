---
paths:
  - app/Modules/Orders/Support/MonetisationSnapshot.php
---

# Support

## VAT on commission is dated, not a setting
The rate comes from `VatRateProvider::percentAt()`, not from `settings('monetisation.vat_on_commission_percent')`. Orders binds a flat-setting floor; Finance binds `VatRateSchedule`, which reads the dated `vat_rates` table so "what was the rate on the 14th" is answerable.

Changing the schedule reprices FUTURE orders only. A settled order carries its rate in `monetisation_snapshot` and every invoice, statement and refund figure is derived from that copy — the test `VatRateScheduleTest` asserts this directly.

The settings row is kept as a one-way mirror of today's rate (for the settings screen and for deployments with Finance disabled). The schedule is the truth; never read the setting back as authoritative.

Trap: `effective_from` is a `date`-cast column, so Laravel stores "2026-06-01 00:00:00". Compare with `whereDate`, not a plain `where` against a bare date string, or a rate is not found until the day after it commences.
