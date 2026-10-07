"""
JourneyAI — evaluation of the NLP module (member D24DCS154).

Four things the NLP code does, four tests:
  1. Sentiment (positive / neutral / negative)      -> a small hand-labelled sentence set  (accuracy, F1, confusion)
  2. Activity tags from text (beach, temples, ...)  -> compare tags found in real Wikivoyage text with the
                                                       hand-curated activity list of the same destination (precision/recall/F1)
  3. Destination name matching ("Bombay" -> Mumbai) -> messy spellings generated from the catalog (accuracy) and
                                                       junk strings that must NOT match (false-positive rate)
  4. Understanding a trip query ("weekend from Udaipur, temples") -> 40 labelled queries (field accuracy)

    ml/venv/Scripts/python.exe -m ml.eval.nlp_eval
"""
import json
import os
import random
import re
import sys

import numpy as np
import pandas as pd

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
DATA = os.path.join(ROOT, "ml", "data")

# ---------------------------------------------------------------------------------------------------------------
# 1. sentiment: 60 sentences written and labelled by the team (20 per class). Includes negation, intensifiers, mixed.
# ---------------------------------------------------------------------------------------------------------------
SENTIMENT_SET = [
    # positive
    ("The sunrise over the lake was absolutely breathtaking and the staff were wonderful.", "positive"),
    ("We loved every minute of the trip, the food was delicious and the people were so friendly.", "positive"),
    ("A perfect getaway, peaceful, clean and beautiful.", "positive"),
    ("The fort is stunning and well maintained, totally worth the ticket.", "positive"),
    ("Great value for money and the hotel had an amazing view.", "positive"),
    ("It was an unforgettable experience, the best trip we have had in years.", "positive"),
    ("The beach was lovely and the seafood was fantastic.", "positive"),
    ("Very peaceful temple, the evening aarti was magical.", "positive"),
    ("Our guide was excellent and the trek was not difficult at all, just beautiful.", "positive"),
    ("The market was vibrant and colourful, we enjoyed shopping there.", "positive"),
    ("Charming town with friendly locals and gorgeous views.", "positive"),
    ("The safari was thrilling and we spotted a tiger, highly recommended.", "positive"),
    ("Clean rooms, comfortable beds and superb breakfast.", "positive"),
    ("We were delighted with the homestay, so warm and welcoming.", "positive"),
    ("The waterfall is spectacular after the monsoon.", "positive"),
    ("Smooth journey, comfortable train and a pleasant arrival.", "positive"),
    ("What a gem, the old city is fascinating and full of history.", "positive"),
    ("The sunset point is incredible, we stayed until dark.", "positive"),
    ("Delicious street food at very reasonable prices.", "positive"),
    ("The museum was interesting and very well organised.", "positive"),
    # negative
    ("The place was overcrowded and dirty, a big disappointment.", "negative"),
    ("Terrible service, the room was dirty and the staff were rude.", "negative"),
    ("It was overpriced and the food was awful.", "negative"),
    ("We waited two hours in the queue and it was exhausting and not worth it.", "negative"),
    ("The road was in bad condition and the journey was stressful.", "negative"),
    ("Very disappointing, the lake was polluted and smelly.", "negative"),
    ("The hotel was noisy and uncomfortable, we could not sleep.", "negative"),
    ("Tourist traps everywhere and the guides kept cheating us.", "negative"),
    ("The temple was chaotic and the touts were annoying.", "negative"),
    ("A boring, overrated place, I would not go again.", "negative"),
    ("The train was delayed by six hours and the toilets were filthy.", "negative"),
    ("Not good at all, the beach is littered with plastic.", "negative"),
    ("The food was bland and the prices were ridiculous.", "negative"),
    ("Worst experience of our trip, the driver was unsafe.", "negative"),
    ("The view was spoiled by garbage and crowds.", "negative"),
    ("Poor maintenance and no one at the ticket counter, very frustrating.", "negative"),
    ("It was not clean and not comfortable.", "negative"),
    ("The trek was dangerous and badly organised.", "negative"),
    ("Mediocre hotel, slow service and expensive for what you get.", "negative"),
    ("I regret coming, nothing was as advertised.", "negative"),
    # neutral
    ("The museum opens at 10 am and closes at 5 pm.", "neutral"),
    ("We took the overnight train from Ahmedabad and reached by morning.", "neutral"),
    ("The fort has three gates and a small courtyard.", "neutral"),
    ("Entry tickets are sold at the main gate near the parking area.", "neutral"),
    ("The bus to the hill station leaves every hour from the stand.", "neutral"),
    ("There are two hotels and one guest house near the lake.", "neutral"),
    ("The temple is about 12 km from the railway station.", "neutral"),
    ("We stayed for three nights and visited the market on the second day.", "neutral"),
    ("The beach is on the western side of the town.", "neutral"),
    ("Breakfast is served between 7 and 10 in the morning.", "neutral"),
    ("The park is open on all days except Monday.", "neutral"),
    ("A taxi from the airport costs around 600 rupees.", "neutral"),
    ("The trek starts at the village and takes about four hours.", "neutral"),
    ("The old city is walled and has several narrow lanes.", "neutral"),
    ("We booked the tickets online two weeks before the trip.", "neutral"),
    ("The region receives most of its rain in July and August.", "neutral"),
    ("The palace was built in the 18th century.", "neutral"),
    ("Local buses run from the market to the lake.", "neutral"),
    ("There is a small cafe next to the ticket counter.", "neutral"),
    ("The distance between the two towns is roughly 90 km.", "neutral"),
]


def eval_sentiment():
    from ml.nlp.extract_features import sentiment
    y_true = [l for _, l in SENTIMENT_SET]
    y_pred = [sentiment(t)[1] for t, _ in SENTIMENT_SET]
    labels = ["positive", "neutral", "negative"]
    cm = pd.crosstab(pd.Series(y_true, name="true"), pd.Series(y_pred, name="predicted")).reindex(index=labels, columns=labels, fill_value=0)
    per = {}
    for c in labels:
        tp = cm.loc[c, c]; fp = cm[c].sum() - tp; fn = cm.loc[c].sum() - tp
        p = tp / (tp + fp) if tp + fp else 0.0; r = tp / (tp + fn) if tp + fn else 0.0
        per[c] = {"precision": round(p, 3), "recall": round(r, 3), "f1": round(2 * p * r / (p + r), 3) if p + r else 0.0, "support": int(cm.loc[c].sum())}
    acc = float(np.mean([a == b for a, b in zip(y_true, y_pred)]))
    macro = float(np.mean([v["f1"] for v in per.values()]))
    wrong = [(t, l, p) for (t, l), p in zip(SENTIMENT_SET, y_pred) if l != p]
    return {"sentences": len(SENTIMENT_SET), "accuracy": round(acc, 3), "macro_f1": round(macro, 3),
            "majority_class_baseline_accuracy": round(1 / 3, 3), "per_class": per, "confusion": cm, "mistakes": wrong}


# ---------------------------------------------------------------------------------------------------------------
# 2. activity tags: tags extracted from real Wikivoyage text vs the hand-curated activities of the same place
# ---------------------------------------------------------------------------------------------------------------
def eval_activity_tags(top_k=4):
    from ml.scrape.build_india_dataset import detect_activities
    from ml.seed.destination_catalog import DESTINATIONS
    entries = json.load(open(os.path.join(DATA, "real_entries.json"), encoding="utf8"))
    text_by = {}
    for e in entries:
        text_by.setdefault(e["destination"], []).append(e["text"])
    rows, tp = [], {}
    TP = FP = FN = 0
    for d in DESTINATIONS:
        if d["name"] not in text_by:
            continue
        truth = set(d["activities"])
        pred = set(detect_activities(" ".join(text_by[d["name"]]), n=top_k))
        t, f, n = len(truth & pred), len(pred - truth), len(truth - pred)
        TP += t; FP += f; FN += n
        for a in truth & pred: tp.setdefault(a, [0, 0, 0])[0] += 1
        for a in pred - truth: tp.setdefault(a, [0, 0, 0])[1] += 1
        for a in truth - pred: tp.setdefault(a, [0, 0, 0])[2] += 1
        rows.append({"destination": d["name"], "curated": sorted(truth), "from_text": sorted(pred), "hit": t})
    p = TP / (TP + FP) if TP + FP else 0.0; r = TP / (TP + FN) if TP + FN else 0.0
    per = pd.DataFrame([{"activity": a, "tp": v[0], "fp": v[1], "fn": v[2],
                         "precision": round(v[0] / (v[0] + v[1]), 2) if v[0] + v[1] else 0.0,
                         "recall": round(v[0] / (v[0] + v[2]), 2) if v[0] + v[2] else 0.0} for a, v in tp.items()]).sort_values("tp", ascending=False)
    return {"destinations compared": len(rows), "precision": round(p, 3), "recall": round(r, 3),
            "f1": round(2 * p * r / (p + r), 3) if p + r else 0.0, "per_activity": per, "examples": pd.DataFrame(rows).head(12)}


# ---------------------------------------------------------------------------------------------------------------
# 3. destination name matching under messy input
# ---------------------------------------------------------------------------------------------------------------
ALIAS_CASES = [("Bombay", "Mumbai"), ("Calcutta", "Kolkata"), ("Madras", "Chennai"), ("Bangalore", "Bengaluru"),
               ("Benares", "Varanasi"), ("Banaras", "Varanasi"), ("Pondicherry", "Puducherry")]
JUNK = ["Springfield", "Hogwarts", "asdfgh", "Atlantis", "Gotham", "Narnia", "xyz123", "Wakanda", "Mordor", "Lorem ipsum",
        "Tatooine", "Hawkins", "Westeros", "Pandora", "Neverland", "Rivendell", "Zzzzz", "Foobar", "Hobbiton", "Sunnydale"]


def _typo(word, rng):
    if len(word) < 5:
        return word
    kind = rng.choice(["drop", "swap", "double", "replace"])
    i = rng.randrange(1, len(word) - 1)
    if kind == "drop":
        return word[:i] + word[i + 1:]
    if kind == "swap":
        return word[:i] + word[i + 1] + word[i] + word[i + 2:] if i + 2 <= len(word) else word
    if kind == "double":
        return word[:i] + word[i] + word[i:]
    return word[:i] + rng.choice("aeiou") + word[i + 1:]


def eval_normalization(n=200, seed=7):
    from ml.nlp.normalize import normalize
    from ml.seed.destination_catalog import get_destinations
    rng = random.Random(seed)
    dests = [d for d in get_destinations() if len(d["city"]) >= 5]
    sample = rng.sample(dests, min(n, len(dests)))
    kinds = {"exact": [], "lower/upper case": [], "extra spaces": [], "one-letter typo": [], "city only (no country)": []}
    for d in sample:
        c, co = d["city"], d["country"]
        kinds["exact"].append((c, co, d["name"]))
        kinds["lower/upper case"].append((c.upper() if rng.random() < .5 else c.lower(), co.lower(), d["name"]))
        kinds["extra spaces"].append(("  " + c + "  ", co, d["name"]))
        kinds["one-letter typo"].append((_typo(c, rng), co, d["name"]))
        kinds["city only (no country)"].append((c, "", d["name"]))
    out = []
    for k, cases in kinds.items():
        ok = 0
        for c, co, want in cases:
            got = normalize(c, co)
            # a different destination with the SAME city name counts as correct (e.g. two "Gir")
            ok += bool(got) and (got["name"] == want or got["city"].lower() == want.split(",")[0].lower())
        out.append({"test": k, "cases": len(cases), "accuracy (%)": round(ok / len(cases) * 100, 1)})
    alias_ok = sum(1 for a, want in ALIAS_CASES if (normalize(a, "India") or {}).get("city") == want)
    out.append({"test": "known alias (Bombay, Madras, ...)", "cases": len(ALIAS_CASES), "accuracy (%)": round(alias_ok / len(ALIAS_CASES) * 100, 1)})
    fp = sum(1 for j in JUNK if normalize(j, "") is not None)
    return {"table": pd.DataFrame(out), "junk_strings": len(JUNK), "false_matches": fp,
            "false_positive_rate (%)": round(fp / len(JUNK) * 100, 1)}


# ---------------------------------------------------------------------------------------------------------------
# 4. understanding a trip query (rule-based parser; the LLM parser is an optional upgrade on top of it)
# ---------------------------------------------------------------------------------------------------------------
QUERY_SET = [
    ("weekend from Ahmedabad, temples and food", {"origin": "ahmedabad", "days": 2, "interests": {"temples", "food"}}),
    ("1 day near Anand, ice cream and gardens", {"origin": "anand", "days": 1}),
    ("3 days from Mumbai beaches budget 25000", {"origin": "mumbai", "days": 3, "budget": 25000, "interests": {"beach"}}),
    ("weekend in Udaipur", {"origin": "udaipur", "days": 2}),
    ("5 day trip from Delhi to Manali", {"origin": "delhi", "destination": "manali", "days": 5}),
    ("Udaipur to Jaipur route", {"origin": "udaipur", "destination": "jaipur"}),
    ("2 days near Surat, museums", {"origin": "surat", "days": 2, "interests": {"museums"}}),
    ("trekking and mountains, 6 days, 50000", {"days": 6, "budget": 50000, "interests": {"trekking", "mountains"}}),
    ("beaches under 20000", {"budget": 20000, "interests": {"beach"}}),
    ("1 day near me, temples", {"origin": "near me", "days": 1, "interests": {"temples"}}),
    ("weekend from Pune, food and nightlife", {"origin": "pune", "days": 2, "interests": {"food", "nightlife"}}),
    ("4 days from Bengaluru, wildlife", {"origin": "bengaluru", "days": 4, "interests": {"wildlife"}}),
    ("one day trip around Vadodara, history", {"origin": "vadodara", "days": 1, "interests": {"history"}}),
    ("snow and mountains under 30000", {"budget": 30000, "interests": {"snow", "mountains"}}),
    ("weekend from Jaipur, shopping", {"origin": "jaipur", "days": 2, "interests": {"shopping"}}),
    ("3 days in Goa", {"origin": "goa", "days": 3}),
    ("7 days from Kolkata, culture and food", {"origin": "kolkata", "days": 7, "interests": {"culture", "food"}}),
    ("day trip near Rajkot, temples", {"origin": "rajkot", "days": 1, "interests": {"temples"}}),
    ("Ahmedabad to Diu road trip", {"origin": "ahmedabad", "destination": "diu"}),
    ("2 days from Chennai, beach and relaxation", {"origin": "chennai", "days": 2, "interests": {"beach", "relaxation"}}),
    ("budget 15000 weekend from Nashik, nature", {"origin": "nashik", "days": 2, "budget": 15000, "interests": {"nature"}}),
    ("desert and photography, 4 days", {"days": 4, "interests": {"desert", "photography"}}),
    ("1 day near Anand, temples", {"origin": "anand", "days": 1, "interests": {"temples"}}),
    ("3 days from Hyderabad, history and architecture", {"origin": "hyderabad", "days": 3, "interests": {"history", "architecture"}}),
    ("weekend from Lucknow, food", {"origin": "lucknow", "days": 2, "interests": {"food"}}),
    ("10 days from Delhi, adventure and trekking", {"origin": "delhi", "days": 10, "interests": {"adventure", "trekking"}}),
    ("1 day near Nadiad, temples", {"origin": "nadiad", "days": 1, "interests": {"temples"}}),
    ("under 40000 for 5 days, beaches and diving", {"days": 5, "budget": 40000, "interests": {"beach", "diving"}}),
    ("weekend near Mysore, wildlife", {"origin": "mysore", "days": 2, "interests": {"wildlife"}}),
    ("2 days near Indore, food and shopping", {"origin": "indore", "days": 2, "interests": {"food", "shopping"}}),
    ("Mumbai to Goa route", {"origin": "mumbai", "destination": "goa"}),
    ("3 days from Bhopal, wildlife and nature", {"origin": "bhopal", "days": 3, "interests": {"wildlife", "nature"}}),
    ("weekend from Surat, beach", {"origin": "surat", "days": 2, "interests": {"beach"}}),
    ("6 days from Pune, mountains", {"origin": "pune", "days": 6, "interests": {"mountains"}}),
    ("1 day near Junagadh, history and temples", {"origin": "junagadh", "days": 1, "interests": {"history", "temples"}}),
    ("4 days from Ahmedabad, temples budget 20000", {"origin": "ahmedabad", "days": 4, "budget": 20000, "interests": {"temples"}}),
    ("weekend from Kochi, backwaters", {"origin": "kochi", "days": 2, "interests": {"backwaters"}}),
    ("2 days from Amritsar, culture", {"origin": "amritsar", "days": 2, "interests": {"culture"}}),
    ("day trip near Mount Abu, nature", {"origin": "mount abu", "days": 1, "interests": {"nature"}}),
    ("8 days from Delhi, snow", {"origin": "delhi", "days": 8, "interests": {"snow"}}),
]


def eval_intent():
    from ml.bot.smartplan import parse
    fields = {"origin": [0, 0], "destination": [0, 0], "days": [0, 0], "budget": [0, 0], "interests": [0, 0]}
    exact, rows = 0, []
    for q, want in QUERY_SET:
        got = parse(q)
        ok_all = True
        for f in fields:
            if f not in want:
                continue
            exp = want[f]
            val = got.get(f)
            if f == "origin":
                val = (val or "").lower().strip()
            if f == "destination":
                val = (val or "").lower().strip()
            if f == "interests":
                good = exp <= set(val or [])          # every wanted interest found (extra ones are tolerated)
            else:
                good = (val == exp)
            fields[f][0] += int(good); fields[f][1] += 1
            ok_all &= good
            if not good:
                rows.append({"query": q, "field": f, "expected": exp, "got": val})
        exact += int(ok_all)
    acc = {f: round(v[0] / v[1] * 100, 1) for f, v in fields.items() if v[1]}
    return {"queries": len(QUERY_SET), "all fields correct (%)": round(exact / len(QUERY_SET) * 100, 1),
            "field accuracy (%)": acc, "mistakes": pd.DataFrame(rows)}


def run_all():
    s, a, n, i = eval_sentiment(), eval_activity_tags(), eval_normalization(), eval_intent()
    return {"sentiment": {k: v for k, v in s.items() if k not in ("confusion", "mistakes")},
            "activity_tags": {k: v for k, v in a.items() if k not in ("per_activity", "examples")},
            "normalization": {"table": n["table"].to_dict("records"), "false_positive_rate (%)": n["false_positive_rate (%)"]},
            "query_understanding": {k: v for k, v in i.items() if k != "mistakes"}}


if __name__ == "__main__":
    print(json.dumps(run_all(), indent=1, default=float))
