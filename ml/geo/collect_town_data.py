"""
JourneyAI — standalone local-highlights data collection script.

Manually researches real landmarks + food specialties for a list of towns,
then merges the results straight into ml/geo/local_highlights.py (via
_merge_highlights.py) — no Claude/agent session needed.

SEARCH BACKEND (pick one):
    1. SerpAPI (recommended — real Google search results, more reliable):
       put your key in ml/geo/serpapi_key.txt (just the key, nothing else).
       Free tier: https://serpapi.com/manage-api-key
    2. Gemini's built-in search grounding (fallback if no SerpAPI key):
       same key ml/bot/chat.py already uses — either
         ml/bot/gemini_key.txt containing just the key, or
         env var JOURNEYAI_GEMINI_KEY=your_key
       Free key: https://aistudio.google.com/app/apikey

    Either way you still need a Gemini key — it does the writing/extraction
    from search results into structured JSON. SerpAPI just gives it better
    source material to extract from than relying on Gemini's own grounding.

USAGE:
    # research specific towns (comma-separated)
    ml/venv/Scripts/python.exe -m ml.geo.collect_town_data --towns "Morbi,Godhra,Gandhidham"

    # research towns from a file (one per line)
    ml/venv/Scripts/python.exe -m ml.geo.collect_town_data --file ml/geo/towns_todo.txt

    # research an entire state (asks Gemini to first list its major towns)
    ml/venv/Scripts/python.exe -m ml.geo.collect_town_data --state Maharashtra

    # dry run: print results without writing to local_highlights.py
    ml/venv/Scripts/python.exe -m ml.geo.collect_town_data --towns "Morbi" --dry-run

Notes:
    - This is a LIGHTER-verification pass than the hand-curated Gujarat batch
      (single Gemini+search call per town, not multi-source cross-checked) —
      good for scaling coverage fast, but spot-check important towns yourself.
    - Safe to re-run: merging a town that already exists overwrites that
      town's entry (see _merge_highlights.py), it does not duplicate.
    - Rate-limited to be polite to the free Gemini tier (short delay between
      towns); large batches (50+) will take a while — that's expected.
"""
import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.request

from ml.bot.gemini import get_key, _model_chain

API = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}"
SERPAPI_URL = "https://serpapi.com/search.json"

VALID_CATEGORIES = {"religious", "heritage", "shopping", "garden", "nature",
                     "beach", "wildlife", "museum", "attraction"}


def get_serpapi_key():
    import os
    k = os.environ.get("SERPAPI_KEY", "").strip()
    if k:
        return k
    import pathlib
    for name in ("serpapi_key.txt", "serpapi_key"):
        p = pathlib.Path(__file__).parent / name
        if p.exists():
            try:
                v = p.read_text(encoding="utf-8").strip()
                if v:
                    return v
            except Exception:
                continue
    return None


def serpapi_search(query, num=6):
    """Real Google search results via SerpAPI. Returns a list of {title, snippet, link}."""
    key = get_serpapi_key()
    if not key:
        return None
    params = urllib.parse.urlencode({"q": query, "api_key": key, "num": num, "engine": "google"})
    url = f"{SERPAPI_URL}?{params}"
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "JourneyAI-DataCollector/1.0"})
        with urllib.request.urlopen(req, timeout=20) as r:
            data = json.loads(r.read().decode("utf-8"))
    except Exception as e:
        print(f"  SerpAPI error: {e}", file=sys.stderr)
        return None
    results = []
    for item in data.get("organic_results", [])[:num]:
        results.append({
            "title": item.get("title", ""),
            "snippet": item.get("snippet", ""),
            "link": item.get("link", ""),
        })
    return results

TOWN_PROMPT_WITH_SEARCH = """Here are web search results about {town}{state_suffix}, India:

--- ATTRACTIONS SEARCH ---
{attractions_results}

--- FOOD SEARCH ---
{food_results}

Based ONLY on the search results above, extract real local landmarks/attractions and local food specialties for {town} for a travel app's "local highlights" feature. Only report what the search results actually say — don't invent landmarks or dishes not mentioned above. If the results are thin, return a short list (2-3 landmarks, 1-2 food items) rather than padding with generic filler.

Respond with ONLY a JSON object (no markdown fences, no other text) in exactly this shape:
{{
  "key": "{town_key}",
  "character": "one sentence describing the town",
  "landmarks": [
    {{"name": "...", "category": "one of: religious|heritage|shopping|garden|nature|beach|wildlife|museum|attraction", "note": "one-line description"}}
  ],
  "food": [
    {{"dish": "...", "where": "specific shop/market if known, else a general area", "note": "one-line description"}}
  ]
}}

Provide 3-8 landmarks and 2-6 food items."""

TOWN_PROMPT_NO_SEARCH = """Research real local landmarks/attractions and local food specialties for {town}{state_suffix}, India, for a travel app's "local highlights" feature.

Use your search tool to find what {town} is actually known for. This is a lighter-verification pass (not deep cross-checking) — only report what search results actually say, don't invent landmarks or dishes. If {town} is a small/minor town with thin tourism coverage, return a short list (2-3 landmarks, 1-2 food items) rather than padding with generic filler unless a source specifically ties it to this town.

Respond with ONLY a JSON object (no markdown fences, no other text) in exactly this shape:
{{
  "key": "{town_key}",
  "character": "one sentence describing the town",
  "landmarks": [
    {{"name": "...", "category": "one of: religious|heritage|shopping|garden|nature|beach|wildlife|museum|attraction", "note": "one-line description"}}
  ],
  "food": [
    {{"dish": "...", "where": "specific shop/market if known, else a general area", "note": "one-line description"}}
  ]
}}

Provide 3-8 landmarks and 2-6 food items."""

STATE_TOWNS_PROMPT = """List the major cities, district headquarters, and well-known tourist towns of {state}, India. Use your search tool to find an authoritative list (e.g. Wikipedia's list of cities in {state}). Aim for 60-120 names covering all districts, not just the largest metros.

Respond with ONLY a JSON object (no markdown fences, no other text):
{{"towns": ["Town1", "Town2", ...]}}"""


def _post(payload, timeout=60):
    key = get_key()
    if not key:
        print("ERROR: No Gemini API key configured. See ml/bot/gemini_key.txt.example.", file=sys.stderr)
        sys.exit(1)
    data = json.dumps(payload).encode("utf-8")
    last_err = None
    for model in _model_chain():
        url = API.format(model=model, key=key)
        for attempt in range(2):
            try:
                req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"})
                with urllib.request.urlopen(req, timeout=timeout) as r:
                    return json.loads(r.read().decode("utf-8"))
            except urllib.error.HTTPError as e:
                last_err = e
                if e.code == 429:
                    time.sleep(3)
                    continue
                break
        continue
    raise RuntimeError(f"Gemini request failed: {last_err}")


def _extract_text(resp):
    try:
        parts = resp["candidates"][0]["content"]["parts"]
        return "".join(p.get("text", "") for p in parts)
    except (KeyError, IndexError):
        return ""


def _extract_json(text):
    text = text.strip()
    text = re.sub(r"^```(?:json)?\s*", "", text)
    text = re.sub(r"\s*```$", "", text)
    m = re.search(r"\{.*\}", text, re.DOTALL)
    if not m:
        return None
    try:
        return json.loads(m.group(0))
    except json.JSONDecodeError:
        return None


def _ask_with_search(prompt, use_grounding=True):
    payload = {
        "contents": [{"role": "user", "parts": [{"text": prompt}]}],
        "generationConfig": {"temperature": 0.2},
    }
    if use_grounding:
        payload["tools"] = [{"google_search": {}}]
    resp = _post(payload)
    return _extract_json(_extract_text(resp))


def _format_results(results):
    if not results:
        return "(no results found)"
    return "\n".join(f"- {r['title']}: {r['snippet']}" for r in results if r.get("snippet"))


def discover_towns(state):
    print(f"Discovering towns in {state}...")
    if get_serpapi_key():
        results = serpapi_search(f"list of cities and towns in {state} India Wikipedia", num=8)
        prompt = (STATE_TOWNS_PROMPT.format(state=state) +
                  f"\n\nSearch results:\n{_format_results(results)}")
        result = _ask_with_search(prompt, use_grounding=False)
    else:
        result = _ask_with_search(STATE_TOWNS_PROMPT.format(state=state))
    if not result or "towns" not in result:
        print(f"  Could not discover town list for {state}.", file=sys.stderr)
        return []
    towns = result["towns"]
    print(f"  Found {len(towns)} towns.")
    return towns


def research_town(town, state=None):
    state_suffix = f", {state}" if state else ""
    town_key = re.sub(r"[^a-z0-9]", "", town.lower())

    if get_serpapi_key():
        attractions = serpapi_search(f"{town}{state_suffix} famous places attractions tourism")
        food = serpapi_search(f"{town}{state_suffix} famous food local dish specialty")
        prompt = TOWN_PROMPT_WITH_SEARCH.format(
            town=town, state_suffix=state_suffix, town_key=town_key,
            attractions_results=_format_results(attractions),
            food_results=_format_results(food),
        )
        result = _ask_with_search(prompt, use_grounding=False)
    else:
        prompt = TOWN_PROMPT_NO_SEARCH.format(town=town, state_suffix=state_suffix, town_key=town_key)
        result = _ask_with_search(prompt, use_grounding=True)

    if not result:
        return None
    # sanitize categories
    for lm in result.get("landmarks", []):
        if lm.get("category") not in VALID_CATEGORIES:
            lm["category"] = "attraction"
    return result


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--towns", help="comma-separated town names")
    ap.add_argument("--file", help="path to a file of town names, one per line")
    ap.add_argument("--state", help="a state name; discovers its towns automatically")
    ap.add_argument("--dry-run", action="store_true", help="print results, don't merge into local_highlights.py")
    ap.add_argument("--delay", type=float, default=1.5, help="seconds to wait between towns (default 1.5)")
    args = ap.parse_args()

    state = args.state if args.towns is None and args.file is None else None
    towns = []
    if args.towns:
        towns = [t.strip() for t in args.towns.split(",") if t.strip()]
    elif args.file:
        with open(args.file, encoding="utf-8") as f:
            towns = [line.strip() for line in f if line.strip()]
    elif args.state:
        towns = discover_towns(args.state)

    if not towns:
        print("No towns to research. Pass --towns, --file, or --state.", file=sys.stderr)
        sys.exit(1)

    results = []
    for i, town in enumerate(towns, 1):
        print(f"[{i}/{len(towns)}] Researching {town}...")
        try:
            r = research_town(town, state=args.state)
        except Exception as e:
            print(f"  ERROR: {e}", file=sys.stderr)
            r = None
        if r:
            results.append(r)
            print(f"  -> {len(r.get('landmarks', []))} landmarks, {len(r.get('food', []))} food items")
        else:
            print(f"  -> failed, skipping")
        if i < len(towns):
            time.sleep(args.delay)

    print(f"\n{len(results)}/{len(towns)} towns researched successfully.")

    if args.dry_run:
        print(json.dumps({"towns": results}, indent=2, ensure_ascii=False))
        return

    if not results:
        print("Nothing to merge.")
        return

    out_path = "ml/geo/_collected_results.json"
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump({"towns": results}, f, ensure_ascii=False, indent=2)
    print(f"Wrote raw results to {out_path}")

    from ml.geo._merge_highlights import merge
    merge(out_path)


if __name__ == "__main__":
    main()
