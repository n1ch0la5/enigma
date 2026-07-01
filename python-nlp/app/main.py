"""Enigma NLP microservice — FastAPI app.

Endpoints:
  GET  /health
  POST /embed         -> vectors for a list of texts
  POST /analyze       -> full pipeline: narratives + repetition + coordination

Laravel calls /analyze with a topic's normalized posts and persists the result.
The service is stateless.
"""
from __future__ import annotations

from datetime import datetime, timezone

from dateutil import parser as dtparser
from fastapi import FastAPI
from pydantic import BaseModel, Field

from .clustering import cluster_narratives
from .coordination import detect_coordination
from .embeddings import Embedder
from .repetition import detect_repetition

app = FastAPI(title="Enigma NLP", version="0.1.0")
_embedder = Embedder()


# --------------------------------------------------------------------------- #
# Schemas
# --------------------------------------------------------------------------- #
class PostIn(BaseModel):
    id: int | str
    author_id: int | str
    text: str
    posted_at: str | float | int  # ISO-8601 string or epoch seconds


class AnalyzeParams(BaseModel):
    window_secs: int = 120
    sim_threshold: float = 0.90
    near_threshold: float = 0.95
    cluster_distance: float = 0.75
    min_cluster_size: int = 3
    min_coactions: int = 2


class AnalyzeIn(BaseModel):
    posts: list[PostIn]
    authors: dict[str, dict] = Field(default_factory=dict)  # author_id -> {account_age_days}
    params: AnalyzeParams = Field(default_factory=AnalyzeParams)


class EmbedIn(BaseModel):
    texts: list[str]


# --------------------------------------------------------------------------- #
# Helpers
# --------------------------------------------------------------------------- #
def _to_epoch(v) -> float:
    if isinstance(v, (int, float)):
        return float(v)
    dt = dtparser.parse(v)
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.timestamp()


def _iso(ts: float | None) -> str | None:
    if ts is None:
        return None
    return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()


# --------------------------------------------------------------------------- #
# Routes
# --------------------------------------------------------------------------- #
@app.get("/health")
def health():
    return {"status": "ok", "backend": _embedder.backend}


@app.post("/embed")
def embed(body: EmbedIn):
    vecs = _embedder.embed(body.texts)
    return {"dim": int(vecs.shape[1]) if vecs.size else 0, "vectors": vecs.tolist()}


@app.post("/analyze")
def analyze(body: AnalyzeIn):
    posts = [
        {
            "id": p.id,
            "author_id": str(p.author_id),
            "text": p.text,
            "ts": _to_epoch(p.posted_at),
        }
        for p in body.posts
    ]
    ids = [p["id"] for p in posts]
    texts = [p["text"] for p in posts]

    if not posts:
        return {"narratives": [], "repetition": [], "coordination": {"edges": [], "clusters": []}}

    embeddings = _embedder.embed(texts)
    P = body.params

    # 1. Narratives
    narratives = cluster_narratives(
        posts, embeddings, distance_threshold=P.cluster_distance, min_cluster_size=P.min_cluster_size
    )
    for c in narratives:
        c["post_ids"] = [ids[i] for i in c.pop("members")]
        c["representative_post_ids"] = [ids[i] for i in c.pop("representative_members")]
        c["started_at"] = _iso(c["started_at"])
        c["peaked_at"] = _iso(c["peaked_at"])

    # 2. Repetition
    rep = detect_repetition(texts, embeddings, near_threshold=P.near_threshold)
    for g in rep:
        g["post_ids"] = [ids[i] for i in g.pop("members")]

    # 3. Coordination
    coord = detect_coordination(
        posts,
        embeddings,
        window_secs=P.window_secs,
        sim_threshold=P.sim_threshold,
        min_coactions=P.min_coactions,
        authors={str(k): v for k, v in body.authors.items()},
    )
    for cl in coord["clusters"]:
        cl["evidence_post_ids"] = sorted(
            {ids[i] for pair in cl.pop("evidence_post_pairs") for i in pair}
        )

    return {
        "counts": {"posts": len(posts), "narratives": len(narratives), "dup_groups": len(rep)},
        "narratives": narratives,
        "repetition": rep,
        "coordination": {
            "edges": coord["edges"],
            "clusters": coord["clusters"],
            "baseline": coord["baseline"],
        },
    }
