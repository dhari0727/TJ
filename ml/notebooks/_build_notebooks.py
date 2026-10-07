"""Generate + execute the JourneyAI notebooks.  Run from repo root:
    ml/venv/Scripts/python.exe ml/notebooks/_build_notebooks.py [--no-exec]
"""
import os
import sys

import nbformat as nbf
from nbconvert.preprocessors import ExecutePreprocessor

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
SETUP = f'''import os, sys, json, warnings
warnings.filterwarnings("ignore")
ROOT = r"{ROOT}"
os.chdir(ROOT); sys.path.insert(0, ROOT)
import pandas as pd, numpy as np, matplotlib.pyplot as plt
pd.set_option("display.width", 160); pd.set_option("display.max_colwidth", 70)
plt.rcParams.update({{"figure.figsize": (9, 4), "axes.grid": True, "grid.alpha": .3}})
DATA = os.path.join(ROOT, "ml", "data")'''

NB = {}
NB["01_wikivoyage_india_crawl"] = [
    ("md", "# 01 · Crawl the India destination graph (Wikivoyage)\nStates → regions → cities, via the public MediaWiki API (CC BY-SA). Code: `ml/scrape/wikivoyage.py`. Re-runs offline from the cache in `ml/data/raw/`."),
    ("code", SETUP),
    ("code", 'nodes = pd.DataFrame(json.load(open(f"{DATA}/india_destinations.json", encoding="utf8")))\nprint(len(nodes), "nodes")\nnodes.kind.value_counts()'),
    ("code", 'nodes[nodes.depth==1][["title","kind"]].T'),
    ("code", 'g = nodes.dropna(subset=["lat","lon"]); g = g[(g.lat.between(6,37))&(g.lon.between(68,98))]\nplt.figure(figsize=(6,7)); plt.scatter(g.lon, g.lat, s=6, alpha=.6)\nplt.title(f"{len(g)} geocoded destinations across India"); plt.xlabel("lon"); plt.ylabel("lat"); plt.show()'),
    ("code", 'nodes.groupby("depth").size().plot.bar(title="Tree depth distribution"); plt.show()'),
]
NB["02_listings_and_real_costs"] = [
    ("md", "# 02 · Real listings and price observations\nEvery see/eat/sleep listing with a parseable ₹ price becomes a cost observation. Daily per-person cost = 3 meals + ~60% of a room night + 2 entry fees + ₹250 local transport (`estimate_daily`)."),
    ("code", SETUP),
    ("code", 'L = pd.DataFrame(json.load(open(f"{DATA}/india_listings.json", encoding="utf8")))\nO = pd.DataFrame(json.load(open(f"{DATA}/india_cost_observations.json", encoding="utf8")))\nC = pd.DataFrame(json.load(open(f"{DATA}/india_catalog.json", encoding="utf8")))\nprint(len(L), "listings;", len(O), "price observations;", len(C), "destinations")\nL.type.value_counts()'),
    ("code", 'O.groupby("type").inr.describe()[["count","25%","50%","75%","max"]]'),
    ("code", 'fig,ax = plt.subplots(1,3,figsize=(13,3.5))\nfor a,t in zip(ax,["eat","sleep","see"]):\n    np.log10(O[O.type==t].inr).hist(bins=30, ax=a); a.set_title(f"{t}: log10(INR)")\nplt.tight_layout(); plt.show()'),
    ("code", 'C.groupby("region").base_daily_inr.describe()[["count","25%","50%","75%"]].round(0)'),
    ("code", 's = C.sort_values("base_daily_inr")\npd.concat([s.head(8), s.tail(8)])[["name","region","tier","base_daily_inr","n_price_obs"]]'),
    ("code", 'print("destinations backed by >=3 direct price observations:", int((C.n_price_obs>=3).sum()), "of", len(C), "(rest use the region median)")'),
]
NB["03_blog_article_corpus"] = [
    ("md", "# 03 · Blog / article corpus\nPolite fetch (robots.txt honoured, 1.5s delay, cached). Only **derived** data is kept: cost figures, activity tags and a ≤450-char attributed snippet. Code: `ml/scrape/blogs.py`.\n\nNo search-API key was available, so pages are fetched from direct article URL patterns (Holidify place pages)."),
    ("code", SETUP),
    ("code", 'bp, cp = f"{DATA}/blog_entries.json", f"{DATA}/blog_cost_obs.json"\nB = pd.DataFrame(json.load(open(bp, encoding="utf8"))) if os.path.exists(bp) else pd.DataFrame()\nBC = pd.DataFrame(json.load(open(cp, encoding="utf8"))) if os.path.exists(cp) else pd.DataFrame()\nprint(len(B), "blog snippets;", len(BC), "daily-cost observations;", B.destination.nunique() if len(B) else 0, "destinations")\nB.head(5)[["destination","url","text"]] if len(B) else "no blog data yet"'),
    ("code", 'if len(BC):\n    C = pd.DataFrame(json.load(open(f"{DATA}/india_catalog.json", encoding="utf8")))\n    m = BC.groupby("destination").daily_inr.median().rename("blog_daily").to_frame().join(C.set_index("name").base_daily_inr.rename("listing_daily"), how="inner")\n    print("destinations with both sources:", len(m), "| median ratio blog/listing:", round((m.blog_daily/m.listing_daily).median(),2))\n    m.plot.scatter("listing_daily","blog_daily", title="Blog-reported vs listing-derived daily cost"); plt.plot([0,m.max().max()],[0,m.max().max()],"r--"); plt.show()'),
]
NB["04_recommender_profiles_eval"] = [
    ("md", "# 04 · NLP profiles and hybrid recommender — demo + evaluation\nUses pipeline artifacts (`python -m ml.rebuild_all`). Evaluation: leave-one-out on ratings — hide one highly-rated destination per user, then check whether a content-only probe built from their *other* likes ranks it in the top-K."),
    ("code", SETUP),
    ("code", 'import pickle\nprof = pickle.load(open("ml/artifacts/dest_profiles.pkl","rb"))["profiles"]\nP = pd.DataFrame([{k:v for k,v in p.items() if k in ("canonical_dest","n_journals","region","popularity_tier","avg_daily","sentiment_mean")} for p in prof.values()])\nprint(len(P), "destination profiles")\nP.region.value_counts()'),
    ("code", 'from ml.recommender.hybrid import get_recommender\nrec = get_recommender()\ndef show(**kw):\n    return pd.DataFrame(rec.recommend(top_n=8, **kw))[["destination","score","predicted_cost","budget_fit","top_activities"]]\nshow(interests=["beach","food"], budget=30000, duration_days=5, month=12)'),
    ("code", 'show(interests=["history","temples"], budget=40000, duration_days=6, month=1, origin_region="North India")'),
    ("code", 'show(interests=["trekking","mountains","snow"], budget=50000, duration_days=7, month=6, origin_region="South India")'),
    ("code", 'from ml.db import fetch_df\nR = fetch_df("SELECT eml, destination, rating FROM interactions WHERE interaction_type=\'rating\'")\nR = R[R.rating>=4]\nrng = np.random.default_rng(0); hits=tot=0; K=20\nfor eml, g in R.groupby("eml"):\n    if len(g) < 4: continue\n    held = g.sample(1, random_state=int(rng.integers(1e6))).destination.iloc[0]\n    liked = [d for d in g.destination if d != held and d in rec.profiles]\n    if held not in rec.dest_names or not liked: continue\n    acts = pd.Series([a for d in liked for a in rec._top_activities(d, 3)]).value_counts().index[:3].tolist()\n    top = [r["destination"] for r in rec.recommend(interests=acts, top_n=K)]\n    hits += held in top; tot += 1\nprint(f"hit-rate@{K} = {hits/tot:.2%} over {tot} users (random baseline ~ {K/len(rec.dest_names):.2%})")\nimport scrapbook as sb; sb.glue("hit_rate_at_20", hits/tot)'),
]
NB["05_cost_model_training"] = [
    ("md", "# 05 · Trip-cost model — training and evaluation\nGradient boosting on daily cost with quantile bands (`ml/cost/train_cost_model.py`). Training data = synthetic taste-driven journals **plus** real-sourced entries whose costs come from observed prices."),
    ("code", SETUP),
    ("code", 'from ml.cost.train_cost_model import load_training_frame\ndf = load_training_frame(); print(df.shape)\ndf.head(3)'),
    ("code", 'import subprocess\nout = subprocess.run([sys.executable, "-m", "ml.cost.train_cost_model"], capture_output=True, text=True, cwd=ROOT)\nprint(out.stdout[-2500:], out.stderr[-500:])'),
    ("code", 'from ml.cost.predict import model_metrics, predict_cost\nprint(model_metrics())\nimport scrapbook as sb; sb.glue("cost_metrics", model_metrics())'),
    ("code", 'rows=[]\nfor d in ["Goa, India","Jaipur, India","Leh, India","Kochi, India","Udaipur, India"]:\n    try:\n        p = predict_cost(d, duration_days=5, travel_style="mid-range", month=12)\n        rows.append({"destination":d, **{k:v for k,v in p.items() if not isinstance(v,(dict,list))}})\n    except Exception as e: rows.append({"destination":d,"error":str(e)[:60]})\npd.DataFrame(rows)'),
]


def build(execute=True):
    for name, cells in NB.items():
        nb = nbf.v4.new_notebook()
        nb.cells = [nbf.v4.new_markdown_cell(c) if k == "md" else nbf.v4.new_code_cell(c) for k, c in cells]
        nb.metadata["kernelspec"] = {"display_name": "Python 3", "language": "python", "name": "python3"}
        path = os.path.join(HERE, name + ".ipynb")
        if execute:
            try:
                ExecutePreprocessor(timeout=900, kernel_name="python3").preprocess(nb, {"metadata": {"path": ROOT}})
                print("executed", name)
            except Exception as e:  # noqa: BLE001
                print("FAILED", name, str(e)[-700:])
        nbf.write(nb, path)


if __name__ == "__main__":
    build("--no-exec" not in sys.argv)
