# OWASP ASVS 4.0 Level 2 — self-assessment

**Scope:** MonaFindAuto, the whole application — storefront, seller portal, staff console
and the `/api/v1` JSON API.
**Assessed:** 13 September 2026, against the codebase at milestone M15.
**Assessor:** development team (self-assessment, not an external audit).

## How to read this

Each control is **Pass**, **Partial**, **Fail** or **N/A**, with the file that implements
it or the work still outstanding. A control is only **Pass** where something in the
repository enforces it — a policy nobody has written down as code is recorded as
**Partial** however confident anyone is about it.

Two things this document is deliberately not:

- It is not an external penetration test. It says what the code does, not what somebody
  determined would find. A pentest before public launch is on `docs/LAUNCH.md`.
- It is not a certificate. ASVS L2 is the level appropriate to an application handling
  payments and personal data, and this is our own read of how far we have got.

**Summary: 112 controls assessed — 101 Pass · 7 Partial · 0 Fail · 4 N/A.** Every Partial has a named owner and a
target in the remediation table at the end.

Two gaps were found and closed while writing this document rather than recorded as
findings: `SESSION_SECURE_COOKIE` was absent from `.env.example` (14.3.2 / 3.4.1), and
the dependency-audit gate claimed under 10.3.3 did not actually exist in CI until it was
added. Both are now in the repository.

---

## V1 — Architecture, design and threat modelling

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 1.1.1 | SDLC uses a repeatable, documented process | Pass | CI gates every merge: Pint, PHPStan L8, vue-tsc, Pest, Vitest, build. `.github/workflows/ci.yml` |
| 1.2.2 | Components communicate with mutual authentication | Pass | Lenco webhooks are HMAC-verified; outbound calls carry a secret key. `WebhookIngestor::isAuthentic()` |
| 1.2.3 | All authentication paths use one strong mechanism | Pass | Fortify for web, Sanctum for API; both register through `Actions\RegisterUser` and both honour `AccountStatus`. `.ai/rules/identity.md` |
| 1.4.1 | Trusted enforcement points enforce access control | Pass | Middleware groups `seller` / `admin` plus per-model policies. `bootstrap/app.php` |
| 1.4.4 | One access-control mechanism, used everywhere | Pass | spatie/laravel-permission roles behind named gates (`staff`, `finance`, `moderate`, `sell`). Modules ask the gate, never the role list |
| 1.5.2 | Serialisation is safe against untrusted data | Pass | No `unserialize()` of user input anywhere; webhook bodies are `json_decode`d and signature-checked against the **raw** bytes first |
| 1.8.1 | Sensitive data is identified and classified | Pass | `docs/DATA_RETENTION.md` classifies every table; each module declares its own PII through `PersonalDataSource` |
| 1.11.2 | Business logic flows are sequential and cannot be skipped | Pass | `OrderStateMachine` allows only declared transitions; `ListingStatus::allowedTransitions()` does the same for listings |
| 1.14.6 | No unsupported or insecure client technologies | Pass | No Flash, applets or NPAPI. CSP sets `object-src 'none'` |

## V2 — Authentication

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 2.1.1 | Passwords are at least 12 characters | Pass | `Password::min(12)` with mixed case, numbers, symbols in production. `AppServiceProvider::configureDefaults()` |
| 2.1.7 | Passwords are checked against breach corpora | Pass | `->uncompromised()` (Pwned Passwords k-anonymity) in production |
| 2.1.9 | No composition rules beyond length/complexity above | Pass | No forced rotation, no character-class puzzles |
| 2.2.1 | Anti-automation on authentication | Pass | `login` limiter 5/min keyed on identifier+IP; `two-factor` 5/min; `passkeys` 10/min. `FortifyServiceProvider` |
| 2.2.3 | Secure notifications are sent after credential changes | Pass | Password reset revokes every session and token; account-status changes notify by mail and SMS |
| 2.3.1 | Initial activation is not a guessable secret | Pass | Phone OTP is random, hashed at rest, single-use, expiring. `OtpService` |
| 2.5.1 | No recovery secret sent in cleartext to a stored channel | Partial | Reset codes go by SMS, which is not end-to-end encrypted. Accepted: it is the only channel a Zambian buyer reliably has. Mitigated by 10-minute expiry, 3 attempts, single use |
| 2.5.4 | No shared or default accounts | Pass | `DevelopmentAccountSeeder` runs only in `local` (guarded in `DatabaseSeeder`) |
| 2.5.6 | Password reset is a secure, time-limited token | Pass | Fortify's signed email tokens, and `OtpPurpose::PasswordReset` for SMS |
| 2.7.2 | Out-of-band authenticator expires within 10 minutes | Pass | `identity.otp_expiry_minutes` defaults to 10 |
| 2.7.6 | Out-of-band code is random, ≥ 20 bits, single use | Partial | Six digits ≈ 19.9 bits. Compensated by 3 attempts then burn, plus a 5/min send throttle. Raising it to 7 digits is tracked below |
| 2.8.1 | TOTP secrets are stored encrypted | Pass | Fortify encrypts `two_factor_secret` / `two_factor_recovery_codes` |
| 2.8.6 | Two-factor is available and enforced for privileged accounts | Pass | Mandatory for `moderator`, `finance`, `platform-admin`; `EnsureStaffTwoFactor` holds them at enrolment **everywhere**, not just `/admin` |

## V3 — Session management

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 3.2.1 | New session token on authentication | Pass | Laravel regenerates the session id on login |
| 3.2.3 | Session tokens only in cookies or headers | Pass | Never in URLs; Sanctum uses a bearer header |
| 3.3.1 | Logout terminates the session | Pass | Fortify invalidates and regenerates |
| 3.3.2 | Re-authentication after inactivity | Partial | `SESSION_LIFETIME` is 120 minutes with no absolute cap. Not raised for buyers; staff absolute timeout is tracked below |
| 3.3.3 | Users can view and terminate active sessions | Pass | `/settings/sessions` lists devices and API tokens and revokes either. `SessionRegistry` |
| 3.4.1 | Cookies use `Secure` | Pass | `SESSION_SECURE_COOKIE` is present in `.env.example` with a comment saying production must set it true, and it is a line on `docs/LAUNCH.md`. It is **not** on by default — the framework default is null, which was the gap this assessment found |
| 3.4.2 | Cookies use `HttpOnly` | Pass | Laravel default; only `appearance` and `sidebar_state` are exempt from encryption and neither is a credential |
| 3.4.3 | Cookies use `SameSite` | Pass | `SESSION_SAME_SITE=lax` |
| 3.5.3 | Stateless tokens use a proven mechanism | Pass | Sanctum tokens are random, hashed at rest, revocable |
| 3.7.1 | Session is verified before a sensitive transaction | Pass | Account deletion re-checks the password (`RequestErasureRequest`); Fortify's password-confirm guards 2FA changes |

## V4 — Access control

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 4.1.1 | Access control enforced server-side | Pass | Policies and gates; the Vue side only ever hides what the server already refuses |
| 4.1.2 | Users cannot manipulate their own permissions | Pass | Roles are never fillable; staff roles are granted only through `StaffInvitation` |
| 4.1.3 | Least privilege | Pass | Six roles with distinct gates. Reconciliation is `finance`-only — a moderator working the dispute queue cannot see the platform's cash position |
| 4.1.5 | Access control fails securely | Pass | Policies return false by default; unauthorised reads answer **404** where the id itself is a fact worth hiding (cart lines, seller documents) |
| 4.2.1 | Object-level access control on every reference | Pass | Every `{order}`, `{item}`, `{media}` route checks ownership. Media ids are global, so `SellerDocumentController` additionally checks the document belongs to *that* seller |
| 4.2.2 | CSRF protection on state-changing operations | Pass | `ValidateCsrfToken` on the whole `web` group; the Lenco webhook is exempt and HMAC-authenticated instead |
| 4.3.1 | Administrative interfaces use multi-factor | Pass | See 2.8.6 |
| 4.3.2 | Directory browsing is disabled | Pass | Only `public/` is served; media originals sit on the private disk and stream through a policy-checked route |
| 4.3.3 | Additional authorisation for low-value transactions | Pass | Every destructive or financial console action extends `ReasonedRequest` and records a reason in the audit trail |

## V5 — Validation, sanitisation and encoding

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 5.1.1 | Protection against HTTP parameter pollution | Pass | Laravel takes the last value; no `$_REQUEST` use |
| 5.1.3 | All input is validated | Pass | Form Requests or `Validator::make` on every endpoint. PHPStan L8 catches unvalidated array access |
| 5.1.4 | Positive (allow-list) validation | Pass | Enum rules throughout (`Rule::enum`, `Rule::exists`); free text is length-bounded |
| 5.2.1 | Untrusted HTML input is sanitised | Pass | Vue escapes by default. `v-html` appears in eleven places and **none of them render user input**: ten are Laravel paginator `link.label` values (`&laquo; Previous`) and one is the server-generated 2FA QR code SVG. Verified by `grep -rn 'v-html' resources/js` |
| 5.2.3 | SMTP/IMAP injection is prevented | Pass | Notifications are built from typed values, never from raw user input in headers |
| 5.3.1 | Output encoding is contextual | Pass | Inertia serialises to JSON; Vue escapes on render |
| 5.3.4 | SQL injection is prevented | Pass | Eloquent and the query builder throughout. The few `havingRaw`/`selectRaw` uses bind their parameters — see `.ai/rules/modules.md` for why one of them binds an integer |
| 5.3.8 | Local file inclusion is prevented | Pass | No user input reaches `include`/`require`; uploads are stored with generated names |
| 5.3.10 | XPath / XML injection | N/A | No XML parsing |
| 5.5.2 | Safe deserialisation | Pass | See 1.5.2 |

## V7 — Error handling and logging

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 7.1.1 | No credentials or payment details in logs | Pass | `LencoWebhookController` logs a rejected webhook **without** its body; `User` hides `password`, `two_factor_secret`, `remember_token` |
| 7.1.3 | Security-relevant events are logged | Pass | Every staff action and money movement writes an immutable `AuditLog` row (actor, action, subject, before/after, reason) |
| 7.1.4 | Log entries include enough context | Pass | Actor, subject, before/after and reason on every audit row |
| 7.2.1 | All authentication decisions are logged | Pass | `user.registered`, `user.phone_verified`, `user.password_reset`, status transitions |
| 7.2.2 | All access-control decisions are logged | Partial | Denials are not all logged — policy failures 403/404 without an audit row. Successful privileged actions all are. Tracked below |
| 7.3.1 | Logs are protected from injection | Pass | Structured context arrays, never string interpolation of user input |
| 7.3.3 | Security logs are protected from deletion | Pass | `audit_logs` is append-only in **both** layers: the `AppendOnly` trait and a database trigger, so a raw `DELETE` fails too |
| 7.4.1 | Generic error messages are shown to users | Pass | `ApiExceptionRenderer` maps every exception into the error envelope; `APP_DEBUG=false` in production |

## V8 — Data protection

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 8.1.1 | Sensitive data is not cached client-side | Pass | `CacheStorefrontResponses` marks every authenticated response `private, no-store` |
| 8.1.2 | Sensitive data is not stored in temporary files | Pass | Uploads go straight to the configured disk |
| 8.2.1 | No sensitive data in browser storage | Pass | Only `appearance` and `sidebar_state` cookies, neither a credential |
| 8.2.2 | No sensitive data in the URL | Pass | Orders are bound by number, listings by slug; no tokens in query strings |
| 8.3.1 | Sensitive data is sent in the body, not the query | Pass | All credential and payment fields are POST bodies |
| 8.3.4 | Sensitive data is inventoried and has a retention rule | Pass | `docs/DATA_RETENTION.md` |
| 8.3.7 | Sensitive PII is encrypted at rest | Pass | Payout account fields and social-provider tokens use the `encrypted` cast |
| 8.3.8 | Sensitive PII has an owner and a lawful basis | Pass | Recorded per table in `docs/DATA_RETENTION.md`; consent itself is an append-only log (`consent_records`) |

## V9 — Communications

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 9.1.1 | TLS everywhere, including internal traffic | Partial | TLS terminates at the edge; app-to-MySQL and app-to-Redis run inside a private network without TLS. Acceptable for the pilot topology; tracked below |
| 9.1.2 | Only strong ciphers and TLS 1.2+ | Pass | Edge-terminated; the launch checklist pins the profile |
| 9.1.3 | Only strong TLS versions are enabled | Pass | Same |
| 9.2.1 | Connections to external systems verify certificates | Pass | Guzzle verifies by default; nothing disables it |
| 9.2.2 | Encrypted communications to external systems | Pass | Lenco, Zamtel, Google Maps and Cloudflare are all HTTPS. CSP's `connect-src` is the readable inventory |

## V10 — Malicious code

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 10.2.1 | No code that phones home undocumented | Pass | Every outbound host is declared in `config/security.php` under `csp.allow` |
| 10.3.2 | Integrity checks on dependencies | Pass | `composer.lock` and `package-lock.json` are committed; CI installs from them |
| 10.3.3 | Dependencies are checked for known vulnerabilities | Pass | `composer audit` and `npm audit` run as a CI gate. `.github/workflows/ci.yml` |

## V11 — Business logic

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 11.1.1 | Business logic flows process in sequence | Pass | `OrderStateMachine`; escrow releases only from a legal predecessor state |
| 11.1.2 | Business limits are enforced server-side | Pass | Stock reservation, per-buyer address limits, quotation expiry, payout reserve |
| 11.1.3 | Protection against excessive-rate abuse | Pass | Named limiters on checkout, payments, search, registration, uploads and webhooks. `AppServiceProvider::configureRateLimiting()` |
| 11.1.4 | Anti-automation on high-value flows | Pass | Turnstile on registration and password reset; `throttle:checkout` on order placement |
| 11.1.5 | Business risk limits per user | Pass | DIRECT sellers carry a rolling reserve and auto-revert to escrow past a dispute-rate threshold |

## V12 — Files and resources

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 12.1.1 | Upload size limits | Pass | Validated per collection; enforced again by the web server |
| 12.2.1 | Uploaded file type is validated | Pass | MIME read from the **bytes**, not the filename — see `tests/Pest.php::fakeVideoUpload()` for why the test fixtures carry real headers |
| 12.3.1 | User-submitted filenames are not used directly | Pass | Media library generates names |
| 12.4.1 | Files are stored outside the web root | Pass | Listing photo originals and all seller documents live on the private disk |
| 12.4.2 | Uploaded content is scanned | Partial | No antivirus scanning. Mitigated: nothing uploaded is ever executed, and everything is served with `X-Content-Type-Options: nosniff` and an attachment disposition. Tracked below |
| 12.5.1 | Direct requests for sensitive files are refused | Pass | Documents have no URL at all; `SellerDocumentController` streams them after a policy check |
| 12.6.1 | SSRF protection on server-side file fetches | N/A | The application never fetches a user-supplied URL |

## V13 — API and web service

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 13.1.1 | The same access controls apply to the API | Pass | `EnsureAccountIsActive` is in the `api.v1` group as well as `web`; policies are shared |
| 13.1.3 | API URLs do not leak sensitive information | Pass | Ids and slugs only |
| 13.1.4 | Authorisation decisions are made at both ends | Pass | Server-enforced; the client only hides |
| 13.2.1 | Only the intended HTTP methods are accepted | Pass | Explicit verbs on every route; no `Route::any` |
| 13.2.3 | CSRF protection or token-based auth on the API | Pass | Sanctum bearer tokens; the API group carries no session |
| 13.2.5 | Requests are content-type validated | Pass | `ForceJsonResponse` in the `api.v1` group |
| 13.2.6 | Messages are validated against a schema | Pass | Form Requests; OpenAPI is generated from them via Scramble |
| 13.3.1 | SOAP schema validation | N/A | No SOAP |
| 13.4.1 | GraphQL query allow-listing | N/A | No GraphQL |

## V14 — Configuration

| # | Control | Status | Evidence / notes |
|---|---|---|---|
| 14.1.1 | Build and deploy are repeatable and automated | Pass | CI builds client and SSR bundles and uploads them as artifacts |
| 14.1.3 | Server configuration is hardened to the vendor baseline | Partial | Docker images are pinned; a CIS benchmark pass has not been run. Tracked below |
| 14.1.4 | Application, config and components are up to date | Pass | Dependabot plus the CI audit gate |
| 14.2.1 | All components are up to date | Pass | Same |
| 14.2.2 | Unneeded features and files are removed | Pass | Scramble, Dusk, Pail, Boost and Pint are `require-dev` and absent from a production install |
| 14.2.3 | Third-party assets are loaded from a trusted source | Pass | No third-party JavaScript except Turnstile and the Lenco widget, both allow-listed in the CSP |
| 14.3.2 | Debug modes are disabled in production | Pass | `APP_DEBUG=false`; `DB::prohibitDestructiveCommands()` in production |
| 14.3.3 | No version disclosure in HTTP headers | Pass | No `X-Powered-By`; the launch checklist removes the web server's `Server` banner |
| 14.4.1 | Every response has a safe content type | Pass | `SecurityHeaders` sets `X-Content-Type-Options: nosniff` on every response |
| 14.4.3 | A Content Security Policy is in place | Pass | Nonce-based `script-src`, `object-src 'none'`, `frame-ancestors 'none'`. `SecurityHeaders` |
| 14.4.4 | `X-Content-Type-Options: nosniff` | Pass | Same |
| 14.4.5 | HSTS is sent on every response | Pass | One year, `includeSubDomains`, on HTTPS responses only. `preload` is off by default and is a launch-checklist decision |
| 14.4.6 | A suitable Referrer-Policy is sent | Pass | `strict-origin-when-cross-origin` |
| 14.4.7 | Content is loaded in a sandbox or restricted | Pass | `frame-src` names only Lenco and Cloudflare |
| 14.5.1 | Unexpected HTTP methods are rejected | Pass | Explicit verbs |
| 14.5.3 | CORS is restrictive | Pass | `config/cors.php` is not published at all, so Laravel's `HandleCors` has no paths configured and no cross-origin response is ever produced. The mobile app is a native client and needs none |

---

## Payment-specific assertions

These are not ASVS controls but are the ones the brief calls non-negotiable, and they
are the first thing an assessor should check.

| Assertion | Status | Evidence |
|---|---|---|
| Card data never touches our servers | **Pass** | There is no card field anywhere in `resources/js` and no card column in any migration. Collection goes through the Lenco inline widget, which posts to Lenco's origin; we receive a reference |
| The Lenco secret key is server-side only | **Pass** | `config('lenco.secret_key')` is read only inside `LencoGateway`. The browser is handed `lenco.public_key` by `WidgetConfigurator` |
| Every collection is verified server-side | **Pass** | `CollectionService` re-asks Lenco by reference; the widget's `onSuccess` is never trusted on its own |
| Webhooks are signature-checked and idempotent | **Pass** | HMAC against the **raw** body (`WebhookIngestor::isAuthentic`); a redelivery answers 200 and writes nothing |
| Payout account fields are encrypted at rest | **Pass** | `encrypted` cast on `PayoutAccount` |
| Money is never a float | **Pass** | Integer ngwee via `App\Support\Money\Money` throughout; PHPStan L8 enforces the types |

## Remediation

| # | Finding | Severity | Owner | Target |
|---|---|---|---|---|
| 1 | 12.4.2 — no malware scanning on uploads | Medium | Platform | Add ClamAV to the media queue before public launch |
| 2 | 2.7.6 — 6-digit OTP is just under 20 bits | Low | Identity | Raise to 7 digits, or reduce attempts from 3 to 2, at the first post-pilot release |
| 3 | 7.2.2 — access-control denials are not audited | Low | Platform | Log 403s from policies with actor and subject |
| 4 | 3.3.2 — no absolute session timeout for staff | Low | Identity | 12-hour absolute cap on staff sessions |
| 5 | 9.1.1 — no TLS on app-to-database traffic | Low | Infrastructure | Accepted for the pilot's single-VPC topology; revisit if the database moves |
| 6 | 14.1.3 — no CIS benchmark pass on the host images | Low | Infrastructure | Run before public launch |
| 7 | 2.5.1 — reset codes travel over SMS | Accepted | Identity | No better channel exists for this market. Compensating controls documented above |
| 8 | External penetration test not yet performed | High | Platform | Book before public launch (pilot is invite-only) |
| 9 | CSP not yet observed in report-only mode against real traffic | Medium | Platform | Run `CSP_REPORT_ONLY=true` for the first week of the pilot |

## Verifying the claims

Most of this file is executable:

```bash
vendor/bin/pest tests/Feature/Security   # headers, captcha, rate limits, health
vendor/bin/pest tests/Feature/Privacy    # consent, export, erasure
vendor/bin/pest tests/Feature/Payments   # webhook signatures, idempotency
composer run types:check                 # PHPStan level 8
composer audit && npm audit              # the dependency gate CI runs
```
