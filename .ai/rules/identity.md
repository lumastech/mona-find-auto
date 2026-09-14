---
paths:
  - 'app/Modules/Identity/**'
---

# Identity

## Accounts: phones are E.164, status gates everything, staff need 2FA
The Identity module owns accounts. Four things every other module depends on:

- Phone numbers are stored in E.164 (`+260977123456`). Normalise input with `AccountFieldRules::normalisePhone()` BEFORE validating — `Rule::unique` compares against the column, so `0977123456` would otherwise not collide with the stored form. `phone_network` is derived from `phone` on save; never set it by hand.
- `User::$status` (AccountStatus) decides whether anyone can act. `EnsureAccountIsActive` runs in the `web` and `api.v1` groups and cuts a suspended/closed account off on its very next request. Change a status only through `AccountModerationService` — it audits, notifies, and tears down sessions, tokens and the remember cookie.
- 2FA is mandatory for `moderator`, `finance` and `platform-admin`. `EnsureStaffTwoFactor` runs in the `web` group (not just `admin`), so an unenrolled staff account is held at `/settings/security` everywhere. Its exemption list is load-bearing — adding a step to the enrolment path means adding its route name there.
- Ask `Gate::allows('staff'|'admin-only'|'finance'|'moderate'|'sell')` rather than re-listing roles; the gates are defined in IdentityServiceProvider.

Registration goes through `Actions\RegisterUser` for both the Fortify web flow and `/api/v1/auth/register`. New accounts land in Pending and become Active only when the phone OTP is confirmed.
