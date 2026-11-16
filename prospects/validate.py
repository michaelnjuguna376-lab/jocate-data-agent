"""Validate prospects/prospects.json against the schema in prospects/README.md.
Run: python3 prospects/validate.py   (exit code 1 on any error)"""
import json, re, sys, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
SECTORS = {"brewing", "tea", "edible-oil", "dairy", "sugar", "textiles", "paper-packaging", "chemicals", "feed-milling",
           "food", "hospital", "hotel", "education", "laundry", "other"}
MAX = {"heat": 30, "distance": 20, "fossil": 15, "scale": 15, "signals": 15, "access": 5}
BLOCKED = {"eabl", "east-african-breweries", "bidco", "bidco-africa", "prime-cartons", "sunflag", "sunflag-textiles",
           "kimfay", "edible-oil-refineries", "golden-africa", "synresins"}
PII = re.compile(r"[\w.+-]+@[\w-]+\.[\w.]+|\+?254\s?\d{3}\s?\d{3}\s?\d{3}|\b0[17]\d{8}\b")
DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def main() -> int:
    hot = {h["id"] for h in json.loads((ROOT / "knowledge/hotspots.json").read_text(encoding="utf-8"))["demand_hotspots"]}
    data = json.loads((ROOT / "prospects/prospects.json").read_text(encoding="utf-8"))
    errors, seen = [], set()
    for i, p in enumerate(data.get("prospects", [])):
        tag = f"[{i}] {p.get('id', '?')}"
        for f in ("id", "company", "sector", "tier", "hotspot", "summary", "fit_score", "score_breakdown", "band",
                  "confidence", "evidence", "first_seen", "last_reviewed", "research_status"):
            if f not in p or p[f] in (None, "", []):
                errors.append(f"{tag}: missing {f}")
        pid = p.get("id", "")
        if not re.fullmatch(r"[a-z0-9]+(-[a-z0-9]+)*", pid): errors.append(f"{tag}: id must be kebab-case")
        if pid in seen: errors.append(f"{tag}: duplicate id")
        seen.add(pid)
        if any(b == pid or pid.startswith(b + "-") for b in BLOCKED): errors.append(f"{tag}: existing client / do-not-prospect")
        if p.get("sector") not in SECTORS: errors.append(f"{tag}: unknown sector {p.get('sector')}")
        if p.get("tier") not in ("A", "B"): errors.append(f"{tag}: tier must be A or B")
        if p.get("hotspot") not in hot: errors.append(f"{tag}: unknown hotspot {p.get('hotspot')}")
        if p.get("band") not in ("priority", "strong", "watch"): errors.append(f"{tag}: bad band")
        if p.get("confidence") not in ("high", "medium", "low"): errors.append(f"{tag}: bad confidence")
        if p.get("research_status") not in ("new", "refreshed", "closed"): errors.append(f"{tag}: bad research_status")
        sb = p.get("score_breakdown", {})
        for k, mx in MAX.items():
            v = sb.get(k, 0)
            if not isinstance(v, int) or v < 0 or v > mx: errors.append(f"{tag}: score_breakdown.{k} must be 0..{mx}")
        if isinstance(p.get("fit_score"), int) and p["fit_score"] != sum(v for v in sb.values() if isinstance(v, int)):
            errors.append(f"{tag}: fit_score != sum(score_breakdown)")
        band = "priority" if p.get("fit_score", 0) >= 75 else "strong" if p.get("fit_score", 0) >= 55 else "watch"
        if p.get("fit_score", 0) < 35 and p.get("research_status") != "closed": errors.append(f"{tag}: score < 35 should not be listed")
        elif p.get("band") != band and p.get("research_status") != "closed": errors.append(f"{tag}: band should be {band}")
        for d in ("first_seen", "last_reviewed"):
            if p.get(d) and not DATE.match(p[d]): errors.append(f"{tag}: {d} must be YYYY-MM-DD")
        for e in p.get("evidence", []):
            if not str(e.get("url", "")).startswith("http") or not e.get("claim") or not e.get("source"):
                errors.append(f"{tag}: evidence needs claim, source and http(s) url")
        blob = json.dumps({k: v for k, v in p.items() if k not in ("website", "procurement_url", "evidence")}, ensure_ascii=False)
        if PII.search(blob): errors.append(f"{tag}: looks like personal contact data (email/phone) - remove it")
    for e in errors: print("ERROR", e)
    print(f"{len(data.get('prospects', []))} prospects checked, {len(errors)} errors")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
