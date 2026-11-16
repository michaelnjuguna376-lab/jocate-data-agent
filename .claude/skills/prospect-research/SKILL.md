---
name: prospect-research
description: Deep-research method for finding and verifying potential industrial buyers of biomass boiler fuel in Kenya. Use it whenever the Potential Partners agent researches companies, sectors, demand hotspots, tenders or fuel-switching news. It plans searches across many sources, verifies each claim against primary or credible sources, cites everything, and returns structured prospect records.
---

# Prospect research (deep-research method)

You are researching **companies** (not people) that burn fuel for steam or process heat in Kenya and could buy firewood or agro-residues from Jocate Enterprises.

## 1. Plan before you search
- Read `knowledge/market-brief.md`, `knowledge/hotspots.json`, `knowledge/scoring.md` and `prospects/coverage.json`.
- Pick this week's focus: the next 2 hotspots in `hotspots.json` → `rotation` after `coverage.json` → `last_hotspots`, and 2–3 Tier A sectors that are under-covered there.
- Write a short query plan (8–15 queries) before searching. Mix:
  - **Discovery:** "<sector> factory <town> Kenya", "<sector> manufacturers <county>", "KAM members <sector>", "<town> industrial area companies".
  - **Fuel evidence:** "<company> boiler", "<company> furnace oil", "<company> biomass", "<company> energy efficiency", "<company> sustainability report".
  - **Signals:** "tender supply of firewood 2026", "biomass boiler Kenya 2026", "<company> expansion new plant 2026", "KTDA firewood tender".

## 2. Search wide, then go deep
- Run discovery queries first and list candidate companies. Then, for each promising candidate, open primary sources:
  - the company website: about, products, sustainability and careers pages
  - annual or ESG reports
  - official tender notices
  - credible news: Business Daily, The Standard, Nation, The Star, Capital FM, KNA
- Prefer sources from the last 24 months. Record the publication date of every source you use.
- Don't rely on one aggregator or directory: confirm that the company exists and operates at the stated location from its own site or a second source.

## 3. Verify every claim
- A **key claim** (location, sector, fuel used, expansion, tender) needs at least one primary or credible source. Use two independent sources for `confidence: high`.
- Never infer a fuel from a sector norm and present it as fact. Write it as "Sector norm: likely oil-fired (unverified)" and score it 5, not 15.
- If sources conflict, keep both, note the conflict, and lower the confidence.
- Don't use paywalled content you can't read, and don't guess what's behind a paywall.

## 4. Privacy and conduct
- Record **company-level** information only: name, sector, sites, website, the public procurement or supplier-registration page, and the general enquiries page.
- **No personal data:** no individual names, personal phone numbers or personal emails, even if they're published.
- Research only. Never submit forms, register on portals, or contact anyone.

## 5. Output
Return or update records in `prospects/prospects.json` using the schema in `prospects/README.md`. Every record has `evidence[]` entries with `claim`, `url`, `source` and `date`. Score with `knowledge/scoring.md`. Before committing, validate with `python3 prospects/validate.py`.

## 6. Stop rules
- Stop discovery for a hotspot after 8 credible candidates or 15 queries, whichever comes first.
- Spend effort on depth for the strongest 5–10 candidates rather than shallow lists.
