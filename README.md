# Enigma

A narrative intelligence platform for public-discourse research. Enter a topic and
Enigma reconstructs, from public data, how the conversation around it formed and
spread, and surfaces statistical signals of coordinated amplification versus organic
discussion. Findings are reported as **scored likelihoods with evidence and baselines,
never as verdicts and never about named individuals.**

This is a non-commercial research project. Case study #1 is the public discourse
around the *Supergirl* (2026) film release.

## How it uses the Reddit API

- **Read only.** It fetches the public comment trees of specific submissions and,
  occasionally, subreddit search to discover relevant threads. It never posts, votes,
  comments, or messages.
- **Official Data API, app-only OAuth** (`client_credentials`). No scraping.
- **Rate limited** well under the free-tier limit (throttled to ~90 requests/min via
  a queued, globally rate-limited job) and responses are cached to minimize calls.
- **Aggregate analysis only.** Collected comments feed offline statistical text
  analysis (topic clustering, near-duplicate detection) and posting-cadence /
  network coordination scoring. No output identifies or targets individual accounts.

## Responsible use

- Aggregate patterns only. No dossiers on named individuals, no "this account is a
  bot" verdicts.
- Public data via official APIs only.
- Coordination reported as scored likelihood with visible evidence and a comparison
  baseline, distinguishing coordinated-*authentic* behavior (an organic fandom) from
  coordinated-*inauthentic* behavior.
- Storage kept minimal; a deletion/retention path is planned before any public output.

## Architecture

```
Reddit thread ──(Laravel: rate-limited queue job)──▶ posts / authors  (Postgres)
                                                          │
                                    AnalyzeTopic job ─────┤
                                                          ▼
                                   Python NLP  /analyze  ─┴─▶  narratives
                                     topic clustering        duplicate groups
                                     coordination scoring     coordination clusters
                                                              (score + signals +
                                                               baseline + evidence)
```

- **Ingestion & orchestration:** Laravel 13 (queued jobs, rate limiting) + PostgreSQL.
- **Analysis:** a small Python FastAPI service (`python-nlp/`) for embeddings,
  clustering, repetition detection, and coordination scoring.

## API

```
POST /api/topics                 {label, query_terms[]}                 -> topic
POST /api/topics/{id}/collect    {permalinks:[ "/r/.../comments/..." ]}
POST /api/topics/{id}/analyze    {params?}   -> runs the NLP pipeline, persists
GET  /api/topics/{id}            -> timeline + narratives + coordination clusters
```

## Status

| Layer | State |
|-------|-------|
| DB schema | done |
| Python NLP service | built + smoke-tested |
| Laravel ingestion (Reddit) | scaffolded |
| Frontend dashboard | in progress |
