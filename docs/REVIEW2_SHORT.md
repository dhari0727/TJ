# JourneyAI — Review 2 (short version)

Guide: Mr. Hitesh Makwana. Team: Margi Maradia (161, Recommendation), Deeksha Mavadiya (162, Data), Dharini Thakkar (163, Cost prediction), Hetavi Shah (154, NLP). Full details are in `docs/REVIEW2.md`.

## What JourneyAI does
A traveller types a trip in plain words ("weekend from Ahmedabad, temples and food", "4 days from Nadiad") and gets real places, a route with an alternative and a map, a day-by-day itinerary, the estimated cost, and a packing list. Every recommendation explains why. There is also a journal, a community feed, an AI chat assistant, and an admin panel.

## What changed since Review 1
- **Coverage:** 100 Gujarat places grew to 578 destinations across India (plus 32 international).
- **Real data added:** Wikivoyage and Wikipedia listings with prices, OpenStreetMap places, and real traveller blog reports.
- **Better recommender:** it now considers budget, season, how well known a place is, and distance from where you start, so short and long trips give different results.
- **New features:** trip page (itinerary, route, map), packing list builder, AI chat, journal and storybooks, community, admin, production hardening.

## Feedback from Review 1: what we did
| Feedback | Done |
|---|---|
| Real user data from blogs | 221 real Tripoto trip reports from 109 destinations. Only 41 usable cost mentions (7 destinations), so blogs are used to check the cost model, not train it |
| Describe the dataset | Four kinds of data with sources, sizes, licences, fields and cleaning steps (below) |
| Evaluation metrics | Metrics with baselines for every module (below) |

## The dataset
| Kind | Size | Real or synthetic |
|---|---|---|
| Destination catalog (region, daily cost, activities, attractions, coordinates) | 578 destinations (193 hand-curated, rest from Wikivoyage), 561 profiled | Real facts, cost is an estimate from real prices |
| Listings and prices (Wikivoyage) | 16,639 listings, 2,148 numeric prices (hotel median ₹1,350 a night, meal ₹170, entry ₹50) | Real |
| Traveller blogs (Tripoto, Holidify) | 221 reports, 396 snippets | Real |
| Journals and ratings used for training | 2,881 journals: 1,500 synthetic, 1,380 real-sourced, 1 real user. 40 synthetic users, 2,068 ratings | Mostly synthetic (no real users yet) |

**How it was built:** crawl Wikivoyage, parse prices, estimate each place's daily cost (3 meals + 60% of a room night + 2 entry fees + ₹250 transport), drop 109 tiny places, tag activities and season, add coordinates, collect blogs politely (robots.txt, 1.5 s delay, only facts and short attributed snippets). Sources are open-licensed (Wikivoyage and Wikipedia CC BY-SA, OpenStreetMap ODbL) and attributed in the site footer.

**Be honest:** training journals are mostly synthetic, so scores on them show the models learn the pattern we generated, not accuracy on real trips.

## The four modules
**NLP (Hetavi):** matches messy place names to the catalog, tokenises and lemmatises text, finds 20 activity tags from 153 keywords, scores sentiment with a word list (handles "not" and "very"), builds TF-IDF vectors (4,000 features), builds a profile for each destination, and understands typed trip requests.

**Cost prediction (Dharini):** a Gradient Boosting model predicts daily cost, then multiplies by days. Inputs: destination, region, travel style, season, days, party size, and the catalog price estimate. Two extra models give a low to high range. The total is split into food, transport, stay, shopping and fees.

**Recommender (Margi):** score = 0.38 content (interest match) + 0.22 semantic (text similarity) + 0.30 collaborative (similar travellers) + 0.12 fame, adjusted for budget, season, and distance for the trip length (day 0 to 90 km, weekend 60 to 380, short 180 to 950, long 700 to 4,500). Explanations use the place's real attractions, distance, season and cost.

**Data (Deeksha):** the collection, cleaning and dataset described above.

## Evaluation results
**Cost model (5-fold cross-validation, 2,881 journals)**
| Model | MAE ₹ | R² |
|---|---|---|
| **Ours (Gradient Boosting)** | **2,698** | 0.75 |
| Destination average | 3,267 | 0.69 |
| Region average | 4,028 | 0.63 |
| Global average | 5,407 | 0.36 |
| Catalog price prior only | 2,491 | 0.80 |

- 17% better than "destination average" on known places and 40% better on brand-new places (₹2,548 vs ₹4,255).
- The price-prior-only baseline matches us, so most of the skill comes from the real-price prior. Say this first.
- The 80% range holds 77% of true costs. Against real blog costs (7 destinations), error is about ₹1,170 a day; Leh is badly underestimated. Preliminary.

**NLP**
| Test | Result |
|---|---|
| Sentiment (60 hand-labelled sentences) | 90% accuracy, F1 0.90 |
| Place-name matching with a typo | 98.5% (200 places) |
| Trip-request understanding (40 queries) | 40 of 40 |
| Activity tags vs curated labels (54 places) | F1 0.44 (weak, keyword based) |

**Recommender**
| Check | Result |
|---|---|
| Top-6 results match the asked interest | 90% |
| Within budget | 100% |
| Within the distance window (5 cities, 3 trip lengths) | 100% |
| Regions in a top-10 | 5.1 |
| Personalisation, AUC (liked place ranks above random) | 0.57 (random 0.48), modest |

Finding one hidden liked place in the top 10 is at chance level for every method, because there are only 40 synthetic users and 561 places. We do not claim strong personalisation; real ratings are the fix.

## Limitations (say these first)
- Training journals are mostly synthetic; blog costs are scarce (41 mentions).
- The cost model leans on the price prior and its range is slightly narrow.
- Personalised ranking is weak with 40 users; activity tagging is keyword based.
- Wikivoyage prices can be dated and lean budget; Leh-type remote places are underestimated.
- Future work: real user journals, a trained text classifier, learned recommender weights, more blog sources.

## Demo in five steps
1. Plan a Trip: "1 day near Nadiad, temples" gives real temples, a route, an alternative and a map.
2. More control: Short trip then Long trip from Nadiad give different places with distance and a reason.
3. Trip page: itinerary and cost, route and map, then the packing list.
4. Journal and Community: write an entry, share it, comment, pin.
5. Chat ("Places near me") and the Admin health page.

## Likely questions, one-line answers
- **Why synthetic data?** No public dataset of Indian trip journals with costs exists; we generate from real prices and say so.
- **Why gradient boosting?** Handles mixed inputs and style-by-season effects on small data.
- **Why a lexicon, not BERT?** Explainable, fast, 90% on our test; it misses unknown words.
- **Why a hybrid recommender?** Each signal covers the others' weaknesses (new users, taste, text).
- **Is scraping allowed?** We follow robots.txt, rate-limit, cache, and store only facts and short attributed snippets.
