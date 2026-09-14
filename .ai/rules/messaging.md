---
paths:
  - 'app/Modules/Messaging/**'
---

# Messaging

## Messaging: notifications declare WHAT, the router decides WHERE
A notification never writes its own `via()`. It implements `Contracts\PlatformNotification` (returning one `NotificationEvent` case) and uses `Concerns\DeliversByPreference`, which asks `NotificationRouter`. Three filters in order: the admin matrix (a ceiling, `settings('notifications.matrix')`), then the recipient's `notification_preferences` opt-outs UNLESS `NotificationEvent::isMandatory()`, then reachability (verified phone for SMS, an address for mail, a User for the bell). A preference can only ever narrow the matrix, never widen it.

SMS is a channel (`Channels\SmsChannel` → `SmsProvider`), not a hand-rolled queued job — the old `SendOtpMessage`/`SendStockSms` pattern is gone, because a job that resolves SmsProvider itself is a delivery nobody can configure. A notification whose event defaults to SMS MUST define `toSms()`: the channel returns silently when it is missing, so the failure is invisible in production. `NotificationCatalogueTest` asserts every channel has its copy — keep it passing rather than working around it.

`$tries` lives on the trait as a `#[Tries]` ATTRIBUTE, not a property: two classes in one composition declaring the same public property is a PHP fatal error. Override with your own `#[Tries(n)]` (see `OtpNotification`).

## Messaging: threads are deduped by key, screened pre-payment, and staff-readable only via a dispute
`ThreadService::openFor()`/`openWith()` are idempotent — one conversation per subject per group of people, guaranteed by the unique `message_threads.dedupe_key` (sha256 of morph class + id + sorted user ids), NOT by the lookup. A simultaneous second press raises `UniqueConstraintViolationException` and the loser reads the winner's row.

Redaction is per thread, not per platform. `MessageThread::allowsContactDetails()` delegates to the subject's `ThreadSubject` resolver; only `OrderThreadSubject` ever returns true, on `paid_at` (not status — a refund does not un-pay an order). Before that, `App\Support\Content\ContentScreen` strips numbers/emails/links and the stored body is the redacted one; the original is never kept. A flagged message is DELIVERED — holding a conversation for a word list breaks the conversation. Anti-disintermediation is deliberately NOT implemented.

`MessageThreadPolicy` is the only authorisation: participants read and write; staff may READ (never write) a thread whose subject is an Order with a dispute row. There is no admin route that lists threads or takes a thread id — the only door is `admin/disputes/{dispute}/thread`.

A new kind of conversable thing is a `ThreadSubject` implementation registered in `MessagingServiceProvider`, never a branch in `ThreadService` (the pattern Ratings uses for `RatingSourceResolver`).
