"""
JourneyAI — shared tokenizer for the TF-IDF vectorizer.

Lives in its own tiny module so the pickled TfidfVectorizer's `tokenizer`
reference resolves to a stable import path (ml.nlp.tokenizer.tokenize)
regardless of which script unpickles it. (Pickling a function defined in a
__main__ script binds it to __main__, which breaks on reload — this avoids that.)
"""
import re

_TOKEN = re.compile(r"[a-z']+")

# Optional NLTK lemmatization — degrade gracefully if data missing.
try:
    from nltk.stem import WordNetLemmatizer
    _LEMMA = WordNetLemmatizer()
    _LEMMA.lemmatize("tests")  # force lookup; raises if wordnet absent
except Exception:  # pragma: no cover
    _LEMMA = None


def tokenize(text):
    toks = _TOKEN.findall((text or "").lower())
    if _LEMMA:
        return [_LEMMA.lemmatize(t) for t in toks]
    return toks
