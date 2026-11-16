# Prospects data

`prospects.json` is the Potential Partners pipeline. The agent writes it on Friday, the server loads it into MySQL (`jocate_prospects`) the same day, and the team works it in the admin page under **Potential partners**. The team's status and notes live only in the database, so a new import never overwrites them.

## Record schema
```json
{
  "id": "kebab-case-company-slug",
  "company": "Company name as it trades",
  "sector": "brewing | tea | edible-oil | dairy | sugar | textiles | paper-packaging | chemicals | feed-milling | food | hospital | hotel | education | laundry | other",
  "tier": "A | B",
  "hotspot": "id from knowledge/hotspots.json",
  "sites": [{ "town": "Thika", "county": "Kiambu" }],
  "website": "https://...",
  "procurement_url": "https://... (public supplier-registration or tender page, optional)",
  "summary": "1-2 sentences: what they make and why they need heat",
  "current_fuel": "e.g. 'HFO boilers (2025 sustainability report)' or 'Unverified: sector norm is oil-fired'",
  "signals": ["expansion: new line 2026", "net-zero 2030 target"],
  "fit_score": 0,
  "score_breakdown": { "heat": 0, "distance": 0, "fossil": 0, "scale": 0, "signals": 0, "access": 0 },
  "band": "priority | strong | watch",
  "confidence": "high | medium | low",
  "suggested_angle": "Why now, plus a Jocate Insights link",
  "next_step": "e.g. 'Register on supplier portal' or 'Request boiler review meeting'",
  "research_status": "new | refreshed | closed",
  "evidence": [{ "claim": "Operates a brewery in Thika", "url": "https://...", "source": "Company website", "date": "2026-10-02" }],
  "first_seen": "YYYY-MM-DD",
  "last_reviewed": "YYYY-MM-DD"
}
```
Rules: company-level information only (no personal names, phones or emails). Every record needs at least one `evidence` item. `fit_score` must equal the sum of `score_breakdown`.
