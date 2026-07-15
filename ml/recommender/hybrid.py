"""
JourneyAI — hybrid explainable recommender (P2 core).

Blends three independent signals into one ranked destination list:

  1. CONTENT  — cosine between the user's interest one-hot (over the activity
                vocabulary) and each destination's activity histogram.
  2. SEMANTIC — cosine between a TF-IDF vector built from the user's interest
                text and each destination's mean-TF-IDF profile, nudged by the
                destination's mean sentiment ("narrative quality").
  3. COLLAB   — item-item collaborative filtering from the interactions table:
                for the user's liked destinations (or, cold-start, globally
                popular ones), how similar is each candidate.

  final = wc*content + ws*semantic + wcf*collab, then multiplied by a
  budget-fit factor and given a small discovery boost for well-scoring
  lesser-known ("hidden gem") destinations. Candidates whose predicted cost
  exceeds budget*1.25 are filtered out.

Artifacts consumed (built in P1 + cost model): dest_profiles.pkl,
tfidf_vectorizer.pkl. Interactions are read live from the DB at load().

The Recommender object is built once at Flask boot and reused per request.
"""
import os
import pickle

import numpy as np
from sklearn.metrics.pairwise import cosine_similarity

from ml.db import fetch_all
from ml.cost.predict import predict_cost
from ml.recommender.proximity import proximity_score, distance_label
# The pickled TF-IDF vectorizer uses this custom tokenizer; import from the
# stable shared module so unpickling always resolves the reference.
from ml.nlp.tokenizer import tokenize  # noqa: F401

ARTIFACT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "artifacts"))

# blend weights (tunable). Proximity only applies when the user asks for "near me".
W_CONTENT = 0.38
W_SEMANTIC = 0.22
W_COLLAB = 0.30
W_PROXIMITY = 0.10             # weight when an origin region is provided
DISCOVERY_BOOST = 0.08         # additive bonus for strong lesser-known picks
BUDGET_HARD_FACTOR = 1.25      # drop candidates above budget * this


def _norm(x):
    x = np.asarray(x, dtype=float)
    lo, hi = x.min(), x.max()
    return (x - lo) / (hi - lo) if hi > lo else np.zeros_like(x)


class Recommender:
    def __init__(self):
        with open(os.path.join(ARTIFACT_DIR, "dest_profiles.pkl"), "rb") as f:
            bundle = pickle.load(f)
        self.profiles = bundle["profiles"]
        self.activities = bundle["activities"]
        with open(os.path.join(ARTIFACT_DIR, "tfidf_vectorizer.pkl"), "rb") as f:
            self.vectorizer = pickle.load(f)

        self.dest_names = list(self.profiles.keys())
        self.act_index = {a: i for i, a in enumerate(self.activities)}

        # stack destination matrices
        self.activity_mat = np.vstack([self.profiles[d]["activity_vec"] for d in self.dest_names])
        self.tfidf_mat = np.vstack([
            (self.profiles[d]["tfidf_mean"]
             if self.profiles[d]["tfidf_mean"] is not None
             else np.zeros(len(self.vectorizer.vocabulary_)))
            for d in self.dest_names
        ])
        self.sentiment = np.array([self.profiles[d]["sentiment_mean"] for d in self.dest_names])
        self.tiers = [self.profiles[d]["popularity_tier"] for d in self.dest_names]
        self.regions = [self.profiles[d].get("region") for d in self.dest_names]

        self._build_cf()

    # ------------------------------------------------------------------ CF
    def _build_cf(self):
        """Item-item CF: destination x destination cosine over a user-rating matrix."""
        rows = fetch_all(
            "SELECT eml, destination, AVG(rating) AS r FROM interactions "
            "WHERE rating IS NOT NULL GROUP BY eml, destination")
        users = sorted({r["eml"] for r in rows})
        u_index = {u: i for i, u in enumerate(users)}
        d_index = {d: i for i, d in enumerate(self.dest_names)}
        mat = np.zeros((len(users), len(self.dest_names)))
        for r in rows:
            if r["destination"] in d_index:
                mat[u_index[r["eml"]], d_index[r["destination"]]] = float(r["r"])
        self.cf_user_index = u_index
        self.cf_matrix = mat  # users x dests
        # mean-center per user (ignore zeros) then item-item cosine
        with np.errstate(invalid="ignore"):
            item_vectors = mat.T  # dests x users
        # popularity (avg rating * log support) for cold-start & tie-breaks
        support = (mat > 0).sum(axis=0)
        avg_rating = np.divide(mat.sum(axis=0), np.maximum(support, 1))
        self.popularity = _norm(avg_rating * np.log1p(support))
        # item-item similarity
        sim = cosine_similarity(item_vectors) if item_vectors.shape[1] else np.zeros(
            (len(self.dest_names), len(self.dest_names)))
        np.fill_diagonal(sim, 0.0)
        self.item_sim = sim

    def _user_liked_vector(self, eml):
        """Return this user's rating row (dests,) or None if unknown/cold."""
        i = self.cf_user_index.get(eml)
        if i is None:
            return None
        row = self.cf_matrix[i]
        return row if row.sum() > 0 else None

    def _collab_scores(self, eml):
        liked = self._user_liked_vector(eml)
        if liked is None:
            # cold-start: fall back to global popularity
            return self.popularity.copy()
        # weight item similarities by how much the user liked each seed dest
        w = np.clip(liked - 2.5, 0, None)  # only positive preferences drive CF
        if w.sum() == 0:
            return self.popularity.copy()
        scores = self.item_sim @ w
        return _norm(scores)

    # -------------------------------------------------------------- scoring
    def _content_scores(self, interests):
        q = np.zeros(len(self.activities))
        for it in interests:
            if it in self.act_index:
                q[self.act_index[it]] = 1.0
        if q.sum() == 0:
            return np.full(len(self.dest_names), 0.5)  # neutral if no interests
        sims = cosine_similarity(q.reshape(1, -1), self.activity_mat).ravel()
        return _norm(sims)

    def _semantic_scores(self, interests):
        text = " ".join(interests) if interests else ""
        if not text.strip():
            base = np.zeros(len(self.dest_names))
        else:
            qv = self.vectorizer.transform([text]).toarray()
            base = cosine_similarity(qv, self.tfidf_mat).ravel()
        # nudge by sentiment quality (0..1)
        sent = _norm(self.sentiment)
        return _norm(0.75 * _norm(base) + 0.25 * sent)

    def recommend(self, eml=None, budget=None, duration_days=5, interests=None,
                  travel_style="mid-range", month=None, party_size=1, top_n=6,
                  origin_region=None):
        interests = [i.lower() for i in (interests or [])]
        content = self._content_scores(interests)
        semantic = self._semantic_scores(interests)
        collab = self._collab_scores(eml)

        use_prox = bool(origin_region)
        base = W_CONTENT * content + W_SEMANTIC * semantic + W_COLLAB * collab

        # Predict cost for ALL destinations in ONE batched model pass (fast).
        from ml.cost.predict import predict_cost_batch
        cost_map = predict_cost_batch(self.dest_names, duration_days=duration_days,
                                      travel_style=travel_style, month=month,
                                      party_size=party_size)

        results = []
        for i, dest in enumerate(self.dest_names):
            cost = cost_map[dest]
            predicted = cost["predicted_cost"]

            # budget-fit factor in [0,1] — NEVER hard-drop; over-budget just scores lower
            if budget and budget > 0:
                if predicted <= budget:
                    fit = 1.0
                else:
                    over = (predicted - budget) / budget
                    fit = max(0.0, 1.0 - over)
                over_budget = predicted > budget * BUDGET_HARD_FACTOR
            else:
                fit = 1.0
                over_budget = False

            # proximity ("near me") signal
            prox = proximity_score(origin_region, self.regions[i]) if use_prox else 0.5
            season_fit = self._season_fit(dest, month)

            score = base[i]
            if use_prox:
                score = (1 - W_PROXIMITY) * score + W_PROXIMITY * prox
            score = score * (0.5 + 0.5 * fit)        # budget modulates
            score = score * (0.9 + 0.1 * season_fit) # small season nudge
            if self.tiers[i] == "lesser_known" and base[i] > 0.5:
                score += DISCOVERY_BOOST

            results.append({
                "destination": dest,
                "score": round(float(score), 4),
                "predicted_cost": predicted,
                "cost_low": cost["low"],
                "cost_high": cost["high"],
                "cost_breakdown": cost["cost_breakdown"],
                "budget_fit": ("within" if (not budget or predicted <= budget)
                               else ("stretch" if not over_budget else "over")),
                "over_budget": over_budget,
                "duration_days": duration_days,
                "lesser_known": self.tiers[i] == "lesser_known",
                "sentiment_mean": round(float(self.sentiment[i]), 3),
                "n_journals": self.profiles[dest]["n_journals"],
                "region": self.regions[i],
                "distance_label": distance_label(origin_region, self.regions[i]) if use_prox else "",
                "best_season": self._season_text(dest),
                "season_fit": round(float(season_fit), 2),
                "reason_factors": {
                    "content": round(float(content[i]), 3),
                    "semantic": round(float(semantic[i]), 3),
                    "collaborative": round(float(collab[i]), 3),
                    "budget": round(float(fit), 3),
                    "proximity": round(float(prox), 3) if use_prox else None,
                },
                "top_activities": self._top_activities(dest),
            })

        # Prefer within-budget picks, but NEVER return an empty list: if fewer than
        # top_n fit the budget, backfill with the closest over-budget options so the
        # user always sees suggestions (flagged as a stretch).
        results.sort(key=lambda r: r["score"], reverse=True)
        in_budget = [r for r in results if not r["over_budget"]]
        if len(in_budget) >= top_n:
            return in_budget[:top_n]
        # backfill: keep in-budget first, then nearest-cost over-budget options
        over = [r for r in results if r["over_budget"]]
        over.sort(key=lambda r: r["predicted_cost"])
        return (in_budget + over)[:top_n]

    def _top_activities(self, dest, k=3):
        vec = self.profiles[dest]["activity_vec"]
        order = np.argsort(vec)[::-1]
        return [self.activities[i] for i in order[:k] if vec[i] > 0]

    def _season_fit(self, dest, month):
        """1.0 if the chosen month is a peak month for the destination, else lower."""
        if not month:
            return 1.0
        peak = self.profiles[dest].get("season_peak") or []
        if not peak:
            return 1.0
        if month in peak:
            return 1.0
        # near a peak month (±1) gets partial credit
        near = any(abs(((month - p + 6) % 12) - 6) <= 1 for p in peak)
        return 0.7 if near else 0.4

    def _season_text(self, dest):
        """Human-readable best months, e.g. 'Oct–Feb'."""
        peak = sorted(self.profiles[dest].get("season_peak") or [])
        if not peak:
            return ""
        mn = ['', 'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
        # collapse contiguous runs (handle wrap-around)
        return ", ".join(mn[m] for m in peak[:4]) + ("+" if len(peak) > 4 else "")


_INSTANCE = None


def get_recommender():
    global _INSTANCE
    if _INSTANCE is None:
        _INSTANCE = Recommender()
    return _INSTANCE


if __name__ == "__main__":
    rec = get_recommender()
    print("=== beach + food, budget Rs30000, 5 days ===")
    for r in rec.recommend(interests=["beach", "food"], budget=30000, duration_days=5,
                           travel_style="mid-range", month=12, top_n=5):
        gem = " [GEM]" if r["lesser_known"] else ""
        print(f"  {r['destination']:24s} score={r['score']:.3f} "
              f"cost=Rs{r['predicted_cost']:>6,} fit={r['budget_fit']:7s} "
              f"acts={r['top_activities']}{gem}")
    print("\n=== history + temples, budget Rs60000, 7 days ===")
    for r in rec.recommend(interests=["history", "temples"], budget=60000, duration_days=7,
                           travel_style="mid-range", month=1, top_n=5):
        gem = " [GEM]" if r["lesser_known"] else ""
        print(f"  {r['destination']:24s} score={r['score']:.3f} "
              f"cost=Rs{r['predicted_cost']:>6,} acts={r['top_activities']}{gem}")
