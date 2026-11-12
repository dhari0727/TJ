"""
Destination profile API: the real facts we hold about one destination, for the guide and compare pages.

Everything comes from the destination profiles built out of Wikivoyage, Wikipedia and real journals
(ml/artifacts/dest_profiles.pkl). Costs come from the trained cost model, never from invented numbers.
"""
import re

from ml.nlp.lexicon import CANONICAL_ACTIVITIES
from ml.recommender.similar import get_model



_CAT = None
_HL = None


def _data():
    global _CAT, _HL
    if _CAT is None:
        import json
        from pathlib import Path
        d = Path(__file__).resolve().parents[1] / "data"
        try:
            _CAT = {c["name"]: c for c in json.load(open(d / "india_catalog.json", encoding="utf-8"))}
        except Exception:
            _CAT = {}
        try:
            _HL = json.load(open(d / "india_highlights.json", encoding="utf-8"))
        except Exception:
            _HL = {}
    return _CAT, _HL


def _clean(text, city=None):
    """Wikivoyage lead text carries wiki residue ('thumb|caption', native-script spellings); keep only the prose."""
    t = str(text or "")
    if "|" in t:
        # an image caption is glued to the front; keep the text only when a clear sentence start can be found
        m = re.search(r"(?<=[a-z0-9\)]) ([A-Z][^\s.|]*(?: [A-Z][^\s.|]*){0,3}(?: \([^)]*\))?,? (?:is|was|are|or|lies|sits|stands)\b)", t)
        t = t[m.start(1):] if m and "|" not in t[m.start(1):] else ""
    t = re.sub(r"\[https?://\S+ ([^\]]+)\]", r"\1", t)
    t = re.sub(r"\s*\([^)]*[^\x00-\x7f][^)]*\)", "", t)
    t = t.replace("&nbsp;", " ")
    t = re.sub(r"\s+", " ", t).strip()
    if t and city and city.split()[0].lower() not in t[:90].lower():
        return ""   # the lead sentence is about something else (a caption fragment); do not show it
    return t


def _short(t, n=420):
    if len(t) <= n:
        return t
    cut = t[:n]
    i = cut.rfind(". ")
    return cut[:i + 1] if i > 120 else cut.rstrip() + "..."


def _end_clean(t):
    """Source text is sometimes cut mid-sentence; keep whole sentences only."""
    t = (t or "").strip()
    if t and not t.endswith((".", "!", "?")):
        i = t.rfind(". ")
        t = t[:i + 1] if i > 60 else t.rstrip(" ,;") + "."
    return t


def get_profile(query, month=None):
    m = get_model()
    i = m.find(query)
    if i is None:
        # "Goa" should find "Old Goa": accept a whole-word match on the place name when it is unambiguous
        q = (query or "").strip().lower()
        hits = [k for n, k in m.index.items() if q and q in n.split(",")[0].split()]
        if len(hits) == 1:
            i = hits[0]
    if i is None:
        return None
    name = m.names[i]
    p = m.p[name]
    acts = []
    for k, v in enumerate(p.get("activity_vec", [])):
        if k < len(CANONICAL_ACTIVITIES) and float(v) > 0:
            acts.append((float(v), CANONICAL_ACTIVITIES[k]))
    acts.sort(reverse=True)
    total = sum(a for a, _ in acts) or 1.0
    from ml.cost.predict import predict_cost
    costs = {}
    for days in (1, 3, 5, 7):
        try:
            c = predict_cost(name, duration_days=days, travel_style="mid-range", month=month, party_size=1)
            costs[str(days)] = {"total": c["predicted_cost"], "low": c["low"], "high": c["high"], "per_day": c["per_day"]}
        except Exception:
            pass
    cat, hl = _data()
    c0 = cat.get(name, {})
    h0 = hl.get((p.get("city") or "").lower(), {}) if isinstance(hl, dict) else {}
    city = p.get("city") or name.split(",")[0]
    desc = _clean(p.get("description"), city) or _clean(c0.get("description"), city) or _clean(h0.get("character"), city)
    landmarks = [{"name": l.get("name"), "category": l.get("category"), "note": _short(_clean(l.get("note")), 220)}
                 for l in (h0.get("landmarks") or [])[:8] if l.get("name")]
    return {
        "destination": name, "city": p.get("city"), "country": p.get("country"), "region": p.get("region"),
        "description": _end_clean(_short(desc)), "source_url": c0.get("source_url"), "landmarks": landmarks,
        "lat": c0.get("lat"), "lon": c0.get("lon"),
        "attractions": list(p.get("attractions") or c0.get("attractions") or [])[:12],
        "season_peak": sorted({int(x) for x in (p.get("season_peak") or c0.get("season_peak") or []) if 1 <= int(x) <= 12}),
        "activities": [{"name": n, "share": round(a / total, 3)} for a, n in acts[:6]],
        "base_daily_cost": p.get("base_daily_cost"),
        "popularity_tier": p.get("popularity_tier"),
        "n_journals": int(p.get("n_journals") or 0),
        "costs": costs,
    }


def list_names():
    """Every destination name with its typical daily cost (for pickers and the live budget helper)."""
    m = get_model()
    return [{"destination": n, "city": m.p[n].get("city"), "per_day": m.p[n].get("base_daily_cost")} for n in m.names]
