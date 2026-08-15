#!/bin/bash
# Keeps the TowLoad realtime server alive. Run from cron every 5 minutes, the
# same pattern already used for bot24-ws and towmasters-ws on this box:
#
#   */5 * * * * /home/coxonuo84/towload-ws/ensure-running.sh > /dev/null 2>&1
#
# A health check rather than a pm2 status check, because "the process exists"
# and "the process is answering" are different questions and only the second
# one matters.
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"

PORT="${TL_WS_PORT:-3003}"
DIR="$(cd "$(dirname "$0")" && pwd)"

if ! curl -s --max-time 4 "http://127.0.0.1:${PORT}/health" > /dev/null 2>&1; then
    cd "$DIR" || exit 1
    # The secrets live in .env next to this script, never in the repo.
    [ -f "$DIR/.env" ] && set -a && . "$DIR/.env" && set +a
    pm2 restart towload-ws 2>/dev/null || pm2 start server.js --name towload-ws --time
fi
