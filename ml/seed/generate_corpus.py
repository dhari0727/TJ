"""
JourneyAI — synthetic corpus generator.

Populates the `project` DB with a realistic, diverse training corpus so every
model (NLP, recommender, cost) has signal:

  - `destinations`  : from the curated catalog
  - `signup`        : ~40 synthetic users, each with a latent "taste profile"
  - `db`/`db1`/`db2`/`db3` : ~500 journals with templated narratives, correlated
                      costs split across the EXACT positional columns the legacy
                      wizard uses, and seasonal dates
  - `interactions`  : ~4,000 ratings clustered by taste (so collaborative
                      filtering finds real neighbour structure)

All seeded rows are tagged with the sentinel email domain `@seed.journeyai`
so `--truncate` removes ONLY seed data and never touches real users.

Usage:
    python ml/seed/generate_corpus.py --seed 42 --n 500
    python ml/seed/generate_corpus.py --truncate          # remove seed rows only
    python ml/seed/generate_corpus.py --seed 42 --n 500 --truncate  # reset + reseed

Determinism: everything is driven by random.Random(seed) / numpy default_rng(seed).
"""
import argparse
import random
import sys
import os
from datetime import date, timedelta

import numpy as np

# Allow running as a script (python ml/seed/generate_corpus.py) or as a module.
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..")))
from ml.db import get_connection  # noqa: E402
from ml.seed.destination_catalog import get_destinations, ACTIVITIES  # noqa: E402

SEED_DOMAIN = "@seed.journeyai"

# ---------------------------------------------------------------------------
# Narrative building blocks (templated NLG — no LLM).
# Sentiment-varied adjective pools give the lexicon-based sentiment step signal.
# ---------------------------------------------------------------------------
POS_ADJ = ["breathtaking", "unforgettable", "stunning", "magical", "delightful",
           "serene", "vibrant", "charming", "spectacular", "wonderful",
           "peaceful", "gorgeous", "incredible", "lovely", "memorable"]
NEG_ADJ = ["disappointing", "overcrowded", "overpriced", "underwhelming", "chaotic",
           "exhausting", "frustrating", "dull", "stressful", "mediocre"]
NEU_ADJ = ["decent", "average", "okay", "fine", "reasonable", "ordinary", "fair"]

FIRST_NAMES = ["Aarav", "Diya", "Kabir", "Isha", "Vivaan", "Ananya", "Reyansh", "Sara",
               "Arjun", "Myra", "Aditya", "Kiara", "Rohan", "Anaya", "Krish", "Riya",
               "Dev", "Tara", "Ayaan", "Zara", "Ishaan", "Nisha", "Veer", "Meera",
               "Laksh", "Pihu", "Aryan", "Navya", "Shaurya", "Aisha", "Yash", "Kavya",
               "Rudra", "Anvi", "Advait", "Saanvi", "Vihaan", "Ira", "Karan", "Diya2"]
LAST_NAMES = ["Sharma", "Patel", "Nair", "Reddy", "Iyer", "Singh", "Gupta", "Mehta",
              "Kapoor", "Rao", "Joshi", "Desai", "Bose", "Menon", "Chopra", "Verma"]

# Sentence templates keyed by narrative slot. {} placeholders filled per journal.
INTRO_T = [
    "We spent {days} days exploring {city} and it was {adj}.",
    "My {days}-day trip to {city} turned out to be absolutely {adj}.",
    "{city} had been on my list for years, and it was {adj}.",
    "This was a {adj} {days}-day getaway to {city}, {country}.",
]
ACTIVITY_T = [
    "The {act} here was {adj} — easily the highlight of the trip.",
    "We loved the {act}; it felt {adj} from start to finish.",
    "If you enjoy {act}, {city} is {adj}.",
    "The {act} scene was {adj} and worth every minute.",
]
STAY_T = [
    "We stayed at {hotel}, which was {adj} and well located.",
    "Our stay at {hotel} was {adj}.",
    "{hotel} made a {adj} base for the days.",
]
COST_T = [
    "Overall the trip felt {adj} for the money we spent.",
    "On budget, it was a {adj} value for the experience.",
    "Costs were {adj}, roughly what we expected for {city}.",
]
CLOSE_T = [
    "Would I go back? {verdict}",
    "All in all, {verdict}",
    "In short — {verdict}",
]
VERDICT_POS = ["absolutely, and soon.", "yes, without a doubt.", "definitely recommend it."]
VERDICT_NEU = ["maybe, with different plans.", "perhaps, off-season.", "it was fine overall."]
VERDICT_NEG = ["probably not, honestly.", "not in a hurry.", "I'd pick elsewhere next time."]

TRAVEL_MODES = ["flight", "train", "car", "bus"]

# Category split ratios of true_total (must sum ~1.0). Slight per-journal noise added.
CAT_RATIOS = {"accommodation": 0.35, "food": 0.25, "transport": 0.20,
              "shopping": 0.12, "fees_misc": 0.08}

STYLE_MULT = {"budget": 0.7, "backpacker": 0.6, "mid-range": 1.0,
              "family": 1.15, "solo": 0.85, "adventure": 1.05, "luxury": 1.8}


def pick_sentiment(rng, positivity):
    """Return (label, adj_pool) given a 0..1 positivity propensity."""
    r = rng.random()
    if r < positivity:
        return "positive", POS_ADJ
    if r < positivity + 0.20:
        return "neutral", NEU_ADJ
    return "negative", NEG_ADJ


def money(rng, amount):
    """Round a rupee amount to a tidy-ish integer string."""
    return str(int(round(amount / 10.0) * 10))


def split_costs(rng, true_total):
    """Split true_total into the five category buckets with mild noise."""
    buckets = {}
    for k, base in CAT_RATIOS.items():
        buckets[k] = max(0.0, base * (1 + rng.uniform(-0.15, 0.15)))
    s = sum(buckets.values())
    for k in buckets:
        buckets[k] = true_total * buckets[k] / s
    return buckets


def make_users(rng, n_users):
    """Create users with latent taste profiles (weighting over ACTIVITIES)."""
    users = []
    for i in range(n_users):
        fn = rng.choice(FIRST_NAMES)
        ln = rng.choice(LAST_NAMES)
        eml = f"{fn.lower()}.{ln.lower()}{i}{SEED_DOMAIN}"
        # latent taste: favour 2-4 activities strongly
        faves = rng.sample(ACTIVITIES, rng.randint(2, 4))
        taste = {a: (3.0 if a in faves else 1.0) for a in ACTIVITIES}
        positivity = rng.uniform(0.55, 0.85)  # personality: how positive they write
        budget_bias = rng.choice(["budget", "mid-range", "mid-range", "luxury"])
        users.append({"fname": fn, "lname": ln, "eml": eml, "faves": faves,
                      "taste": taste, "positivity": positivity, "budget_bias": budget_bias})
    return users


def dest_affinity(user, dest):
    """How well a destination matches a user's taste (for rating clustering)."""
    return sum(user["taste"].get(a, 1.0) for a in dest["activities"])


def seasonal_start_date(rng, dest):
    """Pick a visit-start date, biased toward the destination's peak months."""
    year = rng.choice([2024, 2025])
    month = rng.choice(dest["season_peak"]) if rng.random() < 0.7 else rng.randint(1, 12)
    day = rng.randint(1, 27)
    return date(year, month, day)


def build_narrative(rng, user, dest, days, hotel, label, adj_pool):
    city = dest["city"]
    country = dest["country"]
    acts = dest["activities"]
    parts = [
        rng.choice(INTRO_T).format(days=days, city=city, country=country, adj=rng.choice(adj_pool)),
        rng.choice(ACTIVITY_T).format(act=rng.choice(acts), adj=rng.choice(adj_pool), city=city),
    ]
    if len(acts) > 1:
        parts.append(rng.choice(ACTIVITY_T).format(
            act=rng.choice([a for a in acts if a != acts[0]] or acts),
            adj=rng.choice(adj_pool), city=city))
    parts.append(rng.choice(STAY_T).format(hotel=hotel, adj=rng.choice(adj_pool)))
    parts.append(rng.choice(COST_T).format(adj=rng.choice(adj_pool), city=city))
    verdict = {"positive": VERDICT_POS, "neutral": VERDICT_NEU, "negative": VERDICT_NEG}[label]
    parts.append(rng.choice(CLOSE_T).format(verdict=rng.choice(verdict)))
    return " ".join(parts)


def truncate_seed(conn):
    """Delete only seed-tagged rows (safe: never touches real users)."""
    like = f"%{SEED_DOMAIN}"
    with conn.cursor() as cur:
        for t in ("db1", "db2", "db3", "db"):
            cur.execute(f"DELETE FROM {t} WHERE eml LIKE %s", (like,))
        cur.execute("DELETE FROM interactions WHERE eml LIKE %s", (like,))
        cur.execute("DELETE FROM journal_features WHERE eml LIKE %s", (like,))
        cur.execute("DELETE FROM signup WHERE eml LIKE %s", (like,))
        # destinations are reference data seeded fresh each run
        cur.execute("DELETE FROM destinations")
    conn.commit()


def seed(n_journals, seed_val, n_users):
    rng = random.Random(seed_val)
    np.random.seed(seed_val)
    dests = get_destinations()
    conn = get_connection(dict_cursor=False)
    try:
        # 1) destinations reference
        with conn.cursor() as cur:
            cur.executemany(
                "INSERT INTO destinations (canonical_name, country, city, region, "
                "popularity_tier, base_daily_cost) VALUES (%s,%s,%s,%s,%s,%s)",
                [(d["name"], d["country"], d["city"], d["region"], d["tier"],
                  d["base_daily_inr"]) for d in dests],
            )
        conn.commit()

        # 2) users
        users = make_users(rng, n_users)
        with conn.cursor() as cur:
            cur.executemany(
                "INSERT INTO signup (fname, lname, eml, psw) VALUES (%s,%s,%s,%s)",
                [(u["fname"], u["lname"], u["eml"], "seed-plaintext") for u in users],
            )
        conn.commit()

        db_rows, db1_rows, db2_rows, db3_rows = [], [], [], []
        interactions = []
        used_titles = set()

        # 3) journals
        for j in range(n_journals):
            user = rng.choice(users)
            # bias destination choice toward the user's taste
            weights = np.array([dest_affinity(user, d) for d in dests], dtype=float)
            weights = weights / weights.sum()
            dest = dests[int(np.random.choice(len(dests), p=weights))]

            days = rng.randint(2, 14)
            style = user["budget_bias"] if rng.random() < 0.6 else rng.choice(dest["styles"])
            mult = STYLE_MULT.get(style, 1.0)

            start = seasonal_start_date(rng, dest)
            end = start + timedelta(days=days)
            created = start - timedelta(days=rng.randint(1, 30))

            # correlated cost
            base = dest["base_daily_inr"] * days * mult
            true_total = base * rng.uniform(0.85, 1.15)
            cat = split_costs(rng, true_total)

            label, adj_pool = pick_sentiment(rng, user["positivity"])
            hotel = f"{rng.choice(['The', 'Hotel', 'Casa', 'Villa', 'Grand'])} {dest['city']} {rng.choice(['Stay', 'Retreat', 'Inn', 'Residency', 'Nest'])}"

            title = f"{dest['city']} {rng.choice(['Escape', 'Diaries', 'Trip', 'Getaway', 'Journey', 'Days', 'Tales'])} {j+1}"
            while title in used_titles:
                title += "*"
            used_titles.add(title)

            desc = build_narrative(rng, user, dest, days, hotel, label, adj_pool)
            ptv = ", ".join(rng.sample(dest["attractions"], min(len(dest["attractions"]),
                                                                rng.randint(2, 4))))
            tv = rng.choice(TRAVEL_MODES)
            addr = f"{dest['city']}, {dest['country']}"
            eml = user["eml"]

            # --- db (named cols; entry_id auto) ---
            db_rows.append((title, desc, dest["country"], dest["city"],
                            created.isoformat(), start.isoformat(), end.isoformat(),
                            hotel, addr, ptv, tv, eml))

            # --- db1 (POSITIONAL 16): food + transport line items ---
            food = cat["food"]
            trans = cat["transport"]
            f_split = np.random.dirichlet([2, 2, 1, 2, 1]) * food     # bf,lunch,snacks,dinner,bev
            t_split = np.random.dirichlet([1, 1, 1, 2, 1, 1, 1]) * trans  # car,taxi,train,flight,ug,pass,others
            db1_rows.append((
                money(rng, f_split[0]), money(rng, f_split[1]), money(rng, f_split[2]),
                money(rng, f_split[3]), money(rng, f_split[4]),
                money(rng, t_split[0]), money(rng, t_split[1]), money(rng, t_split[2]),
                money(rng, t_split[3]), money(rng, t_split[4]), money(rng, t_split[5]),
                money(rng, t_split[6]),
                money(rng, food), money(rng, trans),  # FOOD_COST, TRANSPORT_COST
                title, eml,
            ))

            # --- db2 (POSITIONAL 11): accommodation & misc ---
            acc = cat["accommodation"]
            a_split = np.random.dirichlet([5, 1, 1, 1, 1, 1, 1, 1, 1]) * acc
            db2_rows.append((
                money(rng, a_split[0]), money(rng, a_split[1]), money(rng, a_split[2]),
                money(rng, a_split[3]), money(rng, a_split[4]), money(rng, a_split[5]),
                money(rng, a_split[6]), money(rng, a_split[7]), money(rng, a_split[8]),
                title, eml,
            ))

            # --- db3 (POSITIONAL 17): fees + shopping + legacy grand ---
            shop = cat["shopping"]
            fees = cat["fees_misc"]
            fee_split = np.random.dirichlet([1, 1, 1, 1, 1]) * (fees * 0.6)   # mue,pr,ci,cl,pt
            shop_split = np.random.dirichlet([2, 1, 2, 1, 2, 1]) * shop        # clt,carr,gi,fl,so,stamps
            misc_amt = fees * 0.4
            fees_sub = float(fee_split.sum())
            shop_sub = float(shop_split.sum())
            # legacy grand (buggy): food+transport+fees_sub+shop_sub, OMITS db2 (accommodation)
            legacy_grand = food + trans + fees_sub + shop_sub
            db3_rows.append((
                money(rng, fee_split[0]), money(rng, fee_split[1]), money(rng, fee_split[2]),
                money(rng, fee_split[3]), money(rng, fee_split[4]),
                money(rng, shop_split[0]), money(rng, shop_split[1]), money(rng, shop_split[2]),
                money(rng, shop_split[3]), money(rng, shop_split[4]), money(rng, shop_split[5]),
                money(rng, misc_amt),
                money(rng, fees_sub), money(rng, shop_sub),
                title, money(rng, legacy_grand), eml,
            ))

            # the author's own positive interaction with this destination
            interactions.append((eml, dest["name"], None,
                                 {"positive": 5, "neutral": 3, "negative": 2}[label],
                                 "rating"))

        # bulk insert journals
        with conn.cursor() as cur:
            cur.executemany(
                "INSERT INTO db(Title,Description,Country,City,cd,dv,dr,hn,address,ptv,tv,eml) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", db_rows)
            cur.executemany("INSERT INTO db1 VALUES (" + ",".join(["%s"] * 16) + ")", db1_rows)
            cur.executemany("INSERT INTO db2 VALUES (" + ",".join(["%s"] * 11) + ")", db2_rows)
            cur.executemany("INSERT INTO db3 VALUES (" + ",".join(["%s"] * 17) + ")", db3_rows)
        conn.commit()

        # 4) extra rating interactions clustered by taste (for CF neighbour structure)
        for user in users:
            n_extra = rng.randint(8, 20)
            weights = np.array([dest_affinity(user, d) for d in dests], dtype=float)
            probs = weights / weights.sum()
            chosen = np.random.choice(len(dests), size=min(n_extra, len(dests)),
                                      replace=False, p=probs)
            for di in chosen:
                d = dests[di]
                aff = dest_affinity(user, d)
                aff_norm = (aff - weights.min()) / (weights.max() - weights.min() + 1e-9)
                # taste-aligned destinations get high ratings, others low
                rating = int(np.clip(round(2 + aff_norm * 3 + rng.uniform(-0.6, 0.6)), 1, 5))
                interactions.append((user["eml"], d["name"], None, rating, "rating"))

        with conn.cursor() as cur:
            cur.executemany(
                "INSERT INTO interactions (eml, destination, entry_id, rating, interaction_type) "
                "VALUES (%s,%s,%s,%s,%s)", interactions)
        conn.commit()

        return {
            "destinations": len(dests),
            "users": len(users),
            "journals": len(db_rows),
            "interactions": len(interactions),
        }
    finally:
        conn.close()


def main():
    ap = argparse.ArgumentParser(description="Generate JourneyAI synthetic corpus.")
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--n", type=int, default=500, help="number of journals")
    ap.add_argument("--users", type=int, default=40)
    ap.add_argument("--truncate", action="store_true",
                    help="delete existing seed-tagged rows first")
    args = ap.parse_args()

    conn = get_connection(dict_cursor=False)
    try:
        if args.truncate:
            print("Truncating existing seed data...")
            truncate_seed(conn)
    finally:
        conn.close()

    print(f"Seeding {args.n} journals, {args.users} users (seed={args.seed})...")
    stats = seed(args.n, args.seed, args.users)
    print("Done:", ", ".join(f"{k}={v}" for k, v in stats.items()))


if __name__ == "__main__":
    main()
