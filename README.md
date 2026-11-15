# Jocate data agent

This system records every website submission, cleans it (extract, transform, load), drives customer and supplier follow-up, and improves itself every week.

```
Website forms ──► /api/submit.php ──► MySQL (raw submissions)
 (Partner enquiry, Sell to Us,            │  ETL runs immediately on each submission
  Fuel Calculator estimates)              ▼
                               Clean contacts + leads ──► /admin/ (team updates status, notes, next actions)
                                          │
              Monday 06:00 EAT: server/bin/weekly.sh (git) → weekly.php (ETL + report)
                 1. git pull  (gets the agent's latest rules.json)
                 2. ETL with those rules
                 3. reports/YYYY-Www.md + .json  (aggregates only, no personal data)
                 4. git push
                                          ▼
              Monday 08:00 EAT: Data Steward agent (Claude, agent/data-steward.md)
                 follows PLAYBOOK.md → findings/YYYY-Www.md
                 teaches rules/rules.json new aliases and units → server uses them next week
                 records LEARNINGS.md + SKILLS.md, then commits and pushes
```

## What lives where

| Path | Purpose |
|---|---|
| `server/lib/` | ETL, transforms, schema, weekly report (runs on Hostinger, outside the web root) |
| `server/bin/etl.php` | Process new submissions now |
| `server/bin/weekly.sh` | Weekly cron job: git pull rules → `weekly.php --no-git` (ETL + report) → git push |
| `server/bin/weekly.php` | ETL + report (Hostinger's PHP can't run git, so `weekly.sh` wraps it) |
| `server/web/` | Copies of `public_html/api/submit.php` and `public_html/admin/index.php` |
| `rules/rules.json` | Cleaning and engagement rules (the agent edits this) |
| `PLAYBOOK.md`, `SKILLS.md`, `LEARNINGS.md` | The agent's procedure, capabilities and memory |
| `reports/`, `findings/` | Weekly server reports and agent findings |

## Privacy

Names, phone numbers, emails and message text stay in MySQL on the Hostinger server. This repository only ever holds totals and anonymous contact reference numbers. Secrets (admin password hash, salt) live in `~/jocate-private/config.php` on the server, outside the web root and outside git.

## Operations

- Admin page: https://jocateorg.com/admin/
- Run ETL by hand: `php ~/jocate-agent/server/bin/etl.php`
- Weekly job (what cron runs): `/bin/bash ~/jocate-agent/server/bin/weekly.sh`
- Report only, no git (testing): `php ~/jocate-agent/server/bin/weekly.php --no-git`
- Logs: `~/jocate-private/logs/` (etl.log, weekly.log, api.log)
