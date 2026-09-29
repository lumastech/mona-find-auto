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

Registration goes through `Actions\RegisterUser` for both the Fortify web flow and `/api/v1/auth/register`. New accounts land in Pending and become Active only when a contact detail is proven — which one depends on `PhoneVerificationGate`, see below.

## No SMS gateway yet: PhoneVerificationGate decides what activates an account
`integrations.sms.phone_verification` (env PHONE_VERIFICATION_ENABLED) is FALSE until Zamtel is live, and `Support\PhoneVerificationGate` is the one place that reads it. While it is off: registration still requires and validates a Zambian number but sends no OTP, registration redirects to `verification.notice`, a verified EMAIL address activates the Pending account (`Listeners\ActivateOnEmailVerification`, and `SocialAuthService::statusForNewAccount()` for social signups whose address the provider already proved), the phone verify/setup screens redirect to the dashboard, `phone.verified` middleware passes through, the `/api/v1/phone` resend+verify endpoints answer 503, and password reset over SMS 404s. Turning the flag on restores the SMS flow with no other change — so do not delete the OTP paths; tests that cover them call `withPhoneVerification()` (tests/Pest.php).

Unrelated pre-existing trap found while doing this: `GET /reset-password/sms` (route `password.sms.reset`) is shadowed by Fortify's `reset-password/{token}`, which reads "sms" as the token and renders `auth/ResetPassword`. That screen has never been reachable at that URI.
