-- Enigma — Narrative Intelligence Platform
-- Postgres schema (Supabase-compatible). Requires the pgvector extension.
--
-- Design notes:
--   * Raw payloads are stored immutably (posts.raw) so analysis can be re-run
--     without re-paying for / re-fetching data.
--   * "posts" is the unified unit across platforms (a Reddit comment, an X post,
--     an IG comment all normalize into a row here).
--   * Embeddings live on the post row (pgvector) for similarity search.
--   * Coordination is an OUTPUT: edges + scored clusters, always re-derivable.

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;      -- fast fuzzy/near-dup text search

-- ---------------------------------------------------------------------------
-- Topics: a saved investigation ("Supergirl", "James Gunn", a phrase, etc.)
-- ---------------------------------------------------------------------------
CREATE TABLE topics (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug         TEXT UNIQUE NOT NULL,
    label        TEXT NOT NULL,
    query_terms  JSONB NOT NULL DEFAULT '[]',   -- keywords/phrases to match
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Sources: one row per platform connector configuration
-- ---------------------------------------------------------------------------
CREATE TABLE sources (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    platform    TEXT NOT NULL,        -- 'reddit' | 'x' | 'instagram'
    label       TEXT,                 -- e.g. subreddit name
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Authors: an account as seen on a platform. Deliberately minimal PII.
-- ---------------------------------------------------------------------------
CREATE TABLE authors (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    platform           TEXT NOT NULL,
    platform_author_id TEXT NOT NULL,          -- stable id from the platform
    handle             TEXT,
    account_created_at TIMESTAMPTZ,            -- for creation-date clustering
    followers          INTEGER,
    following           INTEGER,
    total_posts        INTEGER,
    profile_location   TEXT,                   -- self-claimed only
    inferred_timezone  TEXT,                   -- inferred, never from IP
    meta               JSONB NOT NULL DEFAULT '{}',
    first_seen_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (platform, platform_author_id)
);

-- ---------------------------------------------------------------------------
-- Posts: unified content unit across platforms
-- ---------------------------------------------------------------------------
CREATE TABLE posts (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id         BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    source_id        BIGINT REFERENCES sources(id) ON DELETE SET NULL,
    author_id        BIGINT REFERENCES authors(id) ON DELETE SET NULL,
    platform         TEXT NOT NULL,
    platform_post_id TEXT NOT NULL,
    parent_post_id   BIGINT REFERENCES posts(id) ON DELETE SET NULL,  -- thread structure
    url              TEXT,
    body             TEXT NOT NULL,
    body_normalized  TEXT,                     -- lowercased/stripped for dup detection
    lang             TEXT,
    score            INTEGER,                  -- upvotes/likes
    posted_at        TIMESTAMPTZ NOT NULL,
    embedding        vector(384),              -- default MiniLM dim; adjust to model
    raw              JSONB NOT NULL DEFAULT '{}',  -- immutable original payload
    ingested_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (platform, platform_post_id)
);

CREATE INDEX posts_topic_time_idx   ON posts (topic_id, posted_at);
CREATE INDEX posts_author_idx       ON posts (author_id);
CREATE INDEX posts_body_trgm_idx    ON posts USING gin (body_normalized gin_trgm_ops);
-- Approx nearest-neighbour for semantic similarity (tune lists to corpus size)
CREATE INDEX posts_embedding_idx    ON posts USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);

-- ---------------------------------------------------------------------------
-- Narratives: clusters of posts sharing a talking point (BERTopic output)
-- ---------------------------------------------------------------------------
CREATE TABLE narratives (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id       BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    label          TEXT,                       -- e.g. "looks woke"
    keywords       JSONB NOT NULL DEFAULT '[]',
    size           INTEGER NOT NULL DEFAULT 0,
    started_at     TIMESTAMPTZ,
    peaked_at      TIMESTAMPTZ,
    centroid       vector(384),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE narrative_posts (
    narrative_id  BIGINT REFERENCES narratives(id) ON DELETE CASCADE,
    post_id       BIGINT REFERENCES posts(id) ON DELETE CASCADE,
    similarity    REAL,                        -- distance to centroid
    is_representative BOOLEAN NOT NULL DEFAULT false,
    PRIMARY KEY (narrative_id, post_id)
);

-- ---------------------------------------------------------------------------
-- Repetition: link a post to the duplicate/near-dup group it belongs to
-- ---------------------------------------------------------------------------
CREATE TABLE dup_groups (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id    BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    kind        TEXT NOT NULL,                 -- 'exact' | 'near' | 'template'
    canonical   TEXT,                          -- representative / template text
    size        INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE post_dup_group (
    post_id     BIGINT REFERENCES posts(id) ON DELETE CASCADE,
    group_id    BIGINT REFERENCES dup_groups(id) ON DELETE CASCADE,
    similarity  REAL,
    PRIMARY KEY (post_id, group_id)
);

-- ---------------------------------------------------------------------------
-- Edges: the author co-action graph (co-post / co-similar / co-reply)
-- ---------------------------------------------------------------------------
CREATE TABLE edges (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id     BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    author_a     BIGINT REFERENCES authors(id) ON DELETE CASCADE,
    author_b     BIGINT REFERENCES authors(id) ON DELETE CASCADE,
    edge_type    TEXT NOT NULL,                -- 'co_post' | 'co_similar' | 'co_reply'
    weight       REAL NOT NULL DEFAULT 0,      -- # of qualifying co-actions
    window_secs  INTEGER,                      -- temporal window used
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX edges_topic_idx ON edges (topic_id, edge_type);

-- ---------------------------------------------------------------------------
-- Coordination: scored clusters/communities detected on the edge graph.
-- Always stored WITH evidence + baseline so the UI can show receipts.
-- ---------------------------------------------------------------------------
CREATE TABLE coordination_clusters (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id       BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    -- JSONB (not BIGINT[]) because Eloquent's 'array' cast writes JSON; these
    -- are re-derivable outputs consumed as JSON by the API, never joined on.
    author_ids     JSONB NOT NULL DEFAULT '[]',
    score          REAL NOT NULL,              -- 0..1 composite
    signals        JSONB NOT NULL DEFAULT '{}',-- {synchrony, similarity, account_age, cadence, overlap}
    baseline       JSONB NOT NULL DEFAULT '{}',-- expected value from matched random sample
    evidence_post_ids JSONB NOT NULL DEFAULT '[]', -- the actual synchronized posts
    label          TEXT,                       -- 'strong' | 'moderate' | 'weak'
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Generated outputs: report/campaign-pack artifacts (evidence-first)
-- ---------------------------------------------------------------------------
CREATE TABLE reports (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    topic_id     BIGINT REFERENCES topics(id) ON DELETE CASCADE,
    format       TEXT NOT NULL,                -- 'exec_summary' | 'x_thread' | 'ig_carousel' | ...
    content      JSONB NOT NULL,               -- generated text + attached receipts
    variant      TEXT,                         -- A/B label
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
