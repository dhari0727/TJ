"""
Similar destinations: "if you liked X, try Y".

Method: latent semantic analysis. Each destination is described by its mean TF-IDF vector (what travellers and
Wikivoyage actually wrote about it), its activity mix, its typical daily cost (log scale) and its region. The TF-IDF
block is reduced with truncated SVD so that destinations that use different words for the same ideas (temple / shrine,
beach / coast) land close together; the other blocks are appended with fixed weights and the result is compared with
cosine similarity. Everything comes from the real destination profiles, nothing is synthetic.
"""
import pickle
import numpy as np
from pathlib import Path
from sklearn.decomposition import TruncatedSVD
from sklearn.preprocessing import normalize

ART = Path(__file__).resolve().parents[1] / "artifacts" / "dest_profiles.pkl"
_MODEL = None


class SimilarModel:
    def __init__(self):
        profiles = pickle.load(open(ART, "rb"))["profiles"]
        self.names = list(profiles)
        self.p = profiles
        tf = np.vstack([profiles[n]["tfidf_mean"] for n in self.names])
        k = min(48, max(2, min(tf.shape) - 1))
        svd = TruncatedSVD(n_components=k, random_state=7)
        text = normalize(svd.fit_transform(tf))
        act = normalize(np.vstack([profiles[n]["activity_vec"] for n in self.names]))
        cost = np.log1p(np.array([float(profiles[n].get("base_daily_cost") or 0) for n in self.names]))
        span = cost.max() - cost.min() or 1.0
        cost = ((cost - cost.min()) / span)[:, None]
        regions = sorted({profiles[n].get("region") or "" for n in self.names})
        reg = np.zeros((len(self.names), len(regions)))
        for i, n in enumerate(self.names):
            reg[i, regions.index(profiles[n].get("region") or "")] = 1.0
        # weights: meaning of the text matters most, then what you do there, then price and region
        self.emb = normalize(np.hstack([text * 1.0, act * 0.7, cost * 0.35, reg * 0.25]))
        self.index = {n.lower(): i for i, n in enumerate(self.names)}

    def find(self, query):
        q = (query or "").strip().lower()
        if q in self.index:
            return self.index[q]
        for suffix in (", india", ""):
            if q + suffix in self.index:
                return self.index[q + suffix]
        for n, i in self.index.items():
            if n.split(",")[0] == q.split(",")[0]:
                return i
        return None

    def similar(self, query, top_n=6, same_region=None):
        i = self.find(query)
        if i is None:
            return None
        sims = self.emb @ self.emb[i]
        order = np.argsort(-sims)
        out = []
        for j in order:
            if j == i:
                continue
            p = self.p[self.names[j]]
            if same_region is True and p.get("region") != self.p[self.names[i]].get("region"):
                continue
            if same_region is False and p.get("region") == self.p[self.names[i]].get("region"):
                continue
            out.append({
                "destination": self.names[j], "city": p.get("city"), "region": p.get("region"),
                "similarity": round(float(sims[j]), 3), "daily_cost": p.get("base_daily_cost"),
                "why": _why(self.p[self.names[i]], p),
            })
            if len(out) >= top_n:
                break
        return {"destination": self.names[i], "similar": out}


def _why(a, b):
    from ml.nlp.lexicon import CANONICAL_ACTIVITIES
    acts = list(CANONICAL_ACTIVITIES)
    shared = []
    for k, (x, y) in enumerate(zip(a["activity_vec"], b["activity_vec"])):
        if x > 0.1 and y > 0.1 and k < len(acts):
            shared.append((min(x, y), acts[k]))
    shared.sort(reverse=True)
    bits = [s[1] for s in shared[:2]]
    if a.get("region") == b.get("region"):
        bits.append("same region")
    ca, cb = float(a.get("base_daily_cost") or 0), float(b.get("base_daily_cost") or 0)
    if ca and cb and abs(ca - cb) / max(ca, cb) < 0.25:
        bits.append("similar daily cost")
    return ("Shares " + ", ".join(bits)) if bits else "Similar overall character"


def get_model():
    global _MODEL
    if _MODEL is None:
        _MODEL = SimilarModel()
    return _MODEL
