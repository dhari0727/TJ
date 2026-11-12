# Stay recommender ("Where to stay")

Code: `ml/stay/recommend.py`, endpoint `POST /stays` in `ml/app.py`, map queries in `ml/geo/places.py`
(`fetch_stays`, `fetch_sights`, category `stay`), UI in `ja-stays.php`, `stays-api.php`, `js/stays.js`,
`css/pages-stays.css`, shown on `trip.php` (itinerary tab). Tests: `tests/stays_test.py`.

## Inputs

| field | meaning |
|---|---|
| `destination` or `lat`/`lon` | where; a few state-level names map to a coastal city (Goa -> Panaji, 30 km radius) |
| `days` 1..30 | trip length; nights = days - 1 |
| `interests` / `trip_type` | activity words from `CANONICAL_ACTIVITIES` plus `family`, `romantic`, `spiritual`, `budget` |
| `travel_style` | budget, mid-range, luxury, backpacker, family, solo, adventure |
| `party_size` | 5 or more adds a "group" theme that favours apartments and guest houses |
| `stops` | optional `[{name, lat, lon}]`; otherwise the notable mapped sights near the centre are used |
| `top_n` | default 6 |

## Trip-length logic

* 1 day: no overnight. The answer says "Day trip: no stay needed"; the cards (max 3) are labelled "if you decide to stay over"
  and sit near the stops. We do not invent rest or day-use places.
* 2 days: one night, one base. The base is the geometric median of the stops (Weiszfeld), the point with the least total
  distance to them, so the day-1 and day-2 stops are both close.
* 3+ days: stops are clustered with k-means (km-projected, farthest-point start), k = 1..3.
  * Under 4 nights: split into 2 bases only if the cluster centres are more than 40 km apart.
  * 4+ nights: k up to min(3, nights/2); a split is accepted only if centres are at least 15 km apart, the mean bed-to-stop
    distance falls by 30% or more, and the smallest cluster has 2+ stops (when there are more than 4 stops).
  * If all stops are within 15 km of each other there is one base.
  * The explanation text states the numbers used.
* Nights per base: 1 each, the rest by largest remainder of stop counts, so they always sum to `days - 1`.

## Candidates

OpenStreetMap through the existing Overpass machinery (`tourism` = hotel, resort, guest_house, hostel, camp_site, apartment,
chalet, motel, with name), around each base (8 km, 16 km if fewer than 12 found). Cached on disk for a week; an empty answer
is never cached; when no mirror answers the API says so instead of claiming there are no stays.
Type is derived from tags plus name words: homestay, heritage stay (heritage/historic tag or haveli, palace, mahal, fort ...),
dharamshala or ashram, eco-lodge or camp.

Wikivoyage "sleep" entries (`ml/data/india_cost_observations.json`) are matched to OSM names (token overlap after dropping
words like hotel and guest house, or string similarity of at least 0.88). A match supplies the only prices shown, with the
Wikivoyage page URL.

## Score

```
score = 0.28 * proximity + 0.24 * type_fit + 0.18 * style_fit + 0.12 * notability + 0.10 * completeness + 0.08 * photo
proximity    = 1 / (1 + d_km / 3)            d = distance to the base
type_fit     = mean over trip themes of (0.55 * type weight + 0.45 * real proximity signal)   (signal only for beach,
               spiritual, heritage, nature; otherwise the type weight alone). Weights are a judgement table in TYPE_FIT.
style_fit    = 1 - |tier - style target| / 3 ; 0.4 if the tier is unknown
notability   = min(1, (wikidata 3 + wikipedia 2 + heritage 1.5 + stars * 0.5 + Wikivoyage-listed 1) / 6)
completeness = share of: website, phone, tier, address/suburb, listed price
photo        = 1 if a verified open-licensed image exists
```

Tier is real or absent: OSM `stars` (1-2 budget, 3 mid-range, 4 upscale, 5 luxury), else the Wikivoyage price against that
destination's Wikivoyage prices (terciles; pooled India if fewer than 6), else "hostel" = budget, else "Tier not mapped".
Selection per base is greedy with a soft variety penalty of 0.04 per repeat of the same type. Each result returns its
`score_parts` and plain-language `why` reasons.

## Images

Only open-licensed pictures, each verified on Wikimedia Commons (license and credit page), in this order: OSM `image` /
`wikimedia_commons` tags that point at Commons; Wikidata P18 for entries with `wikidata`; the lead image of the `wikipedia`
article; for well-placed candidates without those tags, an English Wikipedia article within 1 km whose title matches the
name. Images are served as `commons.wikimedia.org/wiki/Special:FilePath/<file>?width=800`. Hotel and booking sites are never
fetched; no stock photo and no destination photo is substituted: the card shows a no-photo placeholder. Lookups are batched,
cached on disk (positive 30-90 days, negative 3-7 days, failures never cached) and use the user agent
`TravelJournalImageBot/1.0 (educational project; low-rate)` with Retry-After handling.

## Limitations

* OSM coverage varies. Small towns can have few mapped stays; guest houses and homestays are under-mapped; tiers exist only
  where someone added stars or Wikivoyage listed a price.
* Prices exist only for properties Wikivoyage lists and that match an OSM name; they are editors' text, can be stale, and the
  number shown is a summary of the listed range (the raw text is in the tooltip and the API).
* Photos exist for a minority of places (notable properties with a Wikidata/Wikipedia/Commons link). Budget properties
  rarely have one.
* Stops are the notable mapped sights, not the exact stops of the day-by-day text (that plan carries no coordinates).
* The public Overpass servers are sometimes at capacity: the first lookup of a destination can take up to a minute or two,
  later ones are served from cache.
