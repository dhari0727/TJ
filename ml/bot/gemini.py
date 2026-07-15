"""
JourneyAI — Gemini LLM client (REST, no extra dependency).

WHERE TO PUT YOUR GEMINI API KEY (either one works):
  1. Create a file:  ml/bot/gemini_key.txt   with just the key inside, OR
  2. Set env var:    JOURNEYAI_GEMINI_KEY=your_key

Get a FREE key (no billing card for basic use):
  https://aistudio.google.com/app/apikey

The key file is gitignored — it will NOT be committed/pushed.

This module handles the function-calling loop: it sends the user message +
tool schemas to Gemini, executes any tool calls JourneyAI exposes, feeds the
results back, and returns the final natural-language answer + any structured
data (places, itinerary, media) for the UI to render.
"""
import json
import os
import urllib.request
import urllib.parse

MODEL = os.environ.get("JOURNEYAI_GEMINI_MODEL", "gemini-2.5-flash")
API = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}"


def get_key():
    k = os.environ.get("JOURNEYAI_GEMINI_KEY", "").strip()
    if k:
        return k
    p = os.path.join(os.path.dirname(__file__), "gemini_key.txt")
    if os.path.exists(p):
        try:
            return open(p, encoding="utf-8").read().strip()
        except Exception:
            return None
    return None


def has_key():
    return bool(get_key())


def _post(payload, timeout=45):
    key = get_key()
    if not key:
        raise RuntimeError("No Gemini API key configured.")
    url = API.format(model=MODEL, key=key)
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8"))


def generate(contents, tools=None, system=None, timeout=45):
    """Low-level generateContent call. `contents` is Gemini's message list."""
    payload = {"contents": contents}
    if tools:
        payload["tools"] = [{"function_declarations": tools}]
        payload["tool_config"] = {"function_calling_config": {"mode": "AUTO"}}
    if system:
        payload["system_instruction"] = {"parts": [{"text": system}]}
    payload["generationConfig"] = {"temperature": 0.6, "maxOutputTokens": 1200}
    return _post(payload, timeout=timeout)


def extract_text(resp):
    try:
        parts = resp["candidates"][0]["content"]["parts"]
        return "".join(p.get("text", "") for p in parts if "text" in p).strip()
    except Exception:
        return ""


def extract_calls(resp):
    """Return list of {name, args} function calls Gemini wants to make."""
    calls = []
    try:
        for p in resp["candidates"][0]["content"]["parts"]:
            fc = p.get("functionCall")
            if fc:
                calls.append({"name": fc["name"], "args": fc.get("args", {})})
    except Exception:
        pass
    return calls


if __name__ == "__main__":
    if not has_key():
        print("No Gemini key. Put it in ml/bot/gemini_key.txt or env "
              "JOURNEYAI_GEMINI_KEY. Free key: https://aistudio.google.com/app/apikey")
    else:
        r = generate([{"role": "user", "parts": [{"text": "Say hi in 5 words."}]}])
        print("Gemini says:", extract_text(r))
