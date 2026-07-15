"""
JourneyAI — NLP lexicons (pure data, no dependencies).

Two hand-curated resources used by ml/nlp/extract_features.py:

  1. ACTIVITY_GAZETTEER — maps surface keywords/phrases found in journal text
     to canonical activity tags. The canonical tags are the SAME vocabulary
     used by the destination catalog and the recommender, so extracted
     interests line up with destination profiles.

  2. Sentiment lexicon (POSITIVE_WORDS / NEGATIVE_WORDS / NEGATORS /
     INTENSIFIERS) — a domain-tuned polarity lexicon for a lightweight,
     fully-explainable sentiment score (no VADER/transformers needed).

Kept in sync with:
  - ml/seed/destination_catalog.py  (ACTIVITIES list)
  - ml/nlp/extract_features.py       (consumer)
"""

# Canonical activity tags (must match destination_catalog.ACTIVITIES).
CANONICAL_ACTIVITIES = [
    "beach", "trekking", "food", "nightlife", "history", "temples",
    "museums", "shopping", "wildlife", "adventure", "relaxation",
    "photography", "nature", "culture", "backwaters", "mountains",
    "desert", "snow", "diving", "architecture",
]

# keyword/phrase -> canonical activity. Lowercased matching on the raw text.
ACTIVITY_GAZETTEER = {
    # beach
    "beach": "beach", "beaches": "beach", "shore": "beach", "coast": "beach",
    "sand": "beach", "seaside": "beach", "surf": "beach",
    # trekking / hiking
    "trek": "trekking", "trekking": "trekking", "hike": "trekking",
    "hiking": "trekking", "trail": "trekking", "trails": "trekking",
    # food
    "food": "food", "cuisine": "food", "restaurant": "food", "restaurants": "food",
    "street food": "food", "cafe": "food", "cafes": "food", "dining": "food",
    "culinary": "food", "delicacies": "food", "eat": "food",
    # nightlife
    "nightlife": "nightlife", "bar": "nightlife", "bars": "nightlife",
    "club": "nightlife", "clubs": "nightlife", "party": "nightlife",
    "parties": "nightlife", "pub": "nightlife",
    # history
    "history": "history", "historic": "history", "historical": "history",
    "ancient": "history", "heritage": "history", "ruins": "history",
    "fort": "history", "forts": "history", "palace": "history", "castle": "history",
    # temples / spiritual
    "temple": "temples", "temples": "temples", "shrine": "temples",
    "monastery": "temples", "spiritual": "temples", "pilgrimage": "temples",
    "ghat": "temples", "ghats": "temples", "aarti": "temples",
    # museums
    "museum": "museums", "museums": "museums", "gallery": "museums",
    "galleries": "museums", "exhibition": "museums", "art": "museums",
    # shopping
    "shopping": "shopping", "market": "shopping", "markets": "shopping",
    "bazaar": "shopping", "souvenir": "shopping", "souvenirs": "shopping",
    "handicraft": "shopping", "boutique": "shopping",
    # wildlife
    "wildlife": "wildlife", "safari": "wildlife", "jungle": "wildlife",
    "national park": "wildlife", "sanctuary": "wildlife", "birds": "wildlife",
    "tiger": "wildlife", "elephant": "wildlife",
    # adventure
    "adventure": "adventure", "rafting": "adventure", "paragliding": "adventure",
    "zip line": "adventure", "ziplining": "adventure", "bungee": "adventure",
    "kayak": "adventure", "kayaking": "adventure", "thrill": "adventure",
    # relaxation
    "relax": "relaxation", "relaxation": "relaxation", "relaxing": "relaxation",
    "spa": "relaxation", "peaceful": "relaxation", "serene": "relaxation",
    "unwind": "relaxation", "tranquil": "relaxation", "calm": "relaxation",
    # photography
    "photography": "photography", "photo": "photography", "photos": "photography",
    "photogenic": "photography", "instagram": "photography", "scenic": "photography",
    "views": "photography", "viewpoint": "photography",
    # nature
    "nature": "nature", "waterfall": "nature", "waterfalls": "nature",
    "lake": "nature", "valley": "nature", "greenery": "nature",
    "forest": "nature", "gardens": "nature", "tea garden": "nature",
    "meadow": "nature", "meadows": "nature",
    # culture
    "culture": "culture", "cultural": "culture", "tradition": "culture",
    "traditional": "culture", "festival": "culture", "local life": "culture",
    "village": "culture", "villages": "culture", "tribal": "culture",
    # backwaters
    "backwater": "backwaters", "backwaters": "backwaters", "houseboat": "backwaters",
    # mountains
    "mountain": "mountains", "mountains": "mountains", "himalaya": "mountains",
    "himalayas": "mountains", "peak": "mountains", "hills": "mountains",
    "hill station": "mountains", "summit": "mountains",
    # desert
    "desert": "desert", "dunes": "desert", "rann": "desert", "sand dunes": "desert",
    # snow
    "snow": "snow", "snowfall": "snow", "ski": "snow", "skiing": "snow",
    "snowy": "snow", "glacier": "snow",
    # diving / water
    "diving": "diving", "scuba": "diving", "snorkel": "diving",
    "snorkeling": "diving", "reef": "diving",
    # architecture
    "architecture": "architecture", "architectural": "architecture",
    "monument": "architecture", "monuments": "architecture", "cathedral": "architecture",
    "mosque": "architecture", "buildings": "architecture", "old town": "architecture",
}

# ---------------------------------------------------------------------------
# Sentiment lexicon (domain-tuned travel vocabulary).
# ---------------------------------------------------------------------------
POSITIVE_WORDS = {
    "breathtaking", "unforgettable", "stunning", "magical", "delightful",
    "serene", "vibrant", "charming", "spectacular", "wonderful", "peaceful",
    "gorgeous", "incredible", "lovely", "memorable", "amazing", "beautiful",
    "fantastic", "excellent", "perfect", "awesome", "enjoyed", "enjoyable",
    "relaxing", "friendly", "clean", "comfortable", "affordable", "worth",
    "recommend", "recommended", "highlight", "loved", "love", "great",
    "picturesque", "pristine", "authentic", "warm", "welcoming", "smooth",
    "delicious", "tasty", "scenic", "refreshing", "blissful", "paradise",
    "impressive", "exquisite", "cozy", "value", "convenient", "helpful",
    "happy", "joy", "fun", "best", "good", "nice", "pleasant", "splendid",
    "heavenly", "captivating", "mesmerizing", "tranquil", "delighted",
}

NEGATIVE_WORDS = {
    "disappointing", "overcrowded", "overpriced", "underwhelming", "chaotic",
    "exhausting", "frustrating", "dull", "stressful", "mediocre", "dirty",
    "expensive", "crowded", "noisy", "rude", "unsafe", "scam", "scammed",
    "boring", "bad", "worst", "poor", "terrible", "horrible", "awful",
    "avoid", "regret", "waste", "wasted", "uncomfortable", "delayed",
    "cancelled", "hassle", "difficult", "tiring", "polluted", "smelly",
    "unhygienic", "cheated", "annoying", "disappointment", "unpleasant",
    "cramped", "shabby", "broken", "cold", "hot", "tourist trap", "sadly",
    "unfortunately", "lacking", "lacked", "problem", "problems", "issue",
}

# words that flip polarity of the next sentiment word
NEGATORS = {"not", "no", "never", "hardly", "barely", "isn't", "wasn't",
            "aren't", "weren't", "didn't", "don't", "doesn't", "without", "nothing"}

# words that amplify the next sentiment word (weight multiplier)
INTENSIFIERS = {"very", "really", "extremely", "absolutely", "so", "incredibly",
                "truly", "quite", "super", "totally", "completely", "highly"}


def match_activities(text):
    """Return the set of canonical activity tags present in `text`."""
    t = " " + text.lower() + " "
    found = set()
    for phrase, tag in ACTIVITY_GAZETTEER.items():
        # phrase boundary match (handles multi-word phrases too)
        if f" {phrase} " in t or f" {phrase}." in t or f" {phrase}," in t:
            found.add(tag)
    return found


if __name__ == "__main__":
    print("canonical activities:", len(CANONICAL_ACTIVITIES))
    print("gazetteer keys:", len(ACTIVITY_GAZETTEER))
    print("positive words:", len(POSITIVE_WORDS), "negative words:", len(NEGATIVE_WORDS))
    demo = ("We spent 3 days on the beach and loved the food. The temples were "
            "stunning but the market was overcrowded and overpriced.")
    print("demo activities:", sorted(match_activities(demo)))
