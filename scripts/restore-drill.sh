#!/usr/bin/env bash
#
# Restore drill — prove the backups are restorable, on a schedule.
#
# A backup nobody has restored is a hypothesis. This script turns it into a
# fact by doing the whole thing end to end: take the most recent dump, load it
# into a scratch database, run the migrations against it, and assert that the
# ledger still balances.
#
# That last step is the one that matters and the one a generic restore script
# would leave out. A restore that produces a database the application cannot
# use, or one whose journal lines no longer sum to zero, has failed even
# though mysql exited 0.
#
# Usage:
#   scripts/restore-drill.sh [path/to/dump.sql.gz]
#
# With no argument it takes the newest dump in $BACKUP_DIR.
#
# Exits non-zero on the first failure, which is what makes it usable from cron
# or from CI.

set -Eeuo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/monafind}"
DRILL_DB="${DRILL_DB:-monafind_restore_drill}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"

# shellcheck disable=SC2016
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

log()  { printf '\033[0;36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[0;32m  ok\033[0m %s\n' "$*"; }
fail() { printf '\033[0;31m FAIL\033[0m %s\n' "$*" >&2; exit 1; }

mysql_args=(--host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME")
[[ -n "$DB_PASSWORD" ]] && mysql_args+=(--password="$DB_PASSWORD")

cleanup() {
    log "Dropping the drill database"
    mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${DRILL_DB}\`;" || true
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# 1. Find a dump
# ---------------------------------------------------------------------------

DUMP="${1:-}"

if [[ -z "$DUMP" ]]; then
    log "Looking for the newest dump in ${BACKUP_DIR}"
    DUMP="$(find "$BACKUP_DIR" -name '*.sql.gz' -type f -print0 2>/dev/null \
        | xargs -0 ls -t 2>/dev/null | head -n1 || true)"
fi

[[ -n "$DUMP" && -f "$DUMP" ]] || fail "No dump found. Pass one as an argument or set BACKUP_DIR."

# A dump older than two days means the backup job has stopped and nobody
# noticed — which the drill should catch even though the restore itself would
# have succeeded.
DUMP_AGE_HOURS=$(( ( $(date +%s) - $(stat -c %Y "$DUMP") ) / 3600 ))

log "Using ${DUMP} (${DUMP_AGE_HOURS}h old, $(du -h "$DUMP" | cut -f1))"

if (( DUMP_AGE_HOURS > 48 )); then
    fail "The newest dump is ${DUMP_AGE_HOURS}h old. The backup job has stopped."
fi

ok "Dump is recent"

# ---------------------------------------------------------------------------
# 2. Restore it into a scratch database
# ---------------------------------------------------------------------------

log "Creating ${DRILL_DB}"
mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${DRILL_DB}\`; CREATE DATABASE \`${DRILL_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

log "Restoring — this is the step being tested"
START=$(date +%s)
gunzip -c "$DUMP" | mysql "${mysql_args[@]}" "$DRILL_DB"
RESTORE_SECONDS=$(( $(date +%s) - START ))

ok "Restored in ${RESTORE_SECONDS}s"

# ---------------------------------------------------------------------------
# 3. Assert the restored database is one the application can actually use
# ---------------------------------------------------------------------------

query() {
    mysql "${mysql_args[@]}" --database="$DRILL_DB" --skip-column-names --batch -e "$1"
}

log "Checking the schema arrived"

TABLES=$(query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DRILL_DB}';")
(( TABLES > 40 )) || fail "Only ${TABLES} tables restored. The dump is truncated."
ok "${TABLES} tables"

for table in users orders payments journal_entries journal_lines audit_logs terms_acceptances consent_records; do
    EXISTS=$(query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DRILL_DB}' AND table_name='${table}';")
    [[ "$EXISTS" == "1" ]] || fail "Table ${table} is missing from the restore."
done
ok "Every critical table is present"

# The append-only guarantee is enforced by triggers, and mysqldump only
# includes them with --routines --triggers. A restore that silently dropped
# them would leave the ledger editable — which is the failure this check
# exists to catch.
log "Checking the append-only triggers survived"

TRIGGERS=$(query "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = '${DRILL_DB}';")
(( TRIGGERS >= 16 )) || fail "Only ${TRIGGERS} triggers restored (expected 16 — two each on eight append-only tables). Add --routines --triggers to the dump command; the ledger is editable without them."
ok "${TRIGGERS} triggers"

log "Checking the ledger balances"

# Every journal entry must sum to zero across its lines. This is the single
# assertion that says the restored data is coherent rather than merely present.
UNBALANCED=$(query "
    SELECT COUNT(*) FROM (
        SELECT journal_entry_id
        FROM journal_lines
        GROUP BY journal_entry_id
        HAVING SUM(CASE WHEN direction = 'debit' THEN amount_ngwee ELSE -amount_ngwee END) <> 0
    ) AS unbalanced;
")

[[ "$UNBALANCED" == "0" ]] || fail "${UNBALANCED} journal entries do not balance in the restored database."
ok "Every journal entry balances"

ORPHANS=$(query "SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE u.id IS NULL;")
[[ "$ORPHANS" == "0" ]] || fail "${ORPHANS} orders reference a user that did not survive the restore."
ok "No orphaned orders"

# ---------------------------------------------------------------------------
# 4. Assert the application can migrate it
# ---------------------------------------------------------------------------
#
# A restore of yesterday's data into today's code is the actual disaster
# scenario, so the drill runs the migrations the same way a deploy would.

log "Running migrations against the restored database"

(
    cd "$APP_DIR"
    DB_DATABASE="$DRILL_DB" php artisan migrate --force --no-interaction
) || fail "Migrations failed against the restored database."

ok "Migrations applied"

# ---------------------------------------------------------------------------
# 5. Report
# ---------------------------------------------------------------------------

USERS=$(query "SELECT COUNT(*) FROM users;")
ORDERS=$(query "SELECT COUNT(*) FROM orders;")
ENTRIES=$(query "SELECT COUNT(*) FROM journal_entries;")

cat <<REPORT

  Restore drill passed
  --------------------
  Dump:            ${DUMP}
  Dump age:        ${DUMP_AGE_HOURS}h
  Restore time:    ${RESTORE_SECONDS}s
  Tables:          ${TABLES}
  Triggers:        ${TRIGGERS}
  Accounts:        ${USERS}
  Orders:          ${ORDERS}
  Journal entries: ${ENTRIES}

  Record this run in docs/BACKUP_AND_RECOVERY.md.

REPORT
