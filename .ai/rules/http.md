---
paths:
  - 'app/Http/**'
---

# Http

## Every /api/v1 endpoint answers in the ApiResponse envelope
Return `App\Http\Api\ApiResponse::ok()/created()/paginated()/error()` — never a bare array or resource. Success is `{"data": ..., "meta": {...}}`, failure is `{"error": {"code", "message", "details"}}`.

`App\Http\Api\ApiExceptionRenderer` (wired in bootstrap/app.php) maps every exception under `api/*` into that error shape, so controllers should not catch and format exceptions themselves. The `api.v1` middleware group forces a JSON Accept header and applies the `api` rate limiter defined in AppServiceProvider.

Areas are gated by middleware groups `seller` and `admin` (web + auth + verified + role check + DisableSsr). SSR runs on the storefront only.
