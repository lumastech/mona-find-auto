---
paths:
  - 'app/Integrations/Payments/**'
---

# Integrations Payments

## Lenco client: unknown reference is Pending, reads retry and writes do not
Lenco v2 answers every endpoint in a `{status, message, data}` envelope and signals refusals with `status: false` plus HTTP 400 — `LencoResponse` unwraps both. Amounts are decimal kwacha STRINGS ("1234.56"); convert via `Money::ofKwacha()`/`toDecimalString()` and never through a float.

Status mapping: Lenco has five collection statuses to our four. `pay-offline` (USSD prompt on a handset) and `3ds-auth-required` both mean the customer is still working, so both map to Pending — as does anything unrecognised. Treating them as failures cancels orders that are about to be paid.

An unknown reference on `fetchCollection`/`fetchTransfer` returns **Pending, not Failed**, in BOTH LencoGateway and FakePaymentGateway. It is the normal answer while a widget is open, and for transfers a false "failed" reverts a payable that may really have been paid. The fake must keep mirroring this — a fake that reports Failed lets code pass tests and cancel live orders.

Reads are retried on transport failure; WRITES ARE NOT. A POST /transfers that times out may have paid someone, so retrying it pays twice. A 5xx on a write raises `LencoRequestFailed::unreachable()` (meaning "unknown"), which callers treat differently from a well-formed `status: false` refusal.

Webhook signatures are HMAC-**SHA512** (not 256) over the RAW request body, header `X-Lenco-Signature`, keyed on the webhook hash key (defaults to `hash('sha256', secret_key)`). Verify against `$request->getContent()`, never re-encoded JSON — json_decode/encode does not round-trip byte-for-byte.

Transfers require `accountId` from `lenco.account_id`. Both environments share one REST host (`api.lenco.co/access/v2`) and are told apart by the API token; only the WIDGET host differs (pay.sandbox.lenco.co vs pay.lenco.co). `config/lenco.php` keys both off `lenco.environment` so they cannot be chosen separately.

Live sandbox coverage lives in tests/Feature/Payments/LencoSandboxTest.php, double-gated: excluded via the `lenco-sandbox` group in phpunit.xml and skipped unless `LENCO_SANDBOX_TESTS=true`.
