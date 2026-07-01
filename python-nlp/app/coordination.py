"""Coordination detection.

Builds an author co-action graph (accounts posting near-identical content within
a short time window, repeatedly), finds dense communities, and scores each on
several INDEPENDENT signals. Critically:

  * Every cluster ships with EVIDENCE (the actual synchronized posts) and a
    BASELINE (a permutation null: how much synchrony we'd expect by chance).
  * The score is a transparent blend of signals, never a black box.
  * Coordinated-looking != proven-inauthentic. Real fandoms brigade organically.
    This flags patterns for a human to interpret; it does not assign blame.
"""
from __future__ import annotations

import numpy as np
import networkx as nx


def _logistic(x: float, k: float = 1.0) -> float:
    return 1.0 / (1.0 + np.exp(-k * x))


def _count_coactions(
    posts: list[dict],
    embeddings: np.ndarray,
    order: np.ndarray,
    window_secs: int,
    sim_threshold: float,
) -> tuple[dict, dict]:
    """Return {(a,b): count} and {(a,b): [(i,j), ...]} for author pairs that post
    similar content within `window_secs`, iterating time-sorted with a forward window."""
    counts: dict[tuple, int] = {}
    evidence: dict[tuple, list] = {}
    n = len(posts)
    for a in range(n):
        i = int(order[a])
        ti, ai = posts[i]["ts"], posts[i]["author_id"]
        for b in range(a + 1, n):
            j = int(order[b])
            if posts[j]["ts"] - ti > window_secs:
                break  # sorted by time => nothing further is in-window
            aj = posts[j]["author_id"]
            if aj == ai:
                continue
            if float(embeddings[i] @ embeddings[j]) >= sim_threshold:
                key = (ai, aj) if ai <= aj else (aj, ai)
                counts[key] = counts.get(key, 0) + 1
                evidence.setdefault(key, []).append((i, j))
    return counts, evidence


def _baseline_coactions(
    posts: list[dict],
    embeddings: np.ndarray,
    window_secs: int,
    sim_threshold: float,
    runs: int = 20,
    seed: int = 7,
) -> dict:
    """Permutation null: shuffle timestamps across posts and recount total
    co-actions. Tells us how much synchrony is expected by chance."""
    rng = np.random.default_rng(seed)
    ts = np.array([p["ts"] for p in posts], dtype="float64")
    totals = []
    for _ in range(runs):
        shuffled = rng.permutation(ts)
        tmp = [dict(p, ts=float(shuffled[k])) for k, p in enumerate(posts)]
        order = np.argsort([p["ts"] for p in tmp])
        counts, _ = _count_coactions(tmp, embeddings, order, window_secs, sim_threshold)
        totals.append(sum(counts.values()))
    return {"mean": float(np.mean(totals)), "std": float(np.std(totals) or 1.0)}


def _cadence_regularity(author_ts: list[float]) -> float:
    """1.0 = perfectly regular (machine-like) intervals, 0.0 = irregular/human."""
    if len(author_ts) < 3:
        return 0.0
    intervals = np.diff(sorted(author_ts))
    if intervals.mean() == 0:
        return 1.0
    cv = intervals.std() / intervals.mean()  # coefficient of variation
    return float(max(0.0, 1.0 - cv))  # low variation => high regularity


def detect_coordination(
    posts: list[dict],
    embeddings: np.ndarray,
    window_secs: int = 120,
    sim_threshold: float = 0.90,
    min_coactions: int = 2,
    authors: dict | None = None,
) -> dict:
    """posts: [{'author_id', 'ts' (epoch secs), 'text'}]. Returns edges + scored clusters."""
    n = len(posts)
    authors = authors or {}
    if n < 2:
        return {"edges": [], "clusters": [], "baseline": {"mean": 0.0, "std": 1.0}}

    order = np.argsort([p["ts"] for p in posts])
    counts, evidence = _count_coactions(posts, embeddings, order, window_secs, sim_threshold)
    baseline = _baseline_coactions(posts, embeddings, window_secs, sim_threshold)

    edges = [
        {"author_a": a, "author_b": b, "weight": c, "window_secs": window_secs}
        for (a, b), c in counts.items()
        if c >= min_coactions
    ]

    G = nx.Graph()
    for e in edges:
        G.add_edge(e["author_a"], e["author_b"], weight=e["weight"])

    clusters: list[dict] = []
    if G.number_of_edges():
        communities = nx.algorithms.community.greedy_modularity_communities(G, weight="weight")
        # index posts by author for per-cluster stats
        by_author: dict = {}
        for idx, p in enumerate(posts):
            by_author.setdefault(p["author_id"], []).append(idx)

        for comm in communities:
            members = sorted(comm)
            if len(members) < 2:
                continue
            sub = G.subgraph(members)

            # --- Signal: overlap (how densely the community is wired together)
            overlap = nx.density(sub)

            # --- Signal: synchrony (observed co-actions vs permutation baseline)
            obs = sum(d["weight"] for *_e, d in sub.edges(data=True))
            z = (obs - baseline["mean"]) / baseline["std"]
            synchrony = float(_logistic(z, k=0.5))

            # --- Signal: content similarity within the community
            m_idxs = [i for a in members for i in by_author.get(a, [])]
            if len(m_idxs) > 1:
                sub_emb = embeddings[m_idxs]
                sims = sub_emb @ sub_emb.T
                iu = np.triu_indices(len(m_idxs), k=1)
                similarity = float(sims[iu].mean())
            else:
                similarity = 0.0

            # --- Signal: cadence regularity (machine-like posting)
            cad = [
                _cadence_regularity([posts[i]["ts"] for i in by_author.get(a, [])])
                for a in members
            ]
            cadence = float(np.mean(cad)) if cad else 0.0

            # --- Signal: account age (share of members with young accounts), optional
            young = None
            ages = [authors.get(a, {}).get("account_age_days") for a in members]
            ages = [x for x in ages if x is not None]
            if ages:
                young = float(np.mean([1.0 if x is not None and x < 180 else 0.0 for x in ages]))

            signals = {
                "synchrony": round(synchrony, 3),
                "similarity": round(similarity, 3),
                "overlap": round(overlap, 3),
                "cadence": round(cadence, 3),
            }
            if young is not None:
                signals["young_account_share"] = round(young, 3)

            score = float(np.mean(list(signals.values())))
            label = "strong" if score >= 0.66 else "moderate" if score >= 0.4 else "weak"

            # --- Evidence: the actual synchronized post pairs for this community
            ev_pairs = []
            mset = set(members)
            for (a, b), pairs in evidence.items():
                if a in mset and b in mset:
                    ev_pairs.extend(pairs[:5])

            clusters.append(
                {
                    "author_ids": members,
                    "score": round(score, 3),
                    "label": label,
                    "signals": signals,
                    "baseline": {
                        "expected_coactions": round(baseline["mean"], 2),
                        "observed_coactions": int(obs),
                    },
                    "evidence_post_pairs": ev_pairs[:20],
                }
            )
        clusters.sort(key=lambda c: c["score"], reverse=True)

    return {"edges": edges, "clusters": clusters, "baseline": baseline}
