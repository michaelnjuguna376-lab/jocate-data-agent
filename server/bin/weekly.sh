#!/bin/bash
# Weekly job for Hostinger cron (Mondays 03:00 UTC = 06:00 EAT):
#   /bin/bash /home/u706803934/jocate-agent/server/bin/weekly.sh
# Hostinger disables shell functions in PHP, so git runs here and PHP does the ETL and report.
set -uo pipefail
A="${HOME:-/home/u706803934}/jocate-agent"
LOG="$(dirname "$A")/jocate-private/logs/weekly.log"
mkdir -p "$(dirname "$LOG")"
log() { echo "[$(date -u +%FT%TZ)] $*" >> "$LOG"; }
cd "$A" || { log "cannot cd to $A"; exit 1; }
[ "$(git rev-parse --show-toplevel 2>/dev/null)" = "$A" ] || { log "not the agent repo"; exit 1; }

log "weekly start"
git pull --rebase --autostash -q origin main >> "$LOG" 2>&1 || log "git pull failed (continuing with local rules)"
/usr/bin/php "$A/server/bin/weekly.php" --no-git >> "$LOG" 2>&1 || { log "php weekly failed"; exit 1; }
git add reports
if ! git diff --cached --quiet; then
  V=$(/usr/bin/php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "?";' "$A/rules/rules.json")
  git commit -q -m "Weekly data report $(date -u +%G-W%V) (rules v$V)"
  if git push -q origin main >> "$LOG" 2>&1; then log "pushed report"; else log "git push failed"; exit 1; fi
else
  log "no report changes"
fi
log "weekly done"
