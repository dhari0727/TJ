# JourneyAI — Cost Prediction Notebook, Fully Explained

Every cell in `cost_prediction_model.ipynb`, in detail. For each one: **what it does**, **how it
actually works**, **why we did it that way**, and **what ends up in the CSV** where relevant.
Written assuming zero coding background — every technical word is explained the moment it shows
up.

---

## Cell 2 — Loading the data

**What it does:** Reads two files into the notebook: the 2,600 fake-but-realistic trips built in
notebook 01 (`journals.csv`), and the extracted facts about each trip built in notebook 02
(`journal_features.csv` — things like whether the trip was written about positively, and what
budget tier it was).

**How it works:** Pandas (a Python tool for working with spreadsheet-like data) reads each CSV
file straight off the disk into a table in memory, the same way opening a CSV in Excel loads it
into rows and columns you can see and work with.

**Why this way:** This notebook doesn't generate its own data — it deliberately reuses what
notebooks 01 and 02 already built, so there's exactly one source of truth for "what actually
happened on each trip," instead of every notebook inventing its own separate copy.

---

## Cell 4 — Building one clean table to learn from

**What it does:** Merges the two files together into a single table, and does some cleanup so
the table is actually usable for training.

**How it works, step by step:**
1. Every trip has a start date. This cell looks at the *month* of that date and sorts it into one
   of 4 seasons: December/January/February = winter, March/April/May = summer, June-September =
   monsoon, October/November = autumn.
2. It sets "party size" to 1 for every trip (we don't currently track group size, so this is a
   placeholder that keeps the door open for it later).
3. It renames a couple of columns to shorter names (`canonical_dest` → `dest`, `travel_style` →
   `style`) just to make the code cleaner to read.
4. It removes any trip where the total cost came out as zero or negative — these would be data
   mistakes, not real trips, and would confuse the model if left in.

**Why this way:** A model can only learn from clean, consistent examples. If we fed it broken
rows (like a trip that "cost" ₹0), it would learn the wrong lesson. Deriving season from the
actual date (rather than asking the user to pick a season) also means this works automatically
for any date in the future.

---

## Cell 5 — Saving the training table + a safety check

**What it does:** Saves the cleaned-up table from Cell 4 as `cost_training_data.csv`, then checks
that every single destination in it is one of our 103 real Gujarat places — nothing else snuck
in by mistake.

**Why this way:** Since the whole point of this project is that it's scoped specifically to
Gujarat, we don't just *assume* that's true — we programmatically prove it every time, so if
something went wrong upstream, we'd catch it here instead of presenting bad results later.

**What's in the CSV (`cost_training_data.csv`, 2,600 rows):** destination, travel style, season,
how many days the trip was, party size, and every cost figure — food, transport, accommodation,
shopping, fees, and the grand total. This is literally "the textbook" the model studies from.

---

## Cell 7 — Actually training the model (the most important cell)

**What it does:** Trains a real machine learning model that can predict what a trip will cost.

**How it works, step by step:**
1. We pick **5 clues** (in ML terms, "features") the model is allowed to use to make its guess:
   which destination, what travel style, what season, how many days, and party size.
2. We split our 2,600 trips into two piles: 80% (about 2,080 trips) for the model to *practice*
   on, and 20% (about 520 trips) that we hide from it completely, to test it fairly afterward —
   like studying from a textbook, then taking a test on questions you've never seen.
3. The model itself is called a **Gradient Boosting Regressor**. In plain terms: it doesn't try
   to learn the answer in one shot. It builds a small, rough guesser first, sees where that
   guesser was wrong, then builds a *second* small guesser whose whole job is to fix those
   specific mistakes, then a third to fix what's still wrong, and so on — 200 rounds of this in
   our case. Each round makes the overall prediction a little more accurate. This is why it's
   called "boosting" — each step boosts the accuracy of the one before it.
4. One important design decision: the model doesn't predict the *total* trip cost directly — it
   predicts the **daily** cost, and we multiply that by the number of days afterward. Why? A
   5-day trip and a 2-day trip to the same place will naturally have very different totals just
   because of the day count — if we let the model predict totals directly, it would spend a lot
   of its "learning power" just re-discovering that longer trips cost more, instead of learning
   the more useful pattern: what actually makes one destination pricier than another.
5. We also train two extra copies of the same kind of model — one that tends to guess *low*, one
   that tends to guess *high*. Together they give us a realistic price *range*, not just one
   number pretending to be exact.
6. Finally, after testing on the hidden 20%, we retrain the model one more time on **all** 2,600
   trips (not just the 80%) before saving it — because once we're done measuring how good it is,
   there's no reason to hold back 20% of the data from the version we actually ship.

**Why Gradient Boosting specifically:** It's good at picking up on the fact that cost doesn't
depend on just one thing in a simple straight-line way — it depends on *combinations* (a luxury
trip to an already-expensive destination in peak season costs a lot more than any one of those
factors alone would suggest). A simpler method often can't capture that kind of combined effect
as well. Gradient Boosting also doesn't need a huge amount of data to work reasonably — a good
fit for our ~2,600 training rows.

---

## Cell 9 — Proving the model actually learned something real

**What it does:** Compares our trained model against two much simpler approaches, on the exact
same hidden 20% test trips, to prove it isn't just getting lucky.

**How it works:**
1. **Baseline 1 — "just guess the average."** For each destination, this looks only at the
   training trips (never touching the hidden test trips) and works out the average cost for that
   place. Then it "predicts" that same average number for every future trip to that place, no
   matter the style, season, or duration. This is the dumbest reasonable guess you could make.
2. **Baseline 2 — Linear Regression.** A genuinely different kind of model, one of the simplest
   in machine learning. It can only learn straight-line relationships (e.g. "every extra day adds
   a fixed amount") and can't combine factors the way Gradient Boosting can.
3. Both baselines get scored the same way our real model was scored, on the same hidden trips, so
   the comparison is completely fair.

**Why we did this:** A single accuracy number means nothing on its own. Saying "our model is
right within ₹2,400 on average" sounds fine until you realize you don't know if that's actually
good. Comparing against a "dumb guess" baseline tells you whether the model is doing real work at
all; comparing against a simpler model tells you whether the extra complexity of Gradient
Boosting is actually earning its place. We report the real result honestly either way — see the
numbers at the bottom of this file.

---

## Cell 10 — Chart: comparing all three approaches

**What it does:** Draws a simple horizontal bar chart, one bar per approach (dumb-average
baseline, Linear Regression, our Gradient Boosting model), showing the average rupee error of
each — shorter bar means more accurate.

**Why a chart here:** Numbers in a table are easy to skim past; a side-by-side bar makes it
immediately obvious, at a glance, which approach wins and by how much.

---

## Cell 12 — Charts: how accurate is it, really?

**What it does:** Draws two charts side by side.

**How to read the left chart (scatter plot):** Every dot is one real trip from the hidden test
set. Its position left-to-right is the *actual* cost; its position up-down is what the model
*guessed*. A dashed diagonal line shows where a perfect guess would land. Dots sitting close to
that line are accurate predictions; dots far above or below it are the model's bigger misses.

**How to read the right chart (histogram):** This takes every single error (`predicted − actual`)
across all the test trips and shows how those errors are distributed. If the model is unbiased,
this should look roughly like a bell shape centered near zero — meaning it's not systematically
guessing too high or too low, and most of its guesses are reasonably close.

**Why both charts together:** The scatter plot shows accuracy trip-by-trip; the histogram shows
the overall *pattern* of the errors. A model could have a "good" average error but still be
secretly biased (e.g. always slightly over-guessing) — the histogram is what would reveal that.

---

## Cell 13 — Chart: which clue matters most?

**What it does:** Shows a bar chart of how much each of the 5 input clues (destination, style,
season, duration, party size) actually influenced the model's decisions.

**How it works:** Gradient Boosting models can report, internally, how often and how usefully
each input was used across all 200 of its rounds of guessing. This cell pulls that information
out and groups it back into our 5 original clues.

**Why this matters:** It's a sanity check on the model's "reasoning." If destination turned out
to barely matter, that would be a red flag — cost genuinely should depend heavily on where you're
going. Seeing destination dominate (with style and season contributing smaller, real amounts)
confirms the model learned something sensible, not something arbitrary.

---

## Cell 15 — Saving the trained model

**What it does:** Saves the finished, trained model to a file called `cost_model.pkl`, inside this
notebook's own folder.

**How it works:** `.pkl` is short for "pickle" — Python's way of saving a working object (in this
case, the entire trained model, ready to make predictions) to a file, so it can be loaded back
later without repeating all the training work from scratch.

**Why its own folder, not the app's shared folder:** This notebook is a self-contained,
Gujarat-only demonstration. Saving its output separately means it can never accidentally
overwrite or interfere with the live app's own production model.

---

## Cell 17 — Predicting cost for every destination

**What it does:** Uses the trained model to guess the cost of a 5-day, mid-range, winter trip to
**every one** of our 103 Gujarat destinations, then charts the 12 cheapest and 12 most expensive
(all 103 would be too cluttered to read on a slide, but the full list is printed as a table right
below the chart, and saved in the CSV).

**Why this cell:** It's the clearest demonstration that the model generalizes — it's not just
memorizing 2,600 specific trips, it can produce a sensible cost estimate for *any* destination in
the catalog, instantly, on demand.

---

## Cell 18 — Does travel style actually change the price?

**What it does:** Asks the model for Ahmedabad's cost at three different styles — backpacker,
mid-range, and luxury — keeping everything else the same (5 days, winter).

**Why this cell:** It's a sanity check in plain numbers: if backpacker came out more expensive
than luxury, something would clearly be wrong. Seeing a sensible order (backpacker cheapest,
luxury priciest) is confirmation the "style" clue is genuinely doing something, not being
ignored.

---

## Cell 20 — Splitting the total into real spending categories

**What it does:** For every destination, breaks its predicted total cost down into 5 categories —
food, transport, accommodation, shopping, and other fees — and charts the top 18 destinations by
cost as a stacked bar (each bar shows all 5 categories stacked on top of each other).

**How it works:** Back in Cell 7, alongside training the main model, we also worked out — for
each destination — what *fraction* of the total cost typically goes to each category (e.g.
"in Ahmedabad, food tends to be about 25% of the total"). This cell applies those fractions to
the model's predicted total for each place.

**Why this matters for the app:** A single total number is useful, but travelers plan around
categories — "how much should I budget for food vs. hotels." This is exactly the data structure
that would power a spending breakdown chart in the actual app.

---

## Cell 22 — Does the season actually change the price?

**What it does:** Asks the model for Ahmedabad's cost across all 4 seasons — winter, summer,
monsoon, autumn — keeping destination, style, and duration fixed, and charts the result.

**Why this cell:** Same idea as Cell 18, but testing the "season" clue instead of "style." It
confirms the model picked up on a real seasonal cost pattern (peak season typically costs more),
rather than treating season as a useless input it ignores.

---

## Putting it all together — the real result

**How good is the model, honestly:** on the hidden test trips it had never seen, its average
guess was off by about **₹2,434**, and it explained about **71%** of why costs vary
(this is called R², a score from 0 to 1 — higher means it captures more of the real pattern).

**Compared to the two baselines:**

| Approach | Average error (₹) |
|---|---|
| Just guess the destination's average cost | 2,851 |
| Linear Regression (a simpler model) | 2,465 |
| **Our Gradient Boosting model** | **2,434** |

Our model beats the dumb "just guess the average" approach by **15%**. It's very close to Linear
Regression — honestly, almost tied — which tells us the underlying cost pattern here is fairly
straightforward, but our model still comes out ahead on the number that matters most to a real
user: how far off the rupee estimate actually is.
