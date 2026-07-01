"""Pluggable text embedding.

Default backend is TF-IDF (fit per analysis batch) so the service runs with no
model download. Set EMBED_BACKEND=minilm to use sentence-transformers instead.

All vectors are L2-normalized, so cosine similarity == dot product.
"""
from __future__ import annotations

import os
import numpy as np


def l2_normalize(arr: np.ndarray) -> np.ndarray:
    arr = np.asarray(arr, dtype="float32")
    norms = np.linalg.norm(arr, axis=1, keepdims=True)
    norms[norms == 0] = 1.0
    return arr / norms


class Embedder:
    def __init__(self, backend: str | None = None):
        self.backend = backend or os.getenv("EMBED_BACKEND", "tfidf")
        self._model = None
        self.vectorizer = None  # exposed so callers can reuse vocab for keywords

    def embed(self, texts: list[str]) -> np.ndarray:
        if not texts:
            return np.zeros((0, 1), dtype="float32")
        if self.backend == "minilm":
            return self._embed_minilm(texts)
        return self._embed_tfidf(texts)

    def _embed_tfidf(self, texts: list[str]) -> np.ndarray:
        from sklearn.feature_extraction.text import TfidfVectorizer

        # Fitting per batch is appropriate: each analysis run is a single topic.
        self.vectorizer = TfidfVectorizer(
            min_df=1, ngram_range=(1, 2), stop_words="english"
        )
        try:
            X = self.vectorizer.fit_transform(texts)
        except ValueError:
            # e.g. every doc is stop-words only; fall back to char n-grams
            self.vectorizer = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5))
            X = self.vectorizer.fit_transform(texts)
        return l2_normalize(X.toarray())

    def _embed_minilm(self, texts: list[str]) -> np.ndarray:
        if self._model is None:
            from sentence_transformers import SentenceTransformer

            self._model = SentenceTransformer(
                os.getenv("EMBED_MODEL", "all-MiniLM-L6-v2")
            )
        arr = self._model.encode(list(texts), normalize_embeddings=True)
        return np.asarray(arr, dtype="float32")
