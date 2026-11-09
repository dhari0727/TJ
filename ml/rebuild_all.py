"""
JourneyAI — one-shot pipeline rebuild.

Runs the full offline pipeline in order:
    seed corpus -> extract NLP features -> build destination profiles
    -> train cost model
After this, `python -m ml.app` will serve fully-trained models.

Usage:
    python -m ml.rebuild_all               # reload real-sourced entries + retrain (no synthetic data)
    python -m ml.rebuild_all --no-real     # keep existing data, just re-extract+train
    python -m ml.rebuild_all --with-seed   # DEV ONLY: also generate a synthetic corpus (never on production)
"""
import argparse
import subprocess
import sys
import os

PY = sys.executable
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))


def run(mod, *args):
    print(f"\n{'='*60}\n>>> {mod} {' '.join(args)}\n{'='*60}")
    r = subprocess.run([PY, "-m", mod, *args], cwd=ROOT)
    if r.returncode != 0:
        print(f"STEP FAILED: {mod}")
        sys.exit(r.returncode)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--with-seed", action="store_true", help="dev only: generate a synthetic corpus (fake data)")
    ap.add_argument("--no-seed", action="store_true", help=argparse.SUPPRESS)  # kept for old scripts; seeding is now opt-in
    ap.add_argument("--no-real", action="store_true", help="skip loading real-sourced entries")
    ap.add_argument("--n", type=int, default=1500)
    ap.add_argument("--seed", type=int, default=42)
    args = ap.parse_args()

    if args.with_seed and not args.no_seed:
        run("ml.seed.generate_corpus", "--seed", str(args.seed),
            "--n", str(args.n), "--truncate")
    if not args.no_real:
        # real-sourced entries (Wikivoyage + blog extracts); needs ml/data/*.json from ml.scrape.*
        run("ml.seed.load_real_entries", "--truncate")
    run("ml.nlp.extract_features")
    run("ml.nlp.build_profiles")
    run("ml.cost.train_cost_model")
    print("\n" + "=" * 60)
    print("Pipeline complete. Start the service with:  python -m ml.app")
    print("=" * 60)


if __name__ == "__main__":
    main()
