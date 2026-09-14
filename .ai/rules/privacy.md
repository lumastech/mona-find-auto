---
paths:
  - 'app/Modules/Privacy/**'
---

# Privacy

## Privacy: an erased account is anonymised, never deleted
The `users` row survives erasure holding tombstones from `Support\Anonymiser`, keeping its id. This is forced by the schema, not chosen: almost every table referencing `users` does so with `cascadeOnDelete` (orders and terms_acceptances included), so deleting the row would take the financial and legal records with it — and the append-only triggers on `terms_acceptances` would abort the cascade half-way.

Tombstones carry the account id (`erased-91@erased.invalid`) because `email` and `phone` are unique columns; a literal placeholder would collide on the second erasure.

Privacy never reads another module's models. Each module implements `Contracts\PersonalDataSource` (export + erase) and registers it with `Support\PersonalDataRegistry` from its own service provider; modules that can be mid-transaction register an `ErasureBlocker` with `Services\ErasureGuard`. Adding personal data to a module means editing that module's source, never this one.

`tests/Feature/Privacy/AccountErasureTest.php` is the contract: PII gone, ledger balanced, `terms_acceptances` byte-for-byte intact.
