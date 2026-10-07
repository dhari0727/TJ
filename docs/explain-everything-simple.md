# JourneyAI — Explained Like You Know Nothing About This

This file assumes zero background. If a word sounds technical, it's explained the moment it's
used. No code, no jargon left unexplained.

---

## First — 3 words you'll see everywhere

**"Notebook"** — just a file where code runs in small chunks, and you can see the result of each
chunk immediately (a table, a chart, a printed number). Think of it like a lab notebook: write a
step, run it, see what happened, write the next step. We have 6 of these, numbered 01 to 06.

**"CSV file"** — a spreadsheet saved as plain text. If you double-click one, it opens in Excel
like any spreadsheet — rows and columns. Every notebook saves its results as one or more CSVs so
the *next* notebook (or a person) can open and reuse them.

**"Model"** — a program that learns a pattern from examples, then makes a guess about something
new it hasn't seen. Example: show it 1,000 past trips and what they cost, and it learns the
pattern well enough to guess the cost of a trip it's never seen before. That's it. That's what
"training a model" means everywhere in this project.

---

## The big picture

We're building a travel app. Two things it needs to be smart about:
1. **"Where should I go?"** — a recommendation
2. **"What will it cost me?"** — a prediction

Neither of those can be smart without data to learn from. Since no ready-made dataset of Gujarat
trips exists, **we built our own fake-but-realistic dataset first**, then trained models on it.
That's why there are 6 notebooks — each one is one step in that chain, and each one saves its
work as CSV files for the next step to use.

```
01 Make fake trip data  →  02 Read the trip stories  →  03 Learn what things cost  →  04 Learn what to recommend
                                                                                              ↓
                                                                        05 Real place data (separate)
                                                                                              ↓
                                                                        06 Turn it all into a day-by-day plan
```

---

## Notebook 01 — Make Fake Trip Data

**In plain words:** No real dataset of "who went where in Gujarat and what they spent" exists
publicly. So we wrote code that invents realistic trips — a made-up person visits a made-up-but-
real place, for a made-up-but-sensible number of days, spends a made-up-but-realistic amount of
money, split across food/travel/stay/shopping, and writes a short made-up review. We did this
2,600 times, for 150 different made-up travelers, across 103 real Gujarat places.

It's fake, but not random — a person who likes beaches is more likely to "visit" a beach town and
write happily about it. That realism is what lets the later notebooks learn something real from it.

**The CSV files it creates:**
- `destinations.csv` — the list of 103 real Gujarat places we used (Ahmedabad, Dwarka, Kutch...),
  with things like: what it's known for, roughly how much it costs per day, best season to visit.
- `users.csv` — the 150 made-up travelers, with their name, email, and what kind of activities
  they personally prefer (beaches, temples, food, etc).
- `journals.csv` — the actual 2,600 fake trips. Each row is one trip: who went, where, how many
  days, how much it cost (broken into food/transport/stay/shopping/fees), and a written
  description of the trip.
- `interactions.csv` — a list of "ratings" — which traveler rated which place how highly (1-5
  stars), used later to figure out "people who liked X also liked Y."

---

## Notebook 02 — Read the Trip Stories

**In plain words:** Notebook 01 gave us written trip descriptions, but a computer can't "read"
free text the way you do. This notebook turns each written description into structured facts a
model can actually use:
- Was the trip written about **positively or negatively**? (we call this "sentiment")
- What **activities** does it mention? (temple visits, beach time, food, etc.)
- Is it a **budget or luxury** trip, based on how much was spent?
- Turn the whole text into **numbers that represent which words matter** (so two similar-sounding
  trip descriptions end up "close" to each other, numerically)

None of this uses a "black box" AI model — it's all rule-based. A word like "amazing" adds to the
positive score; a word like "amazing" preceded by "not" flips it negative. Every result can be
traced back to exactly which word caused it — that's what "explainable" means in this project.

**The CSV files it creates:**
- `journal_features.csv` — one row per trip from notebook 01, now with the extra facts figured
  out: was it positive/negative, what activities it mentioned, budget tier.
- `destination_profiles.csv` — one row **per place** (all 103), summarizing what all the trips to
  that place have in common: average cost, common activities, overall sentiment, how many trips
  we have data for.

---

## Notebook 03 — Learn What Things Cost

**In plain words:** This is where an actual **model** gets trained (remember: a program that
learns a pattern from examples). We show it 2,600 real trip records — destination, travel style,
season, how many days, and what it cost — and it learns the pattern well enough to predict the
cost of a **new** trip it's never seen.

We deliberately tested it against two simpler alternatives to prove it's actually learning
something, not just getting lucky:
1. "Just guess the average cost for that place" (a very dumb approach)
2. A simpler kind of model (Linear Regression)

Our model beat the dumb guess by 15%, and was about equal to the simpler model — meaning it
really did learn a real pattern.

**How good is it, in plain terms:** on average, its guess is off by about ₹2,400 on trips that
typically cost anywhere from a few thousand to tens of thousands of rupees. Not perfect, but
genuinely useful — same ballpark of accuracy as real commercial travel-price tools use.

**The CSV file it creates:**
- `cost_training_data.csv` — the exact table the model learned from: 2,600 rows, each with
  destination, travel style, season, days, and the real cost — this is the "textbook" the model
  studied.

*(This notebook also saves the trained model itself as a file called `cost_model.pkl` — not a
CSV, just a saved copy of "the thing that learned the pattern," so it can be reused later without
re-training.)*

---

## Notebook 04 — Learn What to Recommend

**In plain words:** This notebook decides which place to suggest to a traveler based on what they
say they like. It doesn't use just one way of deciding — it blends three:
1. **Do their stated interests match this place's activities?** (e.g., they said "temples," this
   place is tagged with temples)
2. **Does their interest text sound like the trip stories written about this place?** (using the
   "numbers that represent words" from notebook 02)
3. **Did other travelers with similar taste rate this place highly?** (using the ratings from
   notebook 01)

All three get combined into one final score per place, and the top-scoring places are shown —
along with a plain-English reason, like *"we recommend Dwarka because it matches your interest in
temples, and travelers with similar taste rated it highly."*

**The CSV file it creates:**
- `recommendation_examples.csv` — example searches we ran (like "someone interested in temples
  and food, with a ₹20,000 budget") and exactly which places got recommended, with their scores
  and the explanation given.

---

## Notebook 05 — Real Place Data

**In plain words:** Notebooks 01-04 use *made-up* trips, but this notebook is about *real* data —
actual landmarks and food spots in 100 real Gujarat towns, which we researched by hand. We prove
it's actually useful with a real test: we compared what a generic map service (like Google/
OpenStreetMap) shows for Vadodara versus our researched version — and 4 out of 6 well-known real
landmarks were **completely missing** from the generic map data. Our research is what recovers
them.

**The CSV files it creates:**
- `local_highlights.csv` — the 100 towns we researched, with how many real landmarks and local
  foods we found for each.
- `poi_sample.csv` — a big dump of place data ("points of interest") pulled from the map service,
  used for comparison.

---

## Notebook 06 — Turn It Into a Day-by-Day Plan

**In plain words:** This is the only notebook that isn't a trained model — it's simpler than
that. It just takes everything the other notebooks built (real places, the cost model, activity
data) and stitches it into a readable day-by-day itinerary: "Day 1 morning — visit X, afternoon —
try Y food, evening — Z." No learning happens here, it's just assembling pieces we already built.

**The CSV file it creates:**
- `itinerary_examples.csv` — two example trip plans, broken down day by day and activity by
  activity.

---

## If someone asks "so what's the actual output of all this?"

A traveler types what they like and their budget. The app (built on these 6 notebooks' worth of
work) tells them: here are the best-matching real places, here's what each will really cost
broken down by category, here's why we picked them, and here's a day-by-day plan for the one they
choose. That's the whole system, end to end.
