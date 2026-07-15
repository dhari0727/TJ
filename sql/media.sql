-- =====================================================================
-- JourneyAI — social media layer (photos/videos on journals, hashtags, feed)
-- Run:  mysql -u root project < sql/media.sql   (safe to re-run)
-- =====================================================================

CREATE TABLE IF NOT EXISTS media (
    media_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id     BIGINT UNSIGNED NULL,          -- journal entry it belongs to (nullable for standalone posts)
    eml          VARCHAR(190) NOT NULL,         -- owner
    kind         ENUM('photo','video') NOT NULL DEFAULT 'photo',
    filepath     VARCHAR(255) NOT NULL,         -- relative path under uploads/
    caption      VARCHAR(500) NULL,
    destination  VARCHAR(160) NULL,             -- canonical/place name (for place-card matching)
    lat          DECIMAL(9,6) NULL,
    lon          DECIMAL(9,6) NULL,
    is_public    TINYINT(1) NOT NULL DEFAULT 1, -- shows in the browse feed
    likes        INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (media_id),
    KEY idx_media_entry (entry_id),
    KEY idx_media_eml (eml),
    KEY idx_media_dest (destination),
    KEY idx_media_public (is_public, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- hashtags (normalized) and their link to media
CREATE TABLE IF NOT EXISTS hashtags (
    tag_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tag      VARCHAR(80) NOT NULL,
    uses     INT NOT NULL DEFAULT 0,
    PRIMARY KEY (tag_id),
    UNIQUE KEY uq_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS media_hashtags (
    media_id BIGINT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (media_id, tag_id),
    KEY idx_mh_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
