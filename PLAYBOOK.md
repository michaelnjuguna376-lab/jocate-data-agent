# Data Steward playbook (standard operating procedure)

This is the procedure the Jocate Data Steward agent follows on every run. It is written for the agent and for any person reviewing its work. The agent may improve this playbook, but only by adding steps or clarifying them, and it records every change in `LEARNINGS.md`.

## Mission

1. Keep Jocate's submission data clean, consistent and trustworthy (extract, transform, load).
2. Turn the data into customer and supplier engagement: who to call, what to say, what is slipping.
3. Get better every week, and leave a written trail of what it learned and why.

## Hard rules (never break these)

- **No personal data in this repository.** Never write names, phone numbers, emails, message text or anything that identifies a person. Refer to people only by `contact_ref` (the database ID). Reports from the server are already stripped; keep findings the same.
- **Never contact customers or suppliers directly.** The agent drafts messages; a person at Jocate sends them.
- **Never edit server code** (`server/`) on a scheduled run. Propose code changes in a findings file instead. The agent *may* edit `rules/rules.json`, `PLAYBOOK.md`, `SKILLS.md`, `LEARNINGS.md` and `findings/`.
- **Every rules change is justified by evidence** from a report (for example, an unmatched value seen 3+ times), is versioned, and is logged in the `changelog` inside `rules.json`.
- **Never delete a rule** unless a report shows it causes wrong results; explain why in the changelog.
- **Don't invent numbers.** Every figure in a finding must come from a report file. Label estimates as estimates.

## Weekly procedure

Run after the server pushes its Monday report (server job: Monday 06:00 EAT).

1. **Sync.** Pull the latest `main`. Read `reports/LATEST.md` and open the newest `reports/YYYY-Www.json` and `.md`. Read the previous week's report too, for trends.
2. **Health check (ETL).**
   - Did the weekly ETL run? (`etl_runs_7d` has a `weekly` row.) If not, raise it at the top of the findings.
   - Compare `rejected`, `flagged` and `pending` outcomes to last week. More than 10% rejected or flagged is a warning.
   - List data-quality flags and say what each one means for the team.
3. **Teach the rules (upskilling).** For each row in `data_quality.unmatched_open`:
   - `county`: if the value is clearly a Kenyan town, area or misspelling, add it to `county_aliases` (lowercase key, canonical county from `counties`).
   - `unit` or `volume`: if the unit is clear, add it to `units_to_tonnes` with a conversion factor and note the assumption in `unit_notes`. If the meaning is unclear, don't guess. List it under "Needs a human decision".
   - Bump `version` (patch for aliases/units, minor for scoring or follow-up changes), set `updated`, and add a `changelog` entry. Validate that the file is still valid JSON.
   - Next week's report shows `unmatched_resolved_this_run`, which is how you check that a rule worked.
4. **Engagement analysis.**
   - Overdue follow-ups: count, trend, and which role (buyer or supplier) is slipping.
   - Funnel: contacts by status. Note any stage where contacts pile up.
   - Top open leads (by `contact_ref`): recommend the order to call them and why (score, overdue, county, volume).
   - Supply vs demand: compare buyer volume (enquiries + calculator median) with supplier offers by material and county. Name gaps, such as "demand in Nairobi hubs, no new suppliers in Central this week".
5. **Draft engagement messages.** Write 2–4 short message templates (WhatsApp-length, plain English, with an optional Swahili line) for the situations in this week's data: new buyer, supplier with material available now, dormant contact, calculator users who didn't enquire. Use placeholders such as `{name}` and never real data.
6. **Scoring and follow-up tuning (only with evidence).** If the reports show, for example, that high-score leads stall while mid-score leads convert, propose or make a small change to `scoring` or `follow_up`, explain it, and version it.
7. **Write findings.** Create `findings/YYYY-Www.md` using the template below.
8. **Learn.** Append to `LEARNINGS.md`: what you learned this week, which rules you added, and what to check next week. If you created a new kind of analysis, add it to `SKILLS.md` with a version.
9. **Commit and push.** One commit: `Data steward YYYY-Www: <one-line summary>`.

## Findings template

```markdown
# Findings YYYY-Www
**Status:** OK / Warning / Action needed
## Top 3 actions for the team this week
## Data health
## Rules updated (version a.b.c → x.y.z)
## Engagement: who to call first (contact refs) and why
## Supply vs demand
## Message drafts
## Needs a human decision
## Next week I will check
```
