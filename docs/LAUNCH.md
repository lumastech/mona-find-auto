# Launch checklist

**Target:** MonaFindAuto pilot, Zambia.
**Rule:** every box is ticked by a named person on a date, or the launch does not happen.
A checklist ticked from memory is a list of things somebody believes.

---

## 1. Payments — Lenco

The highest-consequence section. Everything here moves real money.

- [ ] **Live API keys issued** and stored in the secret manager, never in `.env` in the repo.
- [ ] `LENCO_ENVIRONMENT=live`. **This one switch moves both the API host and the widget
      host.** They cannot be set separately by design — a sandbox widget talking to a
      live API is a class of bug made unrepresentable (`IntegrationServiceProvider::lenco()`).
- [ ] `LENCO_SECRET_KEY` set, and **verified absent from every client bundle**:
      `grep -r "$(echo $LENCO_SECRET_KEY)" public/build/` returns nothing.
- [ ] `LENCO_PUBLIC_KEY` set — this is the only key the browser gets.
- [ ] `LENCO_ACCOUNT_ID` set to the live settlement account.
- [ ] **Sandbox keys rotated or revoked** so a stale deployment cannot take orders that
      collect no money.
- [ ] **Webhook URL registered** with Lenco: `https://<domain>/webhooks/lenco`.
- [ ] `LENCO_WEBHOOK_SECRET` matches what Lenco was given. Signature verification is
      against the **raw** body; a mismatch rejects every webhook with a 401 and the
      symptom is orders that never leave pending.
- [ ] **A test webhook delivered and accepted** — check `lenco_webhook_events` for the row.
- [ ] **A redelivery of the same event answers 200 and writes nothing.** Idempotency is
      the property that stops a retry storm double-crediting an order.
- [ ] `payments.fee_bearer` set in admin to whichever side is bearing Lenco's fee.
- [ ] **One real end-to-end collection** of a small amount, verified server-side by
      reference, appearing in the ledger and reconciling the next morning.
- [ ] **One real payout** to a staff-owned account, confirmed received.

## 2. Secrets and configuration

- [ ] `APP_KEY` generated fresh for production. **Never copied from staging** — a shared
      key means staging can decrypt production's payout account numbers.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `APP_URL` set to the canonical HTTPS URL.
- [ ] `APP_VERSION` stamped by the deploy pipeline, so `/health` can answer "is the fix live".
- [ ] **`SESSION_SECURE_COOKIE=true`.** Left unset the session cookie is sent over plain
      HTTP too, which makes every other session control decorative.
- [ ] `SESSION_SAME_SITE=lax`.
- [ ] Every secret in the secret manager, not in a file on the box.
- [ ] `php artisan config:cache route:cache view:cache` in the deploy, and **`config:clear`
      is not** run afterwards by a stray script.

## 3. Security

- [ ] `CSP_ENABLED=true`.
- [ ] **`CSP_REPORT_ONLY=true` for the first week**, with `CSP_REPORT_URI` collecting.
      Turn enforcement on once the reports are quiet. This is item 9 on the ASVS
      remediation list.
- [ ] `HSTS_ENABLED=true`. **Leave `HSTS_PRELOAD=false`** until the domain has run on
      HTTPS without incident for a month — preload submission is close to irreversible.
- [ ] **Turnstile live keys**: `CAPTCHA_DRIVER=turnstile`, `TURNSTILE_SITE_KEY`,
      `TURNSTILE_SECRET_KEY`, and `TURNSTILE_HOSTNAME` set to the production domain.
- [ ] Confirm the challenge actually renders on `/register` and `/forgot-password/sms`.
- [ ] Decide `CAPTCHA_FAIL_OPEN`. **The default is open** — a Cloudflare outage should not
      close MonaFind's registration and password-reset forms. Change it only deliberately.
- [ ] `HEALTH_CHECK_TOKEN` set. Without it `/health` names every dependency and which of
      them are broken, to anybody who asks.
- [ ] **Admin accounts created and enrolled in 2FA.** `moderator`, `finance` and
      `platform-admin` are held at the enrolment screen everywhere until they are, so
      this cannot be skipped — but do it before launch day rather than during it.
- [ ] Recovery codes for each staff account stored somewhere the whole team is not.
- [ ] Staff invited through `StaffInvitation` only. There is no other route into a role.
- [ ] **External penetration test booked.** ASVS remediation item 8. The pilot is
      invite-only, which is what makes launching before it defensible.
- [ ] `composer audit --no-dev` and `npm audit --omit=dev` clean on the deployed lockfiles.

## 4. Infrastructure

- [ ] TLS certificate issued and auto-renewing. Renewal **tested**, not assumed.
- [ ] TLS 1.2 minimum; weak ciphers disabled.
- [ ] DNS A/AAAA records pointed, TTL lowered ahead of the cutover and raised after.
- [ ] `www` and apex both resolve, one redirecting to the other.
- [ ] The web server's `Server` banner removed.
- [ ] **Horizon running under a process supervisor** and restarting on deploy.
- [ ] The scheduler running (`php artisan schedule:run` every minute). Without it stock
      freshness never ages, escrow never auto-completes, payouts are never batched,
      reconciliation never runs and erasures never happen.
- [ ] Redis persistence configured, or accepted as a cache — see `docs/BACKUP_AND_RECOVERY.md`.
- [ ] **Meilisearch reachable, and the index built**: `scout:sync-index-settings` then
      `scout:import`. An unsynced index filters silently and wrongly.
- [ ] `php artisan storage:link`.
- [ ] Log rotation configured. `laravel.log` reached 15 MB in development alone.

## 5. Integrations

- [ ] **Google Maps API key restricted** — by HTTP referrer for the browser key, and to
      the Geocoding and Distance Matrix APIs only. An unrestricted Maps key is a bill
      somebody else can run up.
- [ ] Maps billing alerts set.
- [ ] **Zamtel sender ID registered.** An unregistered ID is silently replaced by the
      network with a short code, which is how a platform ends up sending verification
      codes that look like spam. Eleven characters maximum.
- [ ] `SMS_PROVIDER=zamtel`, credentials set, **one real SMS received on a real handset**.
- [ ] Mail transport configured, SPF/DKIM/DMARC published, **one real email received and
      not in spam**.
- [ ] `ALERT_RECIPIENTS` set to addresses people actually read. Empty means alerts are
      logged and nobody is told.
- [ ] Trigger one deliberate failed job and confirm the alert arrives.

## 6. Data

- [ ] **Reference data seeded**: provinces, cities, makes, vehicle models, part
      categories, mechanic specialities, VAT rates, the chart of accounts.
- [ ] `SettingsSeeder` run; escrow windows, freshness thresholds, ranking weights,
      commission defaults and reserve percentage reviewed by whoever owns the numbers —
      **not left at the developer's defaults**.
- [ ] Content pages published: About, FAQ, Contact, Terms, Privacy.
- [ ] **The privacy notice reviewed against `docs/DATA_RETENTION.md`.** The retention
      periods there are the ones the application actually enforces; if the published
      notice says something else, the notice is untrue.
- [ ] `privacy.controller_contact` set to a monitored address.
- [ ] **No development seeders in production.** `DatabaseSeeder` guards them with
      `app()->environment('local')` — confirm the deployed environment is not `local`.
- [ ] **No load-test corpus in production.** `LoadTestSeeder` refuses to run there;
      confirm no `Load Test Motors` shop exists.

## 7. Backups

- [ ] Nightly dump running, with **`--routines --triggers`**. Without those flags the
      restore has an editable ledger — see `docs/BACKUP_AND_RECOVERY.md`.
- [ ] Binary log shipping running. This is what makes the 15-minute RPO real.
- [ ] Off-site, encrypted, with a retention policy applied.
- [ ] **`scripts/restore-drill.sh` run once against production backups, and the result
      recorded in the drill log.** A backup nobody has restored is a hypothesis.
- [ ] Backup failure alerts wired to `ALERT_RECIPIENTS`.

## 8. Performance

- [ ] SSR running for the storefront.
- [ ] `npm run build:ssr` output deployed.
- [ ] OPcache enabled and sized.
- [ ] **Load test run against the pilot instance** and the figures recorded in
      `docs/LOAD_TESTING.md`. Targets: search p95 < 300 ms, checkout p95 < 500 ms.
- [ ] Decide `STOREFRONT_SHARED_CACHE_SECONDS`. **It is 0 by default.** Setting it marks
      guest pages `public`, which is a promise that the CDN strips session cookies from
      guest requests and refuses to store responses carrying `Set-Cookie`. Do not set it
      until somebody has checked that the CDN does both.

## 9. Accessibility and compatibility

- [ ] `php artisan dusk --filter=AccessibilityTest` green against the deployed build.
- [ ] Checked on a low-end Android at 360 px wide, on a real 3G connection.
- [ ] Keyboard-only pass through search → listing → cart → checkout.

## 10. Monitoring

- [ ] `/up` wired to the load balancer's liveness probe.
- [ ] `/health` wired to alerting — it answers **503** when something critical is down,
      which is the part infrastructure reads.
- [ ] Horizon dashboard reachable by `platform-admin` only.
- [ ] Alerts confirmed arriving for: a failed job, a payment failure, a reconciliation
      exception.
- [ ] Error tracking receiving events.
- [ ] Uptime monitoring from outside the VPC.

---

## Go / no-go

Launch proceeds only when **every** line below is true. These are not weighted; any one
of them false is a no-go.

| # | Criterion | Evidence |
|---|---|---|
| 1 | CI green on the release commit | Build number |
| 2 | One real payment collected, verified server-side, and reconciled | Order number and reconciliation run |
| 3 | One real payout sent and received | Payout batch id |
| 4 | A webhook delivered, accepted, and its redelivery ignored | `lenco_webhook_events` rows |
| 5 | Restore drill passed against production backups | Drill log entry |
| 6 | Load-test targets met on the pilot instance | `docs/LOAD_TESTING.md` |
| 7 | axe passes on home, search, listing and checkout | Dusk run |
| 8 | Every staff account enrolled in 2FA | Staff list |
| 9 | Privacy notice published and agreeing with the retention schedule | Page version |
| 10 | An SMS and an email received on real devices | Screenshots |
| 11 | Alerts confirmed arriving from a deliberately failed job | The alert |
| 12 | Rollback rehearsed | Timing from the rehearsal |

### Explicitly accepted for the pilot

Recorded so nobody discovers them later and assumes they were missed:

| Accepted | Why it is acceptable for an invite-only pilot | Before public launch |
|---|---|---|
| No external penetration test | Invite-only, known participants | **Required** |
| No malware scanning on uploads | Nothing uploaded is executed; `nosniff` everywhere | Required |
| No TLS on app-to-database traffic | Single private VPC | Revisit if the database moves |
| Retention sweeps not automated | Erasure-on-request works; time-based expiry is manual | Required |
| Seller account erasure is manual | Buyers, mechanics and staff are automated | Required |
| No DPIA | Below the Act's large-scale threshold at pilot size | **Required** |

---

## Rollback

Rehearse this before launch day, and time it.

1. `php artisan down --render=errors::503`
2. Deploy the previous release tag.
3. **Do not roll migrations back by default.** Most are additive and the previous release
   tolerates them. Rolling back a migration that dropped a column loses the data in it.
4. `php artisan up`, then confirm `/health` answers 200.
5. **If the rollback crosses a payment window, reconcile before releasing anything
   further.** A rollback undoes our record of a payout, not the payout.

## First hour after launch

- Watch `/admin/reconciliation` for the first collection.
- Watch Horizon's failed queue.
- Watch the first buyer complete checkout end to end, personally.
- Keep `php artisan pail` open.
