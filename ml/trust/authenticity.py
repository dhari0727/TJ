"""
Journal authenticity scoring: a second opinion for the trust layer, never a verdict.

Signals (all computed from the text itself, no labels needed):
  - near-duplicate: max TF-IDF cosine similarity against every other journal by a DIFFERENT author (copy/paste, spin)
  - lexical diversity and repetition: very repetitive or very low-diversity text is typical of filler / bot output
  - keyword and link stuffing: URLs, phone numbers, repeated promo words
  - specificity: real trips mention concrete things (numbers, prices, place names, durations)
  - length: very short entries carry little evidence
An IsolationForest fitted on the real journal corpus adds an "unusual for this site" signal. The final score is 0-100
(higher = more likely genuine) and always returns the reasons so a moderator can see why. It only ever feeds the
moderation queue as an advisory flag; it never deletes or hides anything by itself.
"""
import re
import math
import numpy as np
from collections import Counter

PROMO = {"buy", "cheap", "discount", "offer", "call", "whatsapp", "contact", "booking", "visit", "www", "http", "click",
         "free", "win", "best price", "limited"}
URL = re.compile(r"(https?://|www\.)\S+", re.I)
PHONE = re.compile(r"(\+?\d[\d\s\-]{8,}\d)")
NUM = re.compile(r"\d")
WORD = re.compile(r"[A-Za-zऀ-෿']+")

_CORPUS = None


def _features(text):
    t = text or ""
    words = [w.lower() for w in WORD.findall(t)]
    n = len(words)
    uniq = len(set(words))
    ttr = uniq / n if n else 0.0
    top = Counter(words).most_common(1)[0][1] / n if n else 0.0
    caps = sum(1 for c in t if c.isupper()) / max(1, len(t))
    sentences = [s for s in re.split(r"[.!?\n]+", t) if s.strip()]
    uniq_sent = len({s.strip().lower() for s in sentences}) / max(1, len(sentences))
    promo = sum(1 for w in words if w in PROMO) / n if n else 0.0
    return {
        "n_words": n, "ttr": ttr, "top_word_share": top, "caps_ratio": caps, "unique_sentences": uniq_sent,
        "urls": len(URL.findall(t)), "phones": len(PHONE.findall(t)), "digit_share": len(NUM.findall(t)) / max(1, len(t)),
        "promo_share": promo, "avg_sentence_len": n / max(1, len(sentences)),
    }


_KEYS = ["n_words", "ttr", "top_word_share", "caps_ratio", "unique_sentences", "urls", "phones", "digit_share",
         "promo_share", "avg_sentence_len"]


def _load():
    """Fit once: vectoriser over all journal text + isolation forest over the feature vectors."""
    global _CORPUS
    if _CORPUS is not None:
        return _CORPUS
    from sklearn.feature_extraction.text import TfidfVectorizer
    from sklearn.ensemble import IsolationForest
    from ml.db import fetch_all
    rows = fetch_all("SELECT entry_id, eml, CONCAT_WS(' ', Title, Description, ptv) AS txt FROM journals")
    texts = [r["txt"] or "" for r in rows]
    vec = TfidfVectorizer(min_df=2, max_df=0.6, ngram_range=(1, 2), max_features=30000, sublinear_tf=True)
    X = vec.fit_transform(texts) if texts else None
    feats = np.array([[_features(t)[k] for k in _KEYS] for t in texts]) if texts else np.zeros((0, len(_KEYS)))
    iso = None
    if len(feats) >= 30:
        iso = IsolationForest(n_estimators=150, contamination=0.05, random_state=7).fit(feats)
    _CORPUS = {"rows": rows, "vec": vec, "X": X, "iso": iso}
    return _CORPUS


def score(text, author=None, exclude_entry_id=None):
    c = _load()
    f = _features(text)
    reasons, pts = [], 100.0

    if f["n_words"] < 25:
        pts -= 25; reasons.append("very short, little evidence of a real trip")
    elif f["n_words"] < 60:
        pts -= 8

    if f["n_words"] >= 20:
        if f["ttr"] < 0.35:
            pts -= 18; reasons.append("very repetitive vocabulary")
        if f["top_word_share"] > 0.18:
            pts -= 10; reasons.append("one word dominates the text")
        if f["unique_sentences"] < 0.7:
            pts -= 18; reasons.append("repeated sentences")
    if f["caps_ratio"] > 0.35 and f["n_words"] >= 10:
        pts -= 10; reasons.append("mostly capital letters")

    if f["urls"]:
        pts -= min(30, 15 * f["urls"]); reasons.append("contains links")
    if f["phones"]:
        pts -= 20; reasons.append("contains a phone number")
    if f["promo_share"] > 0.04:
        pts -= 20; reasons.append("promotional wording")

    if f["digit_share"] > 0.001 or f["n_words"] > 120:
        pts += 3  # concrete numbers (prices, days) or a long entry is mild positive evidence
    pts = min(pts, 100.0)

    dup = {"similarity": 0.0, "entry_id": None}
    if c["X"] is not None and f["n_words"] >= 15:
        v = c["vec"].transform([text or ""])
        sims = (c["X"] @ v.T).toarray().ravel()
        for i, r in enumerate(c["rows"]):
            if exclude_entry_id is not None and r["entry_id"] == exclude_entry_id:
                sims[i] = 0
            elif author and r["eml"] == author:
                sims[i] = 0
        if sims.size:
            j = int(sims.argmax())
            dup = {"similarity": round(float(sims[j]), 3), "entry_id": int(c["rows"][j]["entry_id"])}
    if dup["similarity"] >= 0.9:
        pts -= 45; reasons.append("near-identical to another traveller's journal")
    elif dup["similarity"] >= 0.75:
        pts -= 25; reasons.append("very similar to another traveller's journal")

    unusual = False
    if c["iso"] is not None and f["n_words"] >= 15:
        unusual = int(c["iso"].predict([[f[k] for k in _KEYS]])[0]) == -1
        if unusual and not reasons:
            pts -= 6; reasons.append("statistically unusual compared with other journals")

    pts = int(max(0, min(100, round(pts))))
    level = "likely genuine" if pts >= 70 else ("needs a look" if pts >= 45 else "likely low quality or copied")
    return {"score": pts, "level": level, "reasons": reasons, "nearest_duplicate": dup, "features": {
        k: (round(v, 3) if isinstance(v, float) else v) for k, v in f.items()}}


def reload():
    global _CORPUS
    _CORPUS = None
