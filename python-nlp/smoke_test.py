"""Smoke test for the Enigma NLP pipeline.

Builds a synthetic topic with (a) an organic spread of varied opinions and
(b) a planted 'coordinated' burst: many young accounts posting near-identical
text within seconds. Runs /analyze and prints what the service found.

Run:  python smoke_test.py
"""
from __future__ import annotations

import json

from app.main import AnalyzeIn, analyze

BASE = 1_718_000_000  # arbitrary epoch start

posts = []
authors = {}
pid = 0


def add(author, text, ts, age_days):
    global pid
    posts.append({"id": pid, "author_id": author, "text": text, "posted_at": ts})
    authors.setdefault(author, {"account_age_days": age_days})
    pid += 1


# --- Organic layer: varied, human, spread over days, older accounts ---
organic = [
    "Honestly the trailer looked fun, might check it out this weekend",
    "The VFX seemed a little rough but Alcock looks great as Kara",
    "Not sure about the tone, feels very Guardians of the Galaxy",
    "I loved the comic this is based on, cautiously optimistic",
    "Momoa as Lobo is inspired casting, that alone sells me a ticket",
    "Reviews are mixed but I'll make up my own mind",
    "The pacing looks off in the trailer but could be editing",
    "Why does every superhero movie need quippy humor now",
]
for k, t in enumerate(organic):
    add(f"user_organic_{k}", t, BASE + k * 36000, age_days=1500 + k * 40)

# --- Coordinated layer: near-identical text, tight window, young accounts ---
# Real coordinated accounts post REPEATEDLY, so each account fires several
# near-identical messages inside the same short window. That repetition is what
# lets author pairs accrue the multiple co-actions the detector requires.
templates = [
    "Supergirl is woke garbage and its going to flop hard",
    "Supergirl is woke garbage and it's going to flop hard!!",
    "Supergirl is woke garbage, going to flop hard",
    "Supergirl is woke garbage and its going to flop hard 100%",
]
burst_start = BASE + 5 * 36000
N_ACCTS, POSTS_EACH = 10, 3
for wave in range(POSTS_EACH):
    for k in range(N_ACCTS):
        add(
            f"burst_acct_{k}",
            templates[(k + wave) % len(templates)],
            burst_start + wave * 25 + k * 2,  # whole burst spans ~70s
            age_days=20 + k,  # freshly created accounts
        )

payload = AnalyzeIn(
    posts=posts,
    authors=authors,
    params={"window_secs": 120, "sim_threshold": 0.8, "min_cluster_size": 3},
)

result = analyze(payload)

print("=== COUNTS ===")
print(result["counts"])

print("\n=== NARRATIVES ===")
for c in result["narratives"]:
    print(f"  [{c['size']:>2}] {c['label']}  (started {c['started_at']})")

print("\n=== REPETITION GROUPS ===")
for g in result["repetition"]:
    print(f"  {g['kind']:>8}  x{g['size']}  :: {g['canonical'][:60]!r}")

print("\n=== COORDINATION CLUSTERS ===")
if not result["coordination"]["clusters"]:
    print("  (none)")
for cl in result["coordination"]["clusters"]:
    print(f"  score={cl['score']} [{cl['label']}]  accounts={len(cl['author_ids'])}")
    print(f"    signals : {cl['signals']}")
    print(f"    baseline: {cl['baseline']}")
    print(f"    evidence posts: {cl['evidence_post_ids'][:8]}")

# sanity assertions
assert result["counts"]["posts"] == len(posts)
assert any(g["kind"] in ("exact", "near", "template") for g in result["repetition"]), "expected repetition"
assert result["coordination"]["clusters"], "expected at least one coordination cluster"
top = result["coordination"]["clusters"][0]
assert top["label"] in ("moderate", "strong"), f"expected the planted burst to score up, got {top['label']}"
print("\nAll smoke-test assertions passed.")

# also dump a sample payload file for the Laravel devs
with open("sample_analyze_payload.json", "w") as f:
    json.dump(json.loads(payload.model_dump_json()), f, indent=2)
print("Wrote sample_analyze_payload.json")
