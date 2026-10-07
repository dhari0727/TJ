"""
JourneyAI — load REAL-sourced entries (Wikivoyage text + web-blog extracts) into the journal tables.

Rows are tagged with the sentinel domain @real.journeyai so they can be removed/reloaded without
touching seed or real-user data. Text is verbatim source text with its URL kept in the address
column for attribution; costs are the destination's observed-price estimate (base_daily_inr x days),
NOT noise-perturbed, so the cost model sees grounded values.

    ml/venv/Scripts/python.exe -m ml.seed.load_real_entries [--truncate]
"""
import argparse
import json
import os
import random
import sys
from datetime import date, timedelta

import numpy as np

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.db import get_connection  # noqa: E402
from ml.seed.destination_catalog import get_destinations  # noqa: E402
from ml.seed.generate_corpus import money, split_costs  # noqa: E402

DOMAIN = "@real.journeyai"
DATA = os.path.join(ROOT, "ml", "data")
KIND_DAYS = {"overview": 4, "sights": 3, "food": 2, "activities": 3, "blog": 4}


def load_entries():
    rows = json.load(open(os.path.join(DATA, "real_entries.json"), encoding="utf8"))
    blog = os.path.join(DATA, "blog_entries.json")
    if os.path.exists(blog):
        rows += json.load(open(blog, encoding="utf8"))
    return rows


def truncate(conn):
    with conn.cursor() as cur:
        for t in ("db1", "db2", "db3", "db", "interactions", "journal_features", "signup"):
            cur.execute(f"DELETE FROM {t} WHERE eml LIKE %s", (f"%{DOMAIN}",))
    conn.commit()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--truncate", action="store_true")
    a = ap.parse_args()
    rng = random.Random(7)
    np.random.seed(7)
    dests = {d["name"]: d for d in get_destinations()}
    conn = get_connection(dict_cursor=False)
    try:
        if a.truncate:
            truncate(conn)
        # make sure every catalog destination exists in the reference table
        with conn.cursor() as cur:
            cur.execute("SELECT canonical_name FROM destinations")
            have = {r[0] for r in cur.fetchall()}
            new = [d for d in dests.values() if d["name"] not in have]
            if new:
                cur.executemany(
                    "INSERT INTO destinations (canonical_name,country,city,region,popularity_tier,base_daily_cost)"
                    " VALUES (%s,%s,%s,%s,%s,%s)",
                    [(d["name"], d["country"], d["city"], d["region"], d["tier"], d["base_daily_inr"]) for d in new])
            eml = f"wikivoyage{DOMAIN}"
            cur.execute("INSERT IGNORE INTO signup (fname,lname,eml,psw) VALUES (%s,%s,%s,%s)",
                        ("Web", "Sources", eml, "n/a"))
        conn.commit()

        d_rows, r1, r2, r3 = [], [], [], []
        used = set()
        for e in load_entries():
            dest = dests.get(e["destination"])
            if not dest:
                continue
            days = KIND_DAYS.get(e["kind"], 3)
            total = dest["base_daily_inr"] * days
            cat = split_costs(rng, total)
            title = f"{dest['city']} - {e['kind']} [{e['source']}]"
            while title in used:
                title += "*"
            used.add(title)
            start = date(2025, rng.choice(dest["season_peak"]), rng.randint(1, 27))
            end = start + timedelta(days=days)
            em = f"{e['source']}{DOMAIN}"
            d_rows.append((title, e["text"][:4000], dest["country"], dest["city"],
                           (start - timedelta(days=5)).isoformat(), start.isoformat(), end.isoformat(),
                           "", (e.get("url") or "")[:250], ", ".join(dest["attractions"][:3]), "mixed", em))
            f, t = cat["food"], cat["transport"]
            fs = np.random.dirichlet([2, 2, 1, 2, 1]) * f
            ts = np.random.dirichlet([1, 1, 1, 2, 1, 1, 1]) * t
            r1.append(tuple(money(rng, x) for x in (*fs, *ts, f, t)) + (title, em))
            acc = cat["accommodation"]
            as_ = np.random.dirichlet([5, 1, 1, 1, 1, 1, 1, 1, 1]) * acc
            r2.append(tuple(money(rng, x) for x in as_) + (title, em))
            shop, fees = cat["shopping"], cat["fees_misc"]
            fsp = np.random.dirichlet([1] * 5) * (fees * 0.6)
            ssp = np.random.dirichlet([2, 1, 2, 1, 2, 1]) * shop
            legacy = f + t + float(fsp.sum()) + float(ssp.sum())
            r3.append(tuple(money(rng, x) for x in (*fsp, *ssp, fees * 0.4, fsp.sum(), ssp.sum())) +
                      (title, money(rng, legacy), em))
        with conn.cursor() as cur:
            # ensure distinct emails exist in signup for each source tag
            for em in {r[-1] for r in d_rows}:
                cur.execute("INSERT IGNORE INTO signup (fname,lname,eml,psw) VALUES (%s,%s,%s,%s)", ("Web", "Sources", em, "n/a"))
            cur.executemany("INSERT INTO db(Title,Description,Country,City,cd,dv,dr,hn,address,ptv,tv,eml) "
                            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", d_rows)
            cur.executemany("INSERT INTO db1 VALUES (" + ",".join(["%s"] * 16) + ")", r1)
            cur.executemany("INSERT INTO db2 VALUES (" + ",".join(["%s"] * 11) + ")", r2)
            cur.executemany("INSERT INTO db3 VALUES (" + ",".join(["%s"] * 17) + ")", r3)
        conn.commit()
        print(f"loaded {len(d_rows)} real-sourced entries")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
