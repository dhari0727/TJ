"""
JourneyAI — one-shot pipeline rebuild.

Runs the full offline pipeline in order:
    seed corpus -> extract NLP features -> build destination profiles
    -> train cost model
After this, `python -m ml.app` will serve fully-trained models.

Usage:
    python -m ml.rebuild_all               # reseed (n=500) + retrain everything
    python -m ml.rebuild_all --no-seed     # keep existing data, just re-extract+train
    python -m ml.rebuild_all --n 800       # bigger corpus
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
    ap.add_argument("--no-seed", action="store_true", help="skip corpus generation")
    ap.add_argument("--n", type=int, default=500)
    ap.add_argument("--seed", type=int, default=42)
    args = ap.parse_args()

    if not args.no_seed:
        run("ml.seed.generate_corpus", "--seed", str(args.seed),
            "--n", str(args.n), "--truncate")
    run("ml.nlp.extract_features")
    run("ml.nlp.build_profiles")
    run("ml.cost.train_cost_model")
    print("\n" + "=" * 60)
    print("Pipeline complete. Start the service with:  python -m ml.app")
    print("=" * 60)


if __name__ == "__main__":
    main()
