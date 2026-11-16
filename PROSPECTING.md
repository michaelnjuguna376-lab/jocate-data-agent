# Potential Partners: weekly procedure (standard operating procedure)

The procedure the Potential Partners agent follows every **Friday**. It finds and keeps up to date a list of companies that could buy Jocate's boiler fuel. On the same Friday the web server loads the results into the database (admin → Potential partners) and emails the weekly findings to the company address.

## Mission
1. Build a verified, cited pipeline of industrial buyers in Jocate's demand hotspots and near its supply belts.
2. Keep it current: refresh existing prospects with news, tenders and fuel-switch signals.
3. Get better at prospecting every week, and write down what works.

## Hard rules
- **Company-level information only.** No personal names, personal phones or personal emails. Public company websites, procurement portals and general enquiry pages are fine.
- **Research only.** Never contact companies, submit forms or register on portals.
- **Cite everything.** Every key claim needs an `evidence` entry with URL, source and date. No invented facts, figures or locations.
- **Don't prospect existing clients or Synresins** (listed in `knowledge/market-brief.md`). News about them may go under "Account signals".
- **Don't edit `server/`.** Propose changes in the report instead.

## Weekly procedure
1. **Sync and read.** Pull `main`. Read `knowledge/` in full, `prospects/coverage.json`, `prospects/prospects.json`, the previous report in `prospects/reports/`, and the "Prospecting" entries in `LEARNINGS.md`.
2. **Plan.** Choose this week's 2 hotspots (next in the rotation) and 2–3 sectors. Write the query plan (see the `prospect-research` skill).
3. **Discover.** Research and add **5–10 new prospects** that score 35 or more, with evidence.
4. **Refresh.** Re-check the **5 oldest** `last_reviewed` prospects and any scoring 75+ for news (expansion, fuel switch, tenders, closure). Update fields, evidence and scores. Set `research_status` to `closed` if a company has shut or already has a long-term biomass contract.
5. **Watch signals.** Search for this week's biomass, firewood or briquette tenders and fuel-switch news across Kenya, and record them in the report even when the company isn't a prospect yet.
6. **Score and check.** Apply `knowledge/scoring.md`. Remove duplicates by `id`. Run `python3 prospects/validate.py` and fix every error.
7. **Update coverage.** Set `last_hotspots`, `last_sectors`, `last_run` and per-hotspot counts in `prospects/coverage.json`.
8. **Write the report:** `prospects/reports/YYYY-Www.md` (ISO week of the Friday run), using the template below. Then update `prospects/reports/LATEST.md` to link to it. This file is what gets emailed.
9. **Learn.** Append a dated "Prospecting" entry to `LEARNINGS.md` covering which queries and sources found good prospects, which wasted time, and the plan for next week. Add a row to `SKILLS.md` if you gained a new repeatable capability.
10. **Commit and push:** `Potential partners YYYY-Www: <n> new, <m> refreshed`.

## Report template
```markdown
# Potential partners: YYYY-Www
**This week's focus:** <hotspots> · <sectors>
**Pipeline:** <total> prospects (<priority> priority, <strong> strong, <watch> watch) · <n> new · <m> refreshed

## Top 5 to approach next
| Company | Sector | Location (hub) | Score | Why now | Suggested angle |
(the suggested angle links the relevant Jocate Insights article, e.g. https://jocateorg.com/insights/fuel-shock-2026)

## New prospects this week
## Updates to existing prospects
## Market signals: tenders, fuel switches, expansions
## Account signals (existing clients and Synresins: news only)
## Verify before approaching (low confidence)
## What I learned and next week's plan
```
