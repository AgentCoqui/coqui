-- Coqui Agent Protocol (CAP) — reference SQLite schema (protocol_version 0.5.0)
-- NORMATIVE reference binding. Logical types → SQLite: text=TEXT, int=INTEGER,
-- bool=INTEGER(0/1), timestamp=TEXT(RFC-3339 UTC), json=TEXT(schema-validated).
-- Foreign keys MUST be enabled per connection.
PRAGMA foreign_keys = ON;

-- ── meta: schema marker + instance metadata ──────────────────────────────
CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
-- Required: INSERT INTO meta(key,value) VALUES ('schema_version','0.5.0');

-- ── personas ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS personas (
    id            TEXT PRIMARY KEY,
    name          TEXT NOT NULL UNIQUE,          -- roster names are distinct; bundles key on name
    avatar        TEXT NOT NULL,              -- json {tint, image_ref?}
    model         TEXT NOT NULL,
    allowed_roles TEXT NOT NULL,              -- json array; MUST include "orchestrator"
    soul          TEXT NOT NULL,
    backstory     TEXT,
    context       TEXT,                        -- json array | null
    preferences   TEXT,                        -- json | null
    version       INTEGER NOT NULL DEFAULT 1,  -- optimistic-concurrency token; increments on each update
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

-- ── sessions ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
    id          TEXT PRIMARY KEY,
    persona_id  TEXT NOT NULL,
    title       TEXT,
    kind        TEXT NOT NULL DEFAULT 'chat',  -- chat | loop_workscope
    status      TEXT NOT NULL DEFAULT 'active',-- active | archived | closed
    pinned      INTEGER NOT NULL DEFAULT 0,
    model       TEXT,                          -- nullable ⇒ inherit per Personas §5 precedence
    workspace   TEXT,                          -- opaque host-defined execution locator; null ⇒ none (Foundation §4.4)
    token_count INTEGER NOT NULL DEFAULT 0,
    version     INTEGER NOT NULL DEFAULT 1,    -- optimistic-concurrency token; increments on each update
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS session_members (
    session_id TEXT NOT NULL,
    persona_id TEXT NOT NULL,
    PRIMARY KEY (session_id, persona_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE RESTRICT
);

-- ── turns ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS turns (
    id                TEXT PRIMARY KEY,
    session_id        TEXT NOT NULL,
    actor_persona_id  TEXT,
    turn_number       INTEGER NOT NULL,
    user_prompt       TEXT NOT NULL,
    response_text     TEXT,
    model             TEXT,
    prompt_tokens     INTEGER NOT NULL DEFAULT 0,
    completion_tokens INTEGER NOT NULL DEFAULT 0,
    total_tokens      INTEGER NOT NULL DEFAULT 0,
    iterations        INTEGER NOT NULL DEFAULT 0,
    duration_ms       INTEGER NOT NULL DEFAULT 0,
    tools_used        TEXT,                     -- json array | null
    status            TEXT NOT NULL DEFAULT 'running',
    created_at        TEXT NOT NULL,
    completed_at      TEXT,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
);

-- ── messages ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
    id           TEXT PRIMARY KEY,
    session_id   TEXT NOT NULL,
    turn_id      TEXT,
    role         TEXT NOT NULL,                 -- user | assistant | tool | system
    content      TEXT NOT NULL,
    tool_calls   TEXT,                          -- json | null
    tool_call_id TEXT,
    actor_name   TEXT,
    actor_role   TEXT,
    created_at   TEXT NOT NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (turn_id)    REFERENCES turns(id)    ON DELETE SET NULL
);

-- ── roles (definition table) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS roles (
    name           TEXT PRIMARY KEY,
    access_level   TEXT NOT NULL,               -- full | readonly-shell | readonly | minimal
    model          TEXT,
    toolkits       TEXT,                         -- json array | null
    max_iterations INTEGER,
    gate           INTEGER NOT NULL DEFAULT 0,
    instructions   TEXT,
    version        INTEGER NOT NULL DEFAULT 1   -- optimistic-concurrency token; increments on each update
);

-- ── loop_definitions (definition table) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS loop_definitions (
    name                  TEXT PRIMARY KEY,      -- slug ^[a-z0-9][a-z0-9_-]*$
    description           TEXT NOT NULL DEFAULT '',
    roles                 TEXT NOT NULL,          -- json ordered array
    termination_condition TEXT NOT NULL,          -- json {type,value}
    parameters            TEXT,                    -- json | null
    max_rework_attempts   INTEGER NOT NULL DEFAULT 3,
    builtin               INTEGER NOT NULL DEFAULT 0,
    version               INTEGER NOT NULL DEFAULT 1,  -- optimistic-concurrency token; increments on each update
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL
);

-- ── loops ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS loops (
    id                   TEXT PRIMARY KEY,
    definition_name      TEXT NOT NULL,           -- logical ref (not enforced FK)
    persona_id           TEXT NOT NULL,
    session_id           TEXT,
    goal                 TEXT NOT NULL,
    status               TEXT NOT NULL DEFAULT 'running',
    current_iteration    INTEGER NOT NULL DEFAULT 0,
    current_stage        INTEGER NOT NULL DEFAULT 0,
    max_iterations       INTEGER,
    deadline             TEXT,
    termination_criteria TEXT,                    -- json | null
    configuration        TEXT,                    -- json | null
    origin               TEXT NOT NULL DEFAULT 'conversation',
    created_at           TEXT NOT NULL,
    completed_at         TEXT,
    last_activity_at     TEXT,
    rework_attempts      INTEGER NOT NULL DEFAULT 0,      -- circuit-breaker counter (Loops.md §5)
    dispatch_state       TEXT NOT NULL DEFAULT 'pending', -- 'pending' | 'dispatched' (Loops.md §6)
    last_dispatch_error  TEXT,                            -- last dispatch error, null when healthy (Loops.md §6)
    metadata             TEXT,                    -- json | null
    FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE RESTRICT,
    -- SET NULL is the terminal-loop fallback only. Deleting a session referenced by a
    -- NON-terminal loop (running|paused|blocked) is cascade-stopped at the application layer:
    -- the loop is first transitioned to 'cancelled', then the session is deleted. SQLite cannot
    -- express "SET NULL only when the loop is terminal" declaratively (Data.md §3, I11).
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS loop_iterations (
    id               TEXT PRIMARY KEY,
    loop_id          TEXT NOT NULL,
    iteration_number INTEGER NOT NULL,
    status           TEXT NOT NULL DEFAULT 'pending',
    outcome_summary  TEXT,
    started_at       TEXT,
    completed_at     TEXT,
    FOREIGN KEY (loop_id) REFERENCES loops(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS loop_stages (
    id             TEXT PRIMARY KEY,
    iteration_id   TEXT NOT NULL,
    stage_index    INTEGER NOT NULL,
    role           TEXT NOT NULL,                 -- logical ref (not enforced FK)
    job_id         TEXT,
    artifact_id    TEXT,
    status         TEXT NOT NULL DEFAULT 'pending',
    verdict        TEXT,                          -- json | null
    result_summary TEXT,
    started_at     TEXT,
    completed_at   TEXT,
    FOREIGN KEY (iteration_id) REFERENCES loop_iterations(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id)       REFERENCES jobs(id)            ON DELETE SET NULL
);

-- ── memories ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS memories (
    id          TEXT PRIMARY KEY,
    persona_id  TEXT,                             -- null = shared/untagged
    name        TEXT NOT NULL,
    description TEXT,
    content     TEXT NOT NULL,
    type        TEXT NOT NULL,                    -- user | feedback | project | reference
    metadata    TEXT,                             -- json | null
    version     INTEGER NOT NULL DEFAULT 1,       -- optimistic-concurrency token; increments on each update
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE RESTRICT
);

-- ── child_runs ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS child_runs (
    id                 TEXT PRIMARY KEY,
    parent_session_id  TEXT NOT NULL,
    parent_turn_id     TEXT,
    role               TEXT NOT NULL,
    model              TEXT,                          -- null = inherit (Personas §5)
    prompt             TEXT NOT NULL,
    result             TEXT,
    status             TEXT NOT NULL,                  -- pending|running|completed|failed|cancelled
    prompt_tokens      INTEGER NOT NULL DEFAULT 0,
    completion_tokens  INTEGER NOT NULL DEFAULT 0,
    total_tokens       INTEGER NOT NULL DEFAULT 0,
    created_at         TEXT NOT NULL,
    completed_at       TEXT,
    FOREIGN KEY (parent_session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_turn_id)    REFERENCES turns(id) ON DELETE SET NULL
);

-- ── internal: jobs / job_events / audit_records ──────────────────────────
CREATE TABLE IF NOT EXISTS jobs (
    id                TEXT PRIMARY KEY,
    session_id        TEXT NOT NULL,
    parent_session_id TEXT,
    status            TEXT NOT NULL DEFAULT 'pending',
    title             TEXT,
    prompt            TEXT NOT NULL,
    role              TEXT NOT NULL DEFAULT 'orchestrator',
    metadata          TEXT,                        -- json | null
    result            TEXT,
    error             TEXT,
    max_iterations    INTEGER NOT NULL DEFAULT 25,
    created_at        TEXT NOT NULL,
    started_at        TEXT,
    completed_at      TEXT,
    cancelled_at      TEXT,
    FOREIGN KEY (session_id)        REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_session_id) REFERENCES sessions(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS job_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id     TEXT NOT NULL,
    event_type TEXT NOT NULL,
    data       TEXT NOT NULL DEFAULT '{}',        -- json
    created_at TEXT NOT NULL,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_records (
    id         TEXT PRIMARY KEY,
    session_id TEXT,
    tool_name  TEXT NOT NULL,
    arguments  TEXT NOT NULL,                     -- json
    action     TEXT NOT NULL,
    reason     TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
);

-- ── required indexes ─────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_sessions_persona    ON sessions(persona_id);
CREATE INDEX IF NOT EXISTS idx_sessions_status     ON sessions(status);
CREATE INDEX IF NOT EXISTS idx_session_members_p   ON session_members(persona_id);
CREATE INDEX IF NOT EXISTS idx_turns_session       ON turns(session_id);
CREATE INDEX IF NOT EXISTS idx_messages_session    ON messages(session_id);
CREATE INDEX IF NOT EXISTS idx_messages_turn       ON messages(turn_id);
CREATE INDEX IF NOT EXISTS idx_loops_status        ON loops(status);
CREATE INDEX IF NOT EXISTS idx_loops_persona       ON loops(persona_id);
CREATE INDEX IF NOT EXISTS idx_loop_iters_loop     ON loop_iterations(loop_id);
CREATE INDEX IF NOT EXISTS idx_loop_stages_iter    ON loop_stages(iteration_id);
CREATE INDEX IF NOT EXISTS idx_memories_persona    ON memories(persona_id);
CREATE INDEX IF NOT EXISTS idx_memories_type       ON memories(type);
-- name unique per scope; null persona collapses to the single shared scope (Memory.md §3)
CREATE UNIQUE INDEX IF NOT EXISTS idx_memories_name_scope ON memories(COALESCE(persona_id, ''), name);
CREATE INDEX IF NOT EXISTS idx_jobs_session        ON jobs(session_id);
CREATE INDEX IF NOT EXISTS idx_jobs_status         ON jobs(status);
CREATE INDEX IF NOT EXISTS idx_job_events_job      ON job_events(job_id);
CREATE INDEX IF NOT EXISTS idx_audit_session       ON audit_records(session_id);
CREATE INDEX IF NOT EXISTS idx_child_runs_parent   ON child_runs(parent_session_id);

-- ── profile-gated (additive; create only when the profile is implemented) ─
-- profile: skills
--   CREATE TABLE skills (name TEXT PRIMARY KEY, description TEXT, metadata TEXT,
--       source TEXT, status TEXT NOT NULL, origin TEXT, execution TEXT,
--       created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
-- profile: artifacts
--   CREATE TABLE artifacts (id TEXT PRIMARY KEY, session_id TEXT NOT NULL, name TEXT,
--       type TEXT, content_ref TEXT, metadata TEXT, created_at TEXT NOT NULL,
--       FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE);
-- profile: questions
--   CREATE TABLE questions (id TEXT PRIMARY KEY, session_id TEXT NOT NULL, prompt TEXT NOT NULL,
--       options TEXT, status TEXT NOT NULL, answer TEXT, suggested TEXT,
--       created_at TEXT NOT NULL, answered_at TEXT,
--       FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE);
-- profile: schedules
--   CREATE TABLE scheduled_tasks (id TEXT PRIMARY KEY, name TEXT NOT NULL, cron TEXT NOT NULL,
--     persona_id TEXT NOT NULL REFERENCES personas(id) ON DELETE RESTRICT, action TEXT NOT NULL,
--     status TEXT NOT NULL, last_run_at TEXT, next_run_at TEXT, created_at TEXT NOT NULL, updated_at TEXT);
