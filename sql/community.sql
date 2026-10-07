-- =====================================================================
-- JourneyAI — community: share journals + post media (safe to re-run)
-- Run:  mysql -u root project < sql/community.sql
-- =====================================================================
-- journal entries can be shared: private (default) | link (anyone with the link) | public (Read journals)
ALTER TABLE db ADD COLUMN IF NOT EXISTS visibility ENUM('private','link','public') NOT NULL DEFAULT 'private';
ALTER TABLE db ADD COLUMN IF NOT EXISTS share_token CHAR(22) NULL;
ALTER TABLE db ADD COLUMN IF NOT EXISTS published_at DATETIME NULL;
CREATE INDEX IF NOT EXISTS idx_db_visibility ON db (visibility, published_at);

-- comments on media posts (feed)
CREATE TABLE IF NOT EXISTS media_comments (
    comment_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    media_id    BIGINT UNSIGNED NOT NULL,
    eml         VARCHAR(190) NOT NULL,
    body        VARCHAR(600) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY idx_mc_media (media_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
