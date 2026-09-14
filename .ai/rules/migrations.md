---
paths:
  - 'database/migrations/**'
---

# Migrations

## Append-only tables need both the trait and the DB trigger
AuditLog, JournalEntry, JournalLine, TermsAcceptance and Payment are append-only. Two layers, both required:

- Model: `use App\Support\Database\AppendOnly` — throws ImmutableRecordException on update/delete.
- Migration: `AppendOnlyTable::protect('table')` in up() and `::unprotect()` in down() — installs BEFORE UPDATE/DELETE triggers so raw queries cannot slip past. MySQL DDL goes through `PDO::exec` because MySQL will not prepare CREATE TRIGGER; SQLite and Postgres use DB::statement.

Verified working on both SQLite (tests) and MySQL/MariaDB.

## Declare a forward-referencing FK in the later migration
SQLite does not enforce a foreign key against a table that does not exist yet; MySQL does. So a `constrained()` pointing at a table created by a LATER migration passes the whole test suite and fails `migrate:fresh` in CI and production.

This was real: `cart_items` (005200) constrained `quotation_id` against `quotations` (005300).

The fix pattern: declare the bare column (`unsignedBigInteger('quotation_id')->nullable()`) in the earlier migration, and add `Schema::table(...)->foreign(...)` at the end of the migration that creates the referenced table — dropping it first in that migration's `down()`, because MySQL will not drop a table another still references.
