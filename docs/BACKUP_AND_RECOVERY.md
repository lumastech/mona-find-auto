# Backup and recovery

**Last reviewed:** 13 September 2026.
**Owner:** Infrastructure.

## Targets

| | Target | Why this number |
|---|---|---|
| **RPO** (data we can afford to lose) | **15 minutes** | The window is set by the money. A lost quarter-hour of orders is recoverable from Lenco's own records during reconciliation; a lost hour is a morning of manual work per shop |
| **RTO** (time to be serving again) | **2 hours** | A Zambian parts market is a daytime business. Two hours is an inconvenience; a day is sellers going back to WhatsApp |
| **Retention** | 30 daily, 12 monthly, 7 annual | Ten-year financial retention is served by the annual set plus the ledger's own immutability |

## What is backed up

| What | Method | Frequency | Where |
|---|---|---|---|
| MySQL — full | `mysqldump --single-transaction --routines --triggers --events`, gzipped | Nightly 01:00 Africa/Lusaka | Off-site object storage, server-side encrypted |
| MySQL — binlogs | Continuous binary-log shipping | Continuous | Same bucket, separate prefix. **This is what makes the 15-minute RPO real**; the nightly dump alone would mean a 24-hour RPO |
| Media (private disk) | Object-storage versioning + cross-region replication | Continuous | Seller documents and listing originals |
| Redis | **Not backed up** | — | Deliberate. Redis holds cache, sessions and the queue. Losing it signs everybody out and drops queued jobs; it loses no durable state. See the note below |
| Meilisearch | **Not backed up** | — | Deliberate. The index is derived from MySQL and is rebuilt with `php artisan scout:import` |
| `.env` and secrets | Secret manager, versioned | On change | **Never in the database backup** |

### The two deliberate omissions, in more detail

**Redis.** Nothing durable lives there. Sessions are a convenience, the cache rebuilds
itself, and the queue is the one that looks alarming — but a dropped job means an unsent
notification or an unprocessed webhook, and Lenco redelivers webhooks. Backing up Redis
would mean restoring a queue of jobs whose subjects have since changed, which is worse
than not having them.

**Meilisearch.** The index is a projection. Restoring a stale index would serve buyers
listings that have since sold, which is the failure mode the freshness rules exist to
prevent. Rebuilding takes minutes on a corpus this size.

## `--routines --triggers` is not optional

The dump command carries `--routines --triggers` and the restore drill **fails the run**
if fewer than sixteen triggers come back — two each (`BEFORE UPDATE`, `BEFORE DELETE`) on
eight append-only tables. The count is asserted in
`tests/Feature/Foundation/AppendOnlyProtectionTest.php`, so it stays true as tables are
added.

`audit_logs`, `journal_entries`, `journal_lines`, `terms_acceptances`, `payments`,
`consent_records`, `order_status_events` and `stock_movements` are append-only, and the
second half of that guarantee is a pair of `BEFORE UPDATE` / `BEFORE DELETE` triggers
installed by `App\Support\Database\AppendOnlyTable`. A `mysqldump` without those flags
produces a restore that looks complete and silently has an **editable ledger**.

That is the kind of failure nobody finds until it matters, which is why the drill
asserts it rather than trusting the flag is still in the cron line.

## Restoring

### The whole database

```bash
# 1. Stop the workers first. A worker writing into a half-restored database is
#    a worse problem than a few minutes of extra downtime.
php artisan horizon:terminate
php artisan down --render=errors::503

# 2. Restore the most recent nightly dump.
gunzip -c monafind-2026-09-13.sql.gz | mysql -u root -p monafind

# 3. Replay binlogs from the dump's position to the target moment. This is the
#    step that turns a 24-hour RPO into a 15-minute one.
mysqlbinlog --start-position=<pos-from-dump-header> \
            --stop-datetime="2026-09-13 09:45:00" \
            binlog.0000* | mysql -u root -p monafind

# 4. Bring the schema up to the deployed code.
php artisan migrate --force

# 5. Rebuild the index, which was never backed up.
php artisan scout:import 'App\Modules\Catalog\Models\Product'

# 6. Warm the caches and let traffic back in.
php artisan config:cache && php artisan route:cache
php artisan up
```

### One table, or one row

Do not restore a single table into production directly. Restore the dump into the drill
database (`scripts/restore-drill.sh` creates one), extract what is needed, and apply it
as an ordinary migration or a reviewed script — with an audit row saying who did it and
why.

**The append-only tables cannot be restored selectively at all.** The triggers refuse
`UPDATE` and `DELETE`, which is the point of them. A ledger that needs correcting is
corrected by posting a reversing entry, never by editing history — see
`LedgerAdjustmentService`.

## The drill

`scripts/restore-drill.sh` runs the whole thing end to end against a scratch database
and asserts the result is usable rather than merely present:

- The newest dump is less than 48 hours old — **catches a backup job that has stopped**,
  which a restore test alone would not.
- Every critical table restored.
- At least sixteen triggers restored — the append-only guarantee survived.
- **Every journal entry sums to zero across its lines.** The one assertion that says the
  restored data is coherent.
- No order references a user that did not survive.
- `php artisan migrate` succeeds against it — a restore of yesterday's data into today's
  code is the actual disaster scenario.

```bash
BACKUP_DIR=/var/backups/monafind scripts/restore-drill.sh
```

**Cadence: monthly, and after any change to the schema, the dump command or the storage
backend.** Record each run in the table below. A drill that has not been run in three
months is a backup nobody has tested.

### Drill log

| Date | Dump | Restore time | Result | Run by |
|---|---|---|---|---|
| _(first production drill is a line on `docs/LAUNCH.md`)_ | | | | |

## Monitoring

The backup is monitored in three places, because each catches a different failure:

| Signal | Catches | Where |
|---|---|---|
| Backup job exit code | The job failing | Cron alert to `ALERT_RECIPIENTS` |
| Dump age check in the drill | The job silently not running | `scripts/restore-drill.sh` |
| `/health` deep check | The database being unreachable at all | `App\Support\Health\HealthReport` |

A backup job that fails loudly is a good day. The one worth engineering against is the
job that stopped running six weeks ago and told nobody, which is why the age check fails
the drill rather than warning.

## What a restore does not fix

- **Money already moved.** A restore rolls back our record of a payout, not the payout.
  After any restore that crosses a payout run, reconcile against Lenco before releasing
  anything further: `php artisan queue:work --once` on a `ReconcileGatewayDay` for each
  affected day, then work the exceptions in `/admin/reconciliation`.
- **Notifications already sent.** People have the emails and texts.
- **Erasures already carried out.** Restoring a backup from before an erasure would
  **reinstate personal data somebody exercised their right to have removed**, which is a
  fresh breach rather than a recovery. Any restore that crosses a completed
  `erasure_requests` row must be followed by re-running the erasure for every request
  completed in the restored window:

  ```bash
  php artisan tinker --execute '
      App\Modules\Privacy\Models\ErasureRequest::query()
          ->where("status", "completed")
          ->where("completed_at", ">=", "2026-09-13 00:00:00")
          ->each(fn ($request) => app(App\Modules\Privacy\Services\AccountEraser::class)->erase($request->user));
  '
  ```

  This is the step most likely to be forgotten and the one with legal consequences, so
  it is also a line on the disaster-recovery checklist rather than only here.
