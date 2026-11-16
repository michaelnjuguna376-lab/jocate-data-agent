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
## 2026-11-16 Prospecting (2026-W47)
- First run. Hotspots: nairobi, thika-ruiru. Sectors: food/beverage, paper/packaging, hospitals. Added 8 prospects (0 refreshed, pipeline was empty).
- Worked: queries combining "biomass boiler" + company name; Unilever/AKUH/Kapa gave documented fuels. Company UNGC/sustainability reports are good fuel evidence.
- Wasted time: page fetches were blocked by network policy (standardmedia, capitalfm, company sites), so everything rests on search summaries; most confidence is low. Aggregator tender searches returned only expired notices.
- Insight: boiler-as-a-service (Lean Energy) is a competitor and channel; already-biomass users (Kapa, AKUH, Unilever boilers) are supply-side prospects.
- Next week: nakuru-naivasha and athi-river; dairy, textiles, packaging; verify fuels for the low-confidence set; check the Nakuru dairy/juice plant sale.

## 2026-11-16 Prospecting (2026-W47, second run)
- Hotspots: nakuru-naivasha, athi-river. Sectors: feed, food, edible oil, chemicals. Added 8 prospects, refreshed 6 (pipeline now 16).
- Worked: "<town> factory expansion 2026" queries (De Heus, Crown Paints), NEMA ESIA PDFs and KDC pages as near-primary sources.
- Wasted time: "<company> boiler fuel" queries returned nothing for Athi River and Nakuru firms; page fetches are still blocked, so 6 of 8 new prospects are low confidence and fuel is unverified.
- Next week: central-tea-dairy and eldoret; check Ken Meat, KTDA tenders; verify fuels for low-confidence records once fetch access is allowed.

## 2026-11-16 Prospecting (2026-W47, third run)
- Hotspots: central-tea-dairy, eldoret. Sectors: dairy, tea, textiles, hospitals (+ Nakuru beverage follow-up). Added 7 prospects, refreshed 7, closed 1 (Gertrude's: biomass run by Lean Energy). Pipeline now 23.
- Worked: acquisition news (VBL/Varun bought the Nakuru plant with a listed boiler), dairy trade sites (Dairy Business MEA, FoodBev) with dates, KTDA tenders page for the firewood-tender check.
- Wasted time: "<company> boiler" queries for dairies, tea factories and Eldoret firms returned nothing; Nation and Kenyan Wall Street fetches give 403; Business Daily dairy pages are old (2011, 2015).
- Insight: KTDA tea factories are shifting to electricity, briquettes and gasification, so tea firewood demand is shrinking; dairies and beverage plants are the better Tier A targets.
- Next week: kericho-tea and limuru; verify fuel for VBL, Meru Dairy, New KCC; refresh Kentegra, Crystal Chillers, Kevian, Brookside, EAPM.
