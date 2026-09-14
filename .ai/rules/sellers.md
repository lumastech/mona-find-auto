---
paths:
  - 'app/Modules/Sellers/**'
---

# Sellers

## Sellers: draft-then-seller wizard, versioned policies, resolved payouts
Sign-up: `SellerRegistrationDraft` holds wizard position and typed answers, but saving step two creates the real `Seller` in `VerificationStatus::Draft` — steps 3-5 attach policies, payout accounts and documents to that row. A Draft seller is invisible to buyers and to the queue. `Role::Seller` is granted at submit (not at verify), so the portal is usable while under review.

Policies are append-only versions: `SellerPolicyService::publish()` always writes a new version and flips `is_current`; there is no update path, because orders record the version their buyer accepted. Republishing identical text is a deliberate no-op.

Payout accounts are never saved unresolved. `PayoutAccountService::add()` calls `PaymentGateway::resolveBankAccount()/resolveMobileMoney()` first and throws `PayoutAccountUnresolved` (controllers turn it into a field-level ValidationException). Columns in `PayoutAccount::ENCRYPTED_COLUMNS` are `encrypted` casts, so they cannot be searched or indexed — `last_four`, `bank_code` and `network` are the clear-text values to query on.

Verification transitions go only through `SellerVerificationService`; legal moves are declared on `VerificationStatus::allowedTransitions()`, and `verify()` refuses without a registration number.

Contact blur has one implementation, `Support\SellerContact`, used by `SellerProfileResource` for both the Inertia page and `/api/v1/sellers`. Masking is server-side — never send the real value and blur it in CSS.

Documents live on the `local` (private) disk in the `documents` media collection, tagged with a `document_type` custom property, and are only readable through the policy-checked admin streaming route.
