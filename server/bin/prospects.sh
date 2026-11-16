#!/bin/bash
# Friday job (Hostinger cron, Fridays 07:00 UTC = 10:00 EAT, after the agent's 04:00 UTC run):
#   /bin/bash /home/u706803934/jocate-agent/server/bin/prospects.sh
# Pulls the Potential Partners agent's work, loads it into MySQL and emails the weekly findings.
set -uo pipefail
A="${HOME:-/home/u706803934}/jocate-agent"
LOG="$(dirname "$A")/jocate-private/logs/prospects.log"
mkdir -p "$(dirname "$LOG")"
log() { echo "[$(date -u +%FT%TZ)] $*" >> "$LOG"; }
cd "$A" || { log "cannot cd to $A"; exit 1; }
[ "$(git rev-parse --show-toplevel 2>/dev/null)" = "$A" ] || { log "not the agent repo"; exit 1; }
log "prospects start"
git pull --rebase --autostash -q origin main >> "$LOG" 2>&1 || log "git pull failed (using local copy)"
/usr/bin/php "$A/server/bin/prospects.php" "$@" >> "$LOG" 2>&1 || { log "php prospects failed"; exit 1; }
log "prospects done"
