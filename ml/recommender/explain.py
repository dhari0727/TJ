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
    Build an explanation for one recommendation dict (from Recommender.recommend).
    Returns the same dict enriched with `explanation` and `evidence`.
    """
    factors = rec["reason_factors"]
    interests = interests or []

    # rank the non-budget signals to find the dominant driver(s).
    # skip None factors (e.g. proximity when no origin region was given).
    signal_items = sorted(
        ((k, v) for k, v in factors.items() if k != "budget" and v is not None),
        key=lambda kv: kv[1], reverse=True)
    top = [k for k, v in signal_items if v >= 0.45][:2] or ([signal_items[0][0]] if signal_items else [])

    parts = []
    # lead with matched interests when content is a driver
    matched = [i for i in interests if i in rec.get("top_activities", [])]
    if "content" in top and matched:
        parts.append(f"it's a great match for your interest in "
                     f"{_join(matched)}")
    elif "content" in top and rec.get("top_activities"):
        parts.append(f"it's known for {_join(rec['top_activities'][:2])}")

    if "collaborative" in top:
        parts.append(f"travelers with tastes like yours rated it highly "
                     f"({rec['n_journals']} journals)")

    if "semantic" in top and "content" not in top:
        parts.append("its travel stories closely match what you're looking for")

    # sentiment evidence
    if rec.get("sentiment_mean", 0) >= 0.5:
        parts.append("visitors wrote overwhelmingly positive reviews")

    # budget clause
    pc = rec["predicted_cost"]
    if budget:
        if pc <= budget:
            parts.append(f"and the estimated {_fmt_inr(pc)} fits your "
                         f"{_fmt_inr(budget)} budget")
        elif rec["budget_fit"] == "stretch":
            parts.append(f"though at ~{_fmt_inr(pc)} it slightly stretches your "
                         f"{_fmt_inr(budget)} budget")
    else:
        parts.append(f"with an estimated cost of {_fmt_inr(pc)}")

    gem = ""
    if rec.get("lesser_known"):
        gem = " It's a lesser-known hidden gem worth discovering."

    dest = rec["destination"]
    sentence = f"We recommend {dest} because " + _join(parts, sep="; ") + "." + gem

    titles = sample_titles(dest)
    rec = dict(rec)
    rec["explanation"] = sentence
    rec["evidence"] = {
        "sample_journals": titles,
        "dominant_factors": top,
        "predicted_cost": pc,
    }
    rec["sample_journal_titles"] = titles
    return rec


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
