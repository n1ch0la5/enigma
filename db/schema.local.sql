-- Enigma — LOCAL schema (no pgvector).
--
-- Use this against a vanilla Postgres (e.g. DBngin) for local dev. It's identical
-- to schema.sql minus the vector(384) columns + ivfflat index, which need the
-- pgvector extension. Those columns are unused until you wire embedding
-- persistence, so nothing in the current codebase depends on them.
--
-- When you're ready for semantic similarity search in Postgres, switch to
-- schema.sql on a pgvector-enabled server (the docker-compose db image has it).

CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- ships with standard Postgres contrib

CREATE TABLE topics (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug         TEXT UNIQUE NOT NULL,
    label        TEXT NOT NULL,
    query_terms  JSONB NOT NULL DEFAULT '[]',
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE sources (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    platform    TEXT NOT NULL,
    label       TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE authors (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    platform           TEXT NOT NULL,
    platform_author_id TEXT NOT NULL,
    handle             TEXT,
    account_created_at TIMESTAMPTZ,
    followers          INTEGER,
    following          INTEGER,
    total_posts        INTEGER,
    profile_location   TEXT,
    inferred_timezone  TEXT,
    meta               JSONB NOT NULL DEFAULT '{}',
    first_seen_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (platform, platform_author_id)
);

CREATE TABLE posts (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id         BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    source_id        BIGINT REFERENCES sources(id) ON DELETE SET NULL,
    author_id        BIGINT REFERENCES authors(id) ON DELETE SET NULL,
    platform         TEXT NOT NULL,
    platform_post_id TEXT NOT NULL,
    parent_post_id   BIGINT REFERENCES posts(id) ON DELETE SET NULL,
    url              TEXT,
    body             TEXT NOT NULL,
    body_normalized  TEXT,
    lang             TEXT,
    score            INTEGER,
    posted_at        TIMESTAMPTZ NOT NULL,
    raw              JSONB NOT NULL DEFAULT '{}',
    ingested_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (platform, platform_post_id)
);

CREATE INDEX posts_topic_time_idx ON posts (topic_id, posted_at);
CREATE INDEX posts_author_idx     ON posts (author_id);
CREATE INDEX posts_body_trgm_idx  ON posts USING gin (body_normalized gin_trgm_ops);

CREATE TABLE narratives (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id       BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    label          TEXT,
    keywords       JSONB NOT NULL DEFAULT '[]',
    size           INTEGER NOT NULL DEFAULT 0,
    started_at     TIMESTAMPTZ,
    peaked_at      TIMESTAMPTZ,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE narrative_posts (
    narrative_id      BIGINT REFERENCES narratives(id) ON DELETE CASCADE,
    post_id           BIGINT REFERENCES posts(id) ON DELETE CASCADE,
    similarity        REAL,
    is_representative BOOLEAN NOT NULL DEFAULT false,
    PRIMARY KEY (narrative_id, post_id)
);

CREATE TABLE dup_groups (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id    BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    kind        TEXT NOT NULL,
    canonical   TEXT,
    size        INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE post_dup_group (
    post_id     BIGINT REFERENCES posts(id) ON DELETE CASCADE,
    group_id    BIGINT REFERENCES dup_groups(id) ON DELETE CASCADE,
    similarity  REAL,
    PRIMARY KEY (post_id, group_id)
);

CREATE TABLE edges (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id     BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    author_a     BIGINT REFERENCES authors(id) ON DELETE CASCADE,
    author_b     BIGINT REFERENCES authors(id) ON DELETE CASCADE,
    edge_type    TEXT NOT NULL,
    weight       REAL NOT NULL DEFAULT 0,
    window_secs  INTEGER,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX edges_topic_idx ON edges (topic_id, edge_type);

CREATE TABLE coordination_clusters (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id          BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    author_ids        BIGINT[] NOT NULL,
    score             REAL NOT NULL,
    signals           JSONB NOT NULL DEFAULT '{}',
    baseline          JSONB NOT NULL DEFAULT '{}',
    evidence_post_ids BIGINT[] NOT NULL DEFAULT '{}',
    label             TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE reports (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id     BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    format       TEXT NOT NULL,
    content      JSONB NOT NULL,
    variant      TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
