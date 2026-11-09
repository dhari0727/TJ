-- =====================================================================
-- JourneyAI — trust, verification, moderation, reports, audit (safe to re-run)
-- Run:  mysql -u root project < sql/trust.sql
-- =====================================================================

-- accounts: e-mail verification + manual trust flag
ALTER TABLE signup ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE signup ADD COLUMN IF NOT EXISTS verify_token CHAR(64) NULL;
ALTER TABLE signup ADD COLUMN IF NOT EXISTS verify_sent_at DATETIME NULL;
ALTER TABLE signup ADD COLUMN IF NOT EXISTS trust_override ENUM('none','trusted','restricted') NOT NULL DEFAULT 'none';

-- everything that can become public carries a moderation state
-- approved = visible; pending = waiting for a moderator; rejected = refused; hidden = pulled after reports
ALTER TABLE db         ADD COLUMN IF NOT EXISTS moderation_status ENUM('approved','pending','rejected','hidden') NOT NULL DEFAULT 'approved';
ALTER TABLE db         ADD COLUMN IF NOT EXISTS mod_reason VARCHAR(255) NULL;
ALTER TABLE db         ADD COLUMN IF NOT EXISTS text_hash CHAR(32) NULL;
ALTER TABLE media      ADD COLUMN IF NOT EXISTS moderation_status ENUM('approved','pending','rejected','hidden') NOT NULL DEFAULT 'approved';
ALTER TABLE media      ADD COLUMN IF NOT EXISTS mod_reason VARCHAR(255) NULL;
ALTER TABLE media      ADD COLUMN IF NOT EXISTS sha256 CHAR(64) NULL;
ALTER TABLE media      ADD COLUMN IF NOT EXISTS bytes INT UNSIGNED NULL;
ALTER TABLE storybooks ADD COLUMN IF NOT EXISTS moderation_status ENUM('approved','pending','rejected','hidden') NOT NULL DEFAULT 'approved';
ALTER TABLE storybooks ADD COLUMN IF NOT EXISTS mod_reason VARCHAR(255) NULL;

CREATE INDEX IF NOT EXISTS idx_db_mod      ON db (moderation_status, visibility);
CREATE INDEX IF NOT EXISTS idx_media_mod   ON media (moderation_status, is_public);
CREATE INDEX IF NOT EXISTS idx_media_hash  ON media (sha256);
CREATE INDEX IF NOT EXISTS idx_db_hash     ON db (text_hash);
CREATE INDEX IF NOT EXISTS idx_sb_mod      ON storybooks (moderation_status, visibility);

-- community reports (one report per person per item)
CREATE TABLE IF NOT EXISTS reports (
    report_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporter    VARCHAR(190) NOT NULL,
    kind        ENUM('media','journal','book','comment') NOT NULL,
    item_id     BIGINT UNSIGNED NOT NULL,
    reason      ENUM('fake','spam','unsafe','copyright','other') NOT NULL DEFAULT 'other',
    detail      VARCHAR(300) NULL,
    status      ENUM('open','upheld','dismissed') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id),
    UNIQUE KEY uq_report (reporter, kind, item_id),
    KEY idx_rep_item (kind, item_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- who did what (logins, moderation, deletions, verification)
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor       VARCHAR(190) NULL,
    action      VARCHAR(60) NOT NULL,
    detail      VARCHAR(400) NULL,
    ip          VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_time (created_at),
    KEY idx_audit_actor (actor, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- accounts that cannot log in (seed/real-sourced/demo) count as verified so their content stays visible
UPDATE signup SET email_verified = 1
 WHERE eml LIKE '%@seed.journeyai' OR eml LIKE '%@real.journeyai' OR eml LIKE '%@demo.journeyai' OR role = 'admin';
