"""Narrative clustering.

Groups posts by talking point using agglomerative clustering on embeddings
(cosine). For each cluster we surface: a keyword label, size, start/peak times,
and representative posts. Swap in BERTopic later for nicer labels — same output
shape.
"""
from __future__ import annotations

from collections import Counter

import numpy as np

_STOP = set(
    "the a an and or but is are was were be been being to of in on for with at by "
    "this that it its they them he she you we i as so if then than too very just "
    "movie film about not no yes get got like really".split()
)


def _keywords(texts: list[str], top: int = 5) -> list[str]:
    counts: Counter = Counter()
    for t in texts:
        for w in (t or "").lower().split():
            w = "".join(ch for ch in w if ch.isalnum())
            if len(w) > 2 and w not in _STOP:
                counts[w] += 1
    return [w for w, _ in counts.most_common(top)]


def _bucket_peak(timestamps: list[float], bucket_secs: int = 3600) -> float | None:
    if not timestamps:
        return None
    buckets: Counter = Counter()
    for ts in timestamps:
        buckets[int(ts // bucket_secs)] += 1
    peak_bucket = max(buckets, key=buckets.get)
    return peak_bucket * bucket_secs


def cluster_narratives(
    posts: list[dict],
    embeddings: np.ndarray,
    distance_threshold: float = 0.75,
    min_cluster_size: int = 3,
) -> list[dict]:
    n = len(posts)
    if n < min_cluster_size:
        return []

    from sklearn.cluster import AgglomerativeClustering

    model = AgglomerativeClustering(
        n_clusters=None,
        metric="cosine",
        linkage="average",
        distance_threshold=distance_threshold,
    )
    labels = model.fit_predict(embeddings)

    narratives: list[dict] = []
    for lab in sorted(set(labels)):
        idxs = [i for i in range(n) if labels[i] == lab]
        if len(idxs) < min_cluster_size:
            continue
        centroid = embeddings[idxs].mean(axis=0)
        centroid = centroid / (np.linalg.norm(centroid) or 1.0)
        sims = embeddings[idxs] @ centroid
        order = np.argsort(-sims)
        rep_idxs = [idxs[o] for o in order[:3]]
        times = [p["ts"] for p in (posts[i] for i in idxs)]
        narratives.append(
            {
                "label": " / ".join(_keywords([posts[i]["text"] for i in idxs])) or f"cluster {lab}",
                "keywords": _keywords([posts[i]["text"] for i in idxs]),
                "members": idxs,
                "size": len(idxs),
                "started_at": min(times) if times else None,
                "peaked_at": _bucket_peak(times),
                "representative_members": rep_idxs,
            }
        )
    narratives.sort(key=lambda c: c["size"], reverse=True)
    return narratives
