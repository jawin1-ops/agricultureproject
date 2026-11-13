-- ============================================================
--  Agriculture IS – change_requests table setup
--  Run this once in your Supabase SQL editor.
-- ============================================================

-- Ensure the system_user and user_role_lookup tables exist
-- (adjust column names to match your actual schema if different)

-- ── change_requests ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS change_requests (
    request_id    SERIAL PRIMARY KEY,
    action_type   VARCHAR(10)  NOT NULL CHECK (action_type IN ('UPDATE','DELETE')),
    target_table  VARCHAR(64)  NOT NULL,
    record_id     INTEGER      NOT NULL,
    proposed_data JSONB,                          -- NULL is fine for DELETE
    status        VARCHAR(12)  NOT NULL DEFAULT 'PENDING'
                  CHECK (status IN ('PENDING','APPROVED','REJECTED')),
    requested_by  INTEGER REFERENCES system_user(user_id) ON DELETE SET NULL,
    requested_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    reviewed_by   INTEGER REFERENCES system_user(user_id) ON DELETE SET NULL,
    reviewed_at   TIMESTAMPTZ
);

-- Index for the approval queue query (WHERE status = 'PENDING')
CREATE INDEX IF NOT EXISTS idx_change_requests_status
    ON change_requests (status);

-- ── system_user: add password_hash column if missing ────────
-- (skip if your table already has a password column – rename as needed)
ALTER TABLE system_user
    ADD COLUMN IF NOT EXISTS password_hash TEXT NOT NULL DEFAULT '';

-- ── user_role_lookup: minimal expected shape ─────────────────
-- CREATE TABLE IF NOT EXISTS user_role_lookup (
--     role_id   INTEGER PRIMARY KEY,
--     role_name VARCHAR(64) NOT NULL
-- );
-- INSERT INTO user_role_lookup (role_id, role_name) VALUES
--     (1, 'Administrator'),
--     (2, 'Staff')
-- ON CONFLICT DO NOTHING;
