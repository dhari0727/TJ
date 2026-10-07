-- =====================================================================
-- JourneyAI — production additions (safe to re-run)
-- Run:  mysql -u root project < sql/prod.sql
-- =====================================================================

-- generic rate limiting (login uses login_attempts; this covers sign-up, password reset, uploads, ...)
CREATE TABLE IF NOT EXISTS rate_limits (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket     VARCHAR(120) NOT NULL,           -- e.g. "forgot:ip:1.2.3.4"
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rl (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- indexes for the queries the app runs on every page (IF NOT EXISTS keeps this re-runnable)
CREATE INDEX IF NOT EXISTS idx_signup_created   ON signup (created_at);
CREATE INDEX IF NOT EXISTS idx_signup_role      ON signup (role, is_active);
CREATE INDEX IF NOT EXISTS idx_media_owner_pin  ON media (eml, is_pinned, media_id);
CREATE INDEX IF NOT EXISTS idx_media_public_ts  ON media (is_public, created_at);
CREATE INDEX IF NOT EXISTS idx_db_owner_pin     ON db (eml, is_pinned, entry_id);
CREATE INDEX IF NOT EXISTS idx_storybooks_owner ON storybooks (eml, is_pinned, updated_at);
CREATE INDEX IF NOT EXISTS idx_email_log_to     ON email_log (to_addr, created_at);
CREATE INDEX IF NOT EXISTS idx_jl_user          ON journal_likes (eml);
CREATE INDEX IF NOT EXISTS idx_mc_user          ON media_comments (eml, created_at);
CREATE INDEX IF NOT EXISTS idx_jc_user          ON journal_comments (eml, created_at);
