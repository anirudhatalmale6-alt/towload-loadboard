#!/bin/bash
# ════════════════════════════════════════════════════════════════════════════
#  NIGHTLY DATABASE BACKUP
#
#  Written the night the database was deleted. The dumps that saved us were
#  ones I happened to take by hand before each migration — which meant the
#  most recent one was a day old, and only luck made that day an empty one.
#
#  Runs from cron. Keeps 14 dailies and one dump per month, so a mistake that
#  goes unnoticed for a week is still recoverable.
#
#      30 4 * * * /home/coxonuo84/towload-ws/nightly-backup.sh >> \
#                 /home/coxonuo84/towload-backups/backup.log 2>&1
#
#  Quiet on success, loud on failure — a backup script that chatters every
#  night trains you to ignore the one night it complains.
# ════════════════════════════════════════════════════════════════════════════
set -uo pipefail

DB="${TL_DB_NAME:-towload}"
HOST="${TL_DB_HOST:-vps63982.towmasterscorp.com}"
USER="coxonuo84"
DIR="$HOME/towload-backups"
PWFILE="$(dirname "$0")/.dbpass"

[ -f "$PWFILE" ] || { echo "$(date '+%F %T') FAIL: no $PWFILE"; exit 1; }
PASS="$(cat "$PWFILE")"
mkdir -p "$DIR"

STAMP="$(date '+%Y%m%d-%H%M%S')"
OUT="$DIR/${DB}-auto-${STAMP}.sql"

# --single-transaction so a tow being booked mid-dump doesn't lock the site,
# and doesn't land half-written in the file either.
if ! mysqldump -h "$HOST" -u "$USER" -p"$PASS" \
        --single-transaction --quick --routines --triggers \
        "$DB" > "$OUT" 2>"$OUT.err"; then
    echo "$(date '+%F %T') FAIL: mysqldump: $(tail -1 "$OUT.err")"
    rm -f "$OUT" "$OUT.err"
    exit 1
fi
rm -f "$OUT.err"

# A dump that ran but produced nothing is the failure mode that hurts most:
# it looks like a backup, it sits in the folder, and it restores an empty
# database. Refuse to keep one.
if ! tail -5 "$OUT" | grep -q "Dump completed"; then
    echo "$(date '+%F %T') FAIL: truncated dump, discarding"
    rm -f "$OUT"
    exit 1
fi
TABLES="$(grep -c '^CREATE TABLE' "$OUT")"
if [ "$TABLES" -lt 20 ]; then
    echo "$(date '+%F %T') FAIL: only $TABLES tables, expected 30+. Kept as $OUT.suspect"
    mv "$OUT" "$OUT.suspect"
    exit 1
fi

gzip -f "$OUT"

# Keep the 1st of the month forever; thin the rest to 14 days.
find "$DIR" -name "${DB}-auto-*.sql.gz" -mtime +14 \
     ! -name "${DB}-auto-????????01-*.sql.gz" -delete

exit 0
