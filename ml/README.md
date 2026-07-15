# JourneyAI — ML Service

Python 3.10 Flask microservice that powers the hybrid, explainable, budget-aware
travel recommender for the PHP app. Runs on `http://127.0.0.1:5000`.

## Setup (once)

```bash
py -3.10 -m venv ml/venv
ml/venv/Scripts/python.exe -m pip install -r ml/requirements.txt
# NLTK data (wordnet lemmatizer):
ml/venv/Scripts/python.exe -c "import nltk; [nltk.download(p, quiet=True) for p in ['punkt','punkt_tab','stopwords','wordnet']]"
```

The database must be migrated first: `mysql -u root project < sql/migrations.sql`

## Build the models (offline pipeline)

```bash
ml/venv/Scripts/python.exe -m ml.rebuild_all --seed 42 --n 500
```

Runs: seed corpus → extract NLP features → build destination profiles →
train cost model. Re-run with `--no-seed` to retrain on existing data.

## Run the service

```bash
ml/venv/Scripts/python.exe -m ml.app      # or double-click ml/start_service.bat
```

## Endpoints

| Method | Path                | Purpose |
|--------|---------------------|---------|
| GET    | `/health`           | liveness + loaded-model info + cost metrics |
| POST   | `/recommend`        | ranked, explained destination recommendations |
| POST   | `/predict-cost`     | cost estimate + low/high band + category breakdown |
| GET    | `/analytics/summary`| aggregates for the analytics dashboard |

### `/recommend` request
```json
{ "eml": "user@x.com", "budget": 30000, "duration_days": 5,
  "interests": ["beach","food"], "travel_style": "mid-range",
  "party_size": 1, "month": 12, "top_n": 6 }
```

## Architecture

- **NLP** (`ml/nlp/`): TF-IDF + NLTK + custom lexicon → `journal_features`;
  per-destination profiles.
- **Cost** (`ml/cost/`): GradientBoostingRegressor on daily cost (× duration),
  quantile bands, per-destination category ratios. Trains on `true_total`
  (NOT the buggy `db3.grand`).
- **Recommender** (`ml/recommender/`): content + item-item CF + semantic blend,
  budget-fit factor, hidden-gem discovery boost, templated explanations.

All models load once at boot from `ml/artifacts/` (regenerable, git-ignored).
Service binds to `127.0.0.1` only; CORS limited to localhost.
