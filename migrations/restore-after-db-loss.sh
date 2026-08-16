#!/bin/bash
# ════════════════════════════════════════════════════════════════════════════
#  RESTORE AFTER THE DATABASE WAS DELETED  (2026-08-15)
#
#  The `towmasters` database — which despite its name held TowLoad, not
#  TowMasters — stopped existing. MySQL answers 1049 "Unknown database" to both
#  the live site and the shell, so it was removed rather than corrupted.
#
#  This puts it back from the last dump taken before migration 009, then
#  re-applies 009 on top. 009 only inserts settings rows and is safe to re-run,
#  so the pair is equivalent to the state the database was in when it vanished.
#
#  Rehearsed end to end against a scratch mysqld before being written here:
#  32 tables, 4 users, 13 accounts, 22 calls, 6 legal documents, 15 pricing
#  rules, 59 settings, VAPID keypair intact.
#
#  Usage:  ./restore-after-db-loss.sh <dbname> [dbhost]
#  Refuses to touch a database that already has tables in it, because the one
#  thing worse than this outage is overwriting the recovery someone else made.
# ════════════════════════════════════════════════════════════════════════════
set -euo pipefail

DB="${1:?usage: $0 <dbname> [dbhost]}"
HOST="${2:-vps63982.towmasterscorp.com}"
USER="coxonuo84"
DUMP="$HOME/towload-backups/towmasters-pre009-20260814-175534.sql"
MIG="$HOME/towload-ws/009_realtime.sql"

# The password lives beside this script, mode 600, never in the repo. It is
# read from a file rather than passed on the command line so it stays out of
# the process list and out of shell history.
PWFILE="$(dirname "$0")/.dbpass"
[ -f "$PWFILE" ] || { echo "missing $PWFILE"; exit 1; }
PASS="$(cat "$PWFILE")"

m() { mysql -h "$HOST" -u "$USER" -p"$PASS" "$@" 2>&1 | grep -v '^mysql: \[Warning\]'; }

echo "→ checking $DB on $HOST is reachable and empty"
COUNT="$(m "$DB" -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'")"
if [ "$COUNT" != "0" ]; then
    echo "REFUSING: $DB already has $COUNT tables. Inspect it before restoring."
    exit 1
fi

echo "→ restoring $(basename "$DUMP")"
mysql -h "$HOST" -u "$USER" -p"$PASS" "$DB" < "$DUMP"

echo "→ applying 009_realtime.sql"
mysql -h "$HOST" -u "$USER" -p"$PASS" "$DB" < "$MIG"

# The realtime server is already running with this secret in its .env. Setting
# the same value here is what lets PHP talk to it; a mismatch is a silent 403
# on every publish, which looks exactly like "realtime doesn't work".
echo "→ pairing the realtime secret"
SECRET="$(grep '^TL_INTERNAL_SECRET=' "$HOME/towload-ws/.env" | cut -d= -f2)"
m "$DB" -e "INSERT INTO platform_settings (setting_key, setting_value)
            VALUES ('realtime_internal_secret', '$SECRET'),
                   ('realtime_internal_url', 'http://127.0.0.1:3003')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"

echo
echo "→ verification"
m "$DB" -N -B -e "
  SELECT 'tables',   COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'
  UNION ALL SELECT 'users',             COUNT(*) FROM users
  UNION ALL SELECT 'accounts',          COUNT(*) FROM accounts
  UNION ALL SELECT 'calls',             COUNT(*) FROM calls
  UNION ALL SELECT 'legal_documents',   COUNT(*) FROM legal_documents
  UNION ALL SELECT 'pricing_rules',     COUNT(*) FROM pricing_rules
  UNION ALL SELECT 'platform_settings', COUNT(*) FROM platform_settings
  UNION ALL SELECT 'admin_users',       COUNT(*) FROM admin_users"

echo
echo "Done. If the database name changed, update TL_DB_NAME in"
echo "  /home/dh_5mmq3e/bot24.io/towload/config/env.php"
echo "before the site will come back."
