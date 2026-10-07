# JourneyAI — India-wide completion roadmap

Goal: take the Gujarat-depth approach (real, verified local data) to all of India, replace the
synthetic corpus with real data, keep everything reproducible in Jupyter notebooks, and finish
the storybook-journal idea backlog.

## Data-source policy (what "scrape real data" means here)
- **Primary, redistributable**: Wikivoyage (CC BY-SA), Wikipedia, Wikidata (CC0), OpenStreetMap (ODbL).
  Structured listings include real prices, coordinates, hours.
- **Blogs / articles**: fetched politely (robots.txt honoured, rate-limited, cached, identified UA).
  We store *derived features only* (cost figures, activity tags, sentiment, URL + title for
  attribution) — never republish article text. Reddit/Quora block anonymous scraping (403) and are skipped.
- Every record carries `source` + `url` so it can be cited and audited.

## Phases
| # | Phase | Output | Status |
|---|-------|--------|--------|
| 1 | India destination graph (Wikivoyage crawl) | `ml/data/india_destinations.json` (1,422 nodes), nb 01 | done |
| 2 | Real listings + cost observations | 16,639 listings, 2,148 parsed prices, 441 India destinations after prominence filter, nb 02 | done |
| 3 | Blog/article corpus | `ml/scrape/blogs.py`; Holidify place pages (no valid search-API key). ~155 snippets but only 1 daily-cost figure — blog pages rarely state costs, so **cost signal is Wikivoyage prices**; nb 03 | done, thin on costs |
| 4 | Real-data recommender + cost model | pipeline loads real entries (`ml.seed.load_real_entries`), cost model takes real `base_daily` prior (R2 0.72 -> 0.81, MAE Rs 3.3k -> 2.7k), prominence prior in ranker, nb 04-05 | done; recommender offline eval weak (see nb 04) |
| 5 | India local highlights (beyond Gujarat) | `ml/data/india_highlights.json` (396 towns, 2,559 landmarks, 649 food entries from Wikivoyage listings); `local_highlights.get_town()` falls back to it, Gujarat curated entries keep priority | done (lighter verification than Gujarat) |
| 6 | Storybook backlog | memory prompts, progress indicator, live cost card + running "spent so far" added | done |
| 7 | Integrate + QA + docs | site restructured (one search, combined trip page, community, admin); PHP flows tested end to end | done |

Rebuild everything: `python -m ml.scrape.wikivoyage --crawl --listings`, `python -m ml.scrape.wikipedia_views`,
`python -m ml.scrape.build_india_dataset`, `python -m ml.scrape.blogs`, `python -m ml.rebuild_all`,
then `python ml/notebooks/_build_notebooks.py` (executes the notebooks).
