# Data Steward skills ledger

What the agent can do today. The agent adds a row whenever it learns a new, repeatable capability (a new check, analysis or rule type), so this file shows how it has upskilled over time.

| Skill | Since | Version | What it does |
|---|---|---|---|
| Phone normalisation | 2026-11-16 | 1.0 | Converts Kenyan numbers (07…, 01…, 254…, +254…) to +254 E.164 and flags invalid ones (server ETL) |
| Email validation | 2026-11-16 | 1.0 | Lowercases, trims and validates emails (server ETL) |
| County canonicalisation | 2026-11-16 | 1.0 | Maps 47 counties plus town aliases to canonical names, using `rules.json` |
| Volume to tonnes | 2026-11-16 | 1.0 | Parses free-text quantities (t, lorry loads, bags, m³) into tonnes, using `rules.json` |
| De-duplication | 2026-11-16 | 1.0 | Merges contacts by phone, then email, and logs the merge |
| Lead scoring | 2026-11-16 | 1.0 | Scores 0–100 from contact completeness, volume, county and availability |
| Follow-up scheduling | 2026-11-16 | 1.0 | Sets next action and due time per role; flags dormant contacts after 30 days |
| Unmatched-value learning | 2026-11-16 | 1.0 | Reviews values the rules couldn't map and adds aliases or units with evidence |
| Weekly findings | 2026-11-16 | 1.0 | Health check, funnel, supply-vs-demand gaps, call order, message drafts |
