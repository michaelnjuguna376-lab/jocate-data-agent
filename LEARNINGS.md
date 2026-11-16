# Learnings log

The agent appends one entry per run, newest at the bottom. Each entry covers what it observed, what it changed (with rule versions) and what to check next time. People at Jocate can add entries too: mark them `(human)`.

## 2026-11-16 (setup)
- System created. Rules v1.0.0 cover 47 counties, about 40 town aliases, common units (t, lorry loads, bags, m³) and lead scoring.
- Assumptions to verify against real data: lorry load ≈ 10 t, bag ≈ 50 kg, stacked m³ of seasoned wood ≈ 0.45 t.
- Next: once real submissions arrive, check whether supplier quantities in "trees" or "acres" need a tonnage estimate rule.

## 2026-11-16 (W46 run)
- Observed: report 2026-W46 has 0 submissions (0 all-time); weekly ETL ran once on rules v1.0.0 with all-zero counts. No prior report to compare.
- Changed: nothing; rules stay at v1.0.0 (no evidence).
- Next: check for first real submissions, unmatched county/unit values, and the reject/flag rate.
