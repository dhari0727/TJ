"""
JourneyAI — explainability layer (P2).

Turns a recommendation's numeric `reason_factors` into a transparent,
human-readable explanation: which signals drove the pick, plus concrete
evidence (matching journals, cost-vs-budget, hidden-gem status).

This is what makes the system "explainable AI": every recommendation can say
WHY it was suggested, in plain language, traceable to the data.
"""
from ml.db import fetch_all

# friendly labels for the blend factors
_FACTOR_LABEL = {
    "content": "matches your interests",
    "semantic": "the travel stories resonate with what you want",
    "collaborative": "travelers with tastes like yours loved it",
    "budget": "it fits your budget",
}


def _fmt_inr(x):
    return f"Rs {int(round(x)):,}"


def sample_titles(destination, limit=2):
    """A couple of real journal titles backing this destination (evidence)."""
    rows = fetch_all(
        "SELECT j.Title FROM journal_features jf "
        "JOIN db j ON j.entry_id = jf.entry_id "
        "WHERE jf.canonical_dest = %s AND jf.sentiment_label = 'positive' "
        "ORDER BY jf.sentiment_score DESC LIMIT %s",
        (destination, limit))
    return [r["Title"] for r in rows]


def explain(rec, budget=None, interests=None):
    """
    Build a destination-specific explanation for one recommendation dict (from Recommender.recommend).
    Uses concrete facts the system actually has: real attractions, distance from the start, season fit,
    cost per day vs budget. Returns the same dict enriched with `explanation` and `evidence`.
    """
    factors = rec["reason_factors"]
    interests = interests or []
    dest = rec["destination"]
    city = dest.split(",")[0]
    acts = rec.get("top_activities") or []
    matched = [i for i in interests if i in acts]
    attractions = [a for a in (rec.get("attractions") or []) if a][:3]
    days = int(rec.get("duration_days") or 0)
    pc = rec["predicted_cost"]

    signal_items = sorted(((k, v) for k, v in factors.items() if k != "budget" and v is not None),
                          key=lambda kv: kv[1], reverse=True)
    top = [k for k, v in signal_items if v >= 0.45][:2] or ([signal_items[0][0]] if signal_items else [])

    sents = []
    # 1) why it fits, with real places as proof
    if matched:
        lead = f"{city} fits your interest in {_join(matched)}"
    elif acts:
        lead = f"{city} is known for {_join(acts[:2])}"
    else:
        lead = f"{city} is a strong all-round pick"
    if attractions:
        lead += f", with {_join(attractions)}"
    sents.append(lead + ".")

    # 2) getting there + when
    logistics = []
    if rec.get("distance_km"):
        h = rec.get("drive_hours")
        logistics.append(f"about {rec['distance_km']} km away" + (f" ({h} h by road)" if h else " (best reached by train or flight)"))
    if rec.get("best_season"):
        fit = rec.get("season_fit")
        when = f"best in {rec['best_season']}"
        if fit is not None and fit >= 0.9:
            when += ", which suits your travel month"
        elif fit is not None and fit <= 0.4:
            when += ", so your travel month is off-season"
        logistics.append(when)
    if logistics:
        sents.append(_cap(_join(logistics, sep="; ")) + ".")

    # 3) cost
    perday = int(round(pc / days)) if days else None
    cost = f"Estimated {_fmt_inr(pc)}" + (f" for {days} day{'s' if days != 1 else ''} (about {_fmt_inr(perday)} a day)" if perday else "")
    if budget:
        if pc <= budget:
            cost += f", within your {_fmt_inr(budget)} budget"
        elif rec.get("budget_fit") == "stretch":
            cost += f", a slight stretch on your {_fmt_inr(budget)} budget"
    sents.append(cost + ".")

    if "collaborative" in top and rec.get("n_journals", 0) >= 5:
        sents.append("Travellers with similar tastes rated it highly.")
    if rec.get("lesser_known"):
        sents.append("A lesser-known place worth discovering.")

    titles = sample_titles(dest)
    rec = dict(rec)
    rec["explanation"] = " ".join(sents)
    rec["evidence"] = {"sample_journals": titles, "dominant_factors": top, "predicted_cost": pc}
    rec["sample_journal_titles"] = titles
    return rec


def _cap(t):
    return t[:1].upper() + t[1:] if t else t


def _join(items, sep=", "):
    items = [str(i) for i in items if i]
    if not items:
        return ""
    if len(items) == 1:
        return items[0]
    if sep == ", ":
        return ", ".join(items[:-1]) + " and " + items[-1]
    return sep.join(items)


if __name__ == "__main__":
    from ml.recommender.hybrid import get_recommender
    rec = get_recommender()
    recs = rec.recommend(interests=["history", "temples"], budget=60000,
                         duration_days=7, month=1, top_n=3)
    for r in recs:
        e = explain(r, budget=60000, interests=["history", "temples"])
        print("*", e["explanation"])
        print("  evidence:", e["evidence"]["sample_journals"], "\n")
