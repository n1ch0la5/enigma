"""Repetition detection: exact duplicates, near-duplicates, and template variants.

Bad actors (and copy-paste brigades) repeat. This is one of the cheapest, highest
signal features. Returns groups referencing post indices; the caller maps indices
back to post ids.
"""
from __future__ import annotations

import hashlib
import re

import numpy as np

_WS = re.compile(r"\s+")
_NONWORD = re.compile(r"[^a-z0-9\s]")
_NUM = re.compile(r"\d+")


def normalize_text(t: str) -> str:
    t = (t or "").lower()
    t = _NONWORD.sub(" ", t)
    return _WS.sub(" ", t).strip()


def template_skeleton(t: str) -> str:
    """Collapse numbers/punctuation so 'movie is dead', 'movie is dead!!', and
    'movie is dead 100%' share a skeleton."""
    return _NUM.sub("#", normalize_text(t))


class _UnionFind:
    def __init__(self, n: int):
        self.parent = list(range(n))

    def find(self, x: int) -> int:
        while self.parent[x] != x:
            self.parent[x] = self.parent[self.parent[x]]
            x = self.parent[x]
        return x

    def union(self, a: int, b: int) -> None:
        ra, rb = self.find(a), self.find(b)
        if ra != rb:
            self.parent[rb] = ra


def _near_dup_groups(embeddings: np.ndarray, threshold: float) -> list[list[int]]:
    n = embeddings.shape[0]
    if n < 2:
        return []
    uf = _UnionFind(n)
    # O(n^2); fine at case-study scale. For large corpora, swap in a pgvector /
    # ANN pre-filter and only compare candidate neighbours.
    sims = embeddings @ embeddings.T
    for i in range(n):
        row = sims[i]
        for j in range(i + 1, n):
            if row[j] >= threshold:
                uf.union(i, j)
    groups: dict[int, list[int]] = {}
    for i in range(n):
        groups.setdefault(uf.find(i), []).append(i)
    return [g for g in groups.values() if len(g) > 1]


def detect_repetition(
    texts: list[str],
    embeddings: np.ndarray | None = None,
    near_threshold: float = 0.95,
) -> list[dict]:
    groups: list[dict] = []

    # 1. Exact (after normalization)
    exact: dict[str, list[int]] = {}
    for i, t in enumerate(texts):
        key = hashlib.sha1(normalize_text(t).encode()).hexdigest()
        exact.setdefault(key, []).append(i)
    exact_members = set()
    for idxs in exact.values():
        if len(idxs) > 1:
            exact_members.update(idxs)
            groups.append(
                {"kind": "exact", "canonical": texts[idxs[0]], "members": idxs, "size": len(idxs)}
            )

    # 2. Template variants (share a skeleton but aren't exact dups)
    templ: dict[str, list[int]] = {}
    for i, t in enumerate(texts):
        templ.setdefault(template_skeleton(t), []).append(i)
    for skel, idxs in templ.items():
        if len(idxs) > 1 and not set(idxs).issubset(exact_members):
            groups.append(
                {"kind": "template", "canonical": skel, "members": idxs, "size": len(idxs)}
            )

    # 3. Near-duplicates (semantically ~identical, not caught above)
    if embeddings is not None:
        for idxs in _near_dup_groups(embeddings, near_threshold):
            if not set(idxs).issubset(exact_members):
                groups.append(
                    {"kind": "near", "canonical": texts[idxs[0]], "members": idxs, "size": len(idxs)}
                )

    return groups
