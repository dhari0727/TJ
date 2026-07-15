"""
JourneyAI — destination normalization.

The journal `db.City` / `db.Country` fields are free text. This module maps a
raw (city, country) pair — plus, as a fallback, scanning the narrative text —
to a canonical destination name from the catalog (e.g. "Bombay"/"India" ->
"Mumbai, India").

Strategy (in order):
  1. Exact match on "City, Country" against the catalog.
  2. Alias dictionary (known spelling variants / former names).
  3. City-only exact match.
  4. Fuzzy match (difflib) on city, then on the full "City, Country" string.
  5. Text fallback: scan free text for any catalog city name.
Returns None if nothing crosses the confidence threshold.
"""
import difflib

from ml.seed.destination_catalog import get_destinations

# Known aliases / former names / common misspellings -> canonical city.
CITY_ALIASES = {
    "bombay": "Mumbai",
    "calcutta": "Kolkata",
    "madras": "Chennai",
    "bangalore": "Bengaluru",
    "benares": "Varanasi",
    "banaras": "Varanasi",
    "pondicherry": "Puducherry",
    "goa beach": "Goa",
    "leh ladakh": "Spiti",
    "kashi": "Varanasi",
    "saigon": "Ho Chi Minh",
    "angkor": "Siem Reap",
}

_DESTS = get_destinations()
_BY_NAME = {d["name"].lower(): d for d in _DESTS}
_BY_CITY = {d["city"].lower(): d for d in _DESTS}
_CITY_NAMES = [d["city"] for d in _DESTS]
_FULL_NAMES = [d["name"] for d in _DESTS]


def _clean(s):
    return (s or "").strip()


def normalize(city, country="", text="", cutoff=0.82):
    """
    Map (city, country[, text]) -> canonical destination dict or None.
    `cutoff` is the difflib similarity threshold for fuzzy matches.
    """
    city_c = _clean(city)
    country_c = _clean(country)
    full = f"{city_c}, {country_c}".strip(", ").lower()

    # 1. exact "City, Country"
    if full in _BY_NAME:
        return _BY_NAME[full]

    city_l = city_c.lower()

    # 2. alias dictionary
    if city_l in CITY_ALIASES:
        alias_city = CITY_ALIASES[city_l].lower()
        if alias_city in _BY_CITY:
            return _BY_CITY[alias_city]

    # 3. city-only exact
    if city_l in _BY_CITY:
        return _BY_CITY[city_l]

    # 4a. fuzzy on city
    if city_c:
        m = difflib.get_close_matches(city_c, _CITY_NAMES, n=1, cutoff=cutoff)
        if m:
            return _BY_CITY[m[0].lower()]

    # 4b. fuzzy on full "City, Country"
    if full:
        m = difflib.get_close_matches(full.title(), _FULL_NAMES, n=1, cutoff=cutoff)
        if m:
            return _BY_NAME[m[0].lower()]

    # 5. text fallback: scan narrative for any known city
    if text:
        t = " " + text.lower() + " "
        for d in _DESTS:
            if f" {d['city'].lower()} " in t:
                return d

    return None


def canonical_name(city, country="", text=""):
    """Convenience: return the canonical name string or None."""
    d = normalize(city, country, text)
    return d["name"] if d else None


if __name__ == "__main__":
    tests = [
        ("Goa", "India", ""),
        ("Bombay", "India", ""),          # alias -> Mumbai
        ("Benares", "", ""),              # alias -> Varanasi
        ("Jiapur", "India", ""),          # typo -> Jaipur (fuzzy)
        ("", "", "We loved our days in Hampi near the ruins."),  # text fallback
        ("Nowhereville", "Atlantis", ""),  # -> None
    ]
    for c, co, tx in tests:
        print(f"{c!r},{co!r},text={bool(tx)} -> {canonical_name(c, co, tx)}")
