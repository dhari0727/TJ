"""
JourneyAI — merges a batch of researched town entries (JSON, matching the
TOWN_SCHEMA shape used by the state-town-highlights workflow) into
ml/geo/local_highlights.py's TOWNS dict, without hand-editing the file.

Usage: python -m ml.geo._merge_highlights <path-to-results.json>
results.json shape: {"towns": [{"key":..., "character":..., "landmarks":[...], "food":[...]}]}
Idempotent: re-running with the same key overwrites that town's entry.
"""
import ast
import json
import re
import sys
from pathlib import Path

HIGHLIGHTS_PATH = Path(__file__).parent / "local_highlights.py"


def _py_repr(obj, indent=0):
    """Pretty-print a dict/list as Python source, matching the file's style."""
    pad = "    " * indent
    if isinstance(obj, dict):
        lines = ["{"]
        for k, v in obj.items():
            lines.append(f'{pad}    {k!r}: {_py_repr(v, indent + 1)},')
        lines.append(f"{pad}}}")
        return "\n".join(lines)
    if isinstance(obj, list):
        if not obj:
            return "[]"
        lines = ["["]
        for item in obj:
            lines.append(f"{pad}    {_py_repr(item, indent + 1)},")
        lines.append(f"{pad}]")
        return "\n".join(lines)
    return repr(obj)


def merge(results_path):
    data = json.loads(Path(results_path).read_text(encoding="utf-8"))
    towns = data.get("towns", data) if isinstance(data, dict) else data

    src = HIGHLIGHTS_PATH.read_text(encoding="utf-8")

    # locate the TOWNS = { ... } block by parsing, so we can splice safely
    tree = ast.parse(src)
    towns_assign = None
    for node in tree.body:
        if isinstance(node, ast.Assign) and any(
            isinstance(t, ast.Name) and t.id == "TOWNS" for t in node.targets
        ):
            towns_assign = node
            break
    if towns_assign is None:
        raise RuntimeError("Could not find TOWNS = {...} in local_highlights.py")

    end_line = towns_assign.end_lineno
    lines = src.splitlines(keepends=True)
    before = "".join(lines[:towns_assign.lineno - 1])
    dict_src = "".join(lines[towns_assign.lineno - 1:end_line])
    after = "".join(lines[end_line:])

    # eval the existing dict literal (safe: our own generated file, ast.literal_eval)
    eq_idx = dict_src.index("=")
    existing = ast.literal_eval(dict_src[eq_idx + 1:].strip())

    added, updated = 0, 0
    for t in towns:
        key = t["key"].strip().lower()
        entry = {
            "character": t["character"],
            "landmarks": [
                {"name": lm["name"], "category": lm["category"], "note": lm["note"]}
                for lm in t.get("landmarks", [])
            ],
            "food": [
                {"dish": f["dish"], "where": f["where"], "note": f["note"]}
                for f in t.get("food", [])
            ],
        }
        if key in existing:
            updated += 1
        else:
            added += 1
        existing[key] = entry

    new_dict_src = "TOWNS = " + _py_repr(existing) + "\n"
    HIGHLIGHTS_PATH.write_text(before + new_dict_src + after, encoding="utf-8")
    print(f"Merged {len(towns)} towns ({added} added, {updated} updated). Total now: {len(existing)}")


if __name__ == "__main__":
    merge(sys.argv[1])
