"""
JourneyAI — NLP feature extraction pipeline (P1 core).

Reads the `journals` view, and for each entry derives a structured feature
record written to `journal_features`:

    canonical_dest   normalized destination (ml/nlp/normalize)
    budget_bucket    from daily spend (true_total / duration) via corpus quantiles
    duration_days    from the view
    activities       canonical activity tags (gazetteer over Description+ptv)
    travel_style     rule-based from budget + activities + keywords
    sentiment_score  lexicon-based (-1..1) with negation/intensifier handling
    sentiment_label  negative / neutral / positive

Also fits a TF-IDF vectorizer over the journal narratives and pickles the
vectorizer + matrix + a per-journal feature DataFrame to ml/artifacts/ for
reuse by the recommender (so it never re-vectorizes at request time).

Run:
    python -m ml.nlp.extract_features
"""
import os
import re
import pickle

import numpy as np
import pandas as pd
from sklearn.feature_extraction.text import TfidfVectorizer

from ml.db import get_connection, fetch_df
from ml.nlp.lexicon import (match_activities, POSITIVE_WORDS, NEGATIVE_WORDS,
                            NEGATORS, INTENSIFIERS, CANONICAL_ACTIVITIES)
from ml.nlp.normalize import canonical_name
from ml.nlp.tokenizer import tokenize  # shared, picklable tokenizer

ARTIFACT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "artifacts"))

_TOKEN = re.compile(r"[a-z']+")

STYLE_KEYWORDS = {
    "luxury": ["luxury", "resort", "5-star", "five star", "premium", "spa", "suite"],
    "backpacker": ["backpack", "hostel", "cheap", "shoestring", "budget", "dorm"],
    "family": ["family", "kids", "children", "parents"],
    "solo": ["solo", "alone", "myself", "on my own"],
    "adventure": ["adventure", "trek", "rafting", "hike", "thrill", "climb"],
}


def sentiment(text):
    """Lexicon sentiment with negation + intensifier handling. Returns (score,label)."""
    toks = _TOKEN.findall((text or "").lower())
    if not toks:
        return 0.0, "neutral"
    score = 0.0
    hits = 0
    for i, tok in enumerate(toks):
        val = 0.0
        if tok in POSITIVE_WORDS:
            val = 1.0
        elif tok in NEGATIVE_WORDS:
            val = -1.0
        if val == 0.0:
            continue
        # look back up to 2 tokens for negators / intensifiers
        weight = 1.0
        negate = False
        for j in (i - 1, i - 2):
            if j >= 0:
                if toks[j] in NEGATORS:
                    negate = True
                if toks[j] in INTENSIFIERS:
                    weight = 1.6
        if negate:
            val = -val
        score += val * weight
        hits += 1
    if hits == 0:
        return 0.0, "neutral"
    norm = float(np.clip(score / (hits + 1), -1.0, 1.0))
    label = "positive" if norm > 0.15 else ("negative" if norm < -0.15 else "neutral")
    return round(norm, 3), label


def budget_bucket_from_quantiles(daily_spend, edges):
    """Map a daily spend to a bucket using precomputed quantile edges."""
    names = ["shoestring", "budget", "mid", "premium", "luxury"]
    for i, e in enumerate(edges):
        if daily_spend <= e:
            return names[i]
    return names[-1]


def classify_style(budget_bucket, activities, text):
    """Rule-based travel-style classifier."""
    t = (text or "").lower()
    for style, kws in STYLE_KEYWORDS.items():
        if any(k in t for k in kws):
            return style
    if budget_bucket in ("premium", "luxury"):
        return "luxury"
    if budget_bucket == "shoestring":
        return "backpacker"
    if {"trekking", "adventure", "mountains"} & set(activities):
        return "adventure"
    return "mid-range"


def extract():
    print("Loading journals view...")
    df = fetch_df(
        "SELECT entry_id, eml, Title, Description, Country, City, ptv, "
        "duration_days, true_total FROM journals")
    if df.empty:
        print("No journals found — seed the corpus first.")
        return

    df["duration_days"] = pd.to_numeric(df["duration_days"], errors="coerce").fillna(1).clip(lower=1)
    df["true_total"] = pd.to_numeric(df["true_total"], errors="coerce").fillna(0.0)
    df["daily_spend"] = df["true_total"] / df["duration_days"]

    # budget quantile edges over the corpus (20/40/60/80 percentiles)
    edges = list(np.quantile(df["daily_spend"], [0.2, 0.4, 0.6, 0.8]))

    # combined narrative text per journal
    df["text"] = (df["Description"].fillna("") + " " + df["ptv"].fillna("")).str.strip()

    print(f"Extracting features for {len(df)} journals...")
    records = []
    for _, r in df.iterrows():
        acts = sorted(match_activities(r["text"]))
        bucket = budget_bucket_from_quantiles(r["daily_spend"], edges)
        style = classify_style(bucket, acts, r["text"])
        s_score, s_label = sentiment(r["Description"] or "")
        dest = canonical_name(r["City"], r["Country"], r["text"])
        records.append({
            "entry_id": int(r["entry_id"]),
            "eml": r["eml"],
            "canonical_dest": dest,
            "budget_bucket": bucket,
            "duration_days": int(r["duration_days"]),
            "activities": acts,
            "travel_style": style,
            "sentiment_score": s_score,
            "sentiment_label": s_label,
        })

    feat = pd.DataFrame(records)

    # --- TF-IDF over the narratives (reused by the recommender) ---
    print("Fitting TF-IDF...")
    # Tokenize the sklearn English stop list the SAME way as documents so the
    # lemmatized forms line up (avoids the "inconsistent stop_words" warning).
    from sklearn.feature_extraction.text import ENGLISH_STOP_WORDS
    stop_lemmas = sorted({w for s in ENGLISH_STOP_WORDS for w in tokenize(s)})
    vec = TfidfVectorizer(tokenizer=tokenize, token_pattern=None,
                          stop_words=stop_lemmas, ngram_range=(1, 2),
                          min_df=3, max_df=0.6, max_features=4000)
    tfidf = vec.fit_transform(df["text"])

    # --- persist artifacts ---
    os.makedirs(ARTIFACT_DIR, exist_ok=True)
    with open(os.path.join(ARTIFACT_DIR, "tfidf_vectorizer.pkl"), "wb") as f:
        pickle.dump(vec, f)
    with open(os.path.join(ARTIFACT_DIR, "tfidf_matrix.pkl"), "wb") as f:
        pickle.dump(tfidf, f)
    feat.assign(entry_order=df["entry_id"].values).to_pickle(
        os.path.join(ARTIFACT_DIR, "journal_features.pkl"))
    # keep the entry_id ordering aligned with the tfidf matrix rows
    with open(os.path.join(ARTIFACT_DIR, "tfidf_entry_ids.pkl"), "wb") as f:
        pickle.dump(df["entry_id"].tolist(), f)

    # --- write journal_features table ---
    print("Writing journal_features table...")
    import json
    rows = [(
        rec["entry_id"], rec["eml"], rec["canonical_dest"], rec["budget_bucket"],
        rec["duration_days"], json.dumps(rec["activities"]), rec["travel_style"],
        rec["sentiment_score"], rec["sentiment_label"],
    ) for rec in records]
    conn = get_connection(dict_cursor=False)
    try:
        with conn.cursor() as cur:
            cur.execute("DELETE FROM journal_features")
            cur.executemany(
                "INSERT INTO journal_features (entry_id, eml, canonical_dest, "
                "budget_bucket, duration_days, activities, travel_style, "
                "sentiment_score, sentiment_label) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)", rows)
        conn.commit()
    finally:
        conn.close()

    # summary
    print("\n=== Extraction summary ===")
    print("journals:", len(feat))
    print("budget buckets:", feat["budget_bucket"].value_counts().to_dict())
    print("sentiment labels:", feat["sentiment_label"].value_counts().to_dict())
    print("styles:", feat["travel_style"].value_counts().to_dict())
    print("dest resolved:", feat["canonical_dest"].notna().sum(), "/", len(feat))
    print("avg activities/journal:", round(feat["activities"].apply(len).mean(), 2))
    print("TF-IDF matrix:", tfidf.shape, "vocab:", len(vec.vocabulary_))
    print("artifacts ->", ARTIFACT_DIR)


if __name__ == "__main__":
    extract()
