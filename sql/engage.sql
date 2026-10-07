-- =====================================================================
-- JourneyAI — packing lists, pinning, public traveller handles (safe to re-run)
-- Run:  mysql -u root project < sql/engage.sql
-- =====================================================================

-- standalone packing lists (optionally tied to a saved plan)
CREATE TABLE IF NOT EXISTS packing_lists (
    list_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    eml          VARCHAR(190) NOT NULL,
    plan_id      INT UNSIGNED NULL,
    name         VARCHAR(120) NOT NULL,
    destination  VARCHAR(160) NULL,
    days         SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    month        TINYINT UNSIGNED NULL,
    style        VARCHAR(20) NOT NULL DEFAULT 'mid-range',
    party        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    options      VARCHAR(255) NULL,            -- comma separated: kids,elderly,photography,...
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id),
    KEY idx_pl_eml (eml, created_at),
    UNIQUE KEY uq_pl_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS packing_list_items (
    item_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id      INT UNSIGNED NOT NULL,
    category     VARCHAR(40) NOT NULL DEFAULT 'general',
    label        VARCHAR(160) NOT NULL,
    qty          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    why          VARCHAR(200) NULL,            -- explanation for suggested items
    source       ENUM('suggested','custom') NOT NULL DEFAULT 'suggested',
    is_checked   TINYINT(1) NOT NULL DEFAULT 0,
    sort_order   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (item_id),
    KEY idx_pli_list (list_id, sort_order),
    CONSTRAINT fk_pli_list FOREIGN KEY (list_id) REFERENCES packing_lists(list_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- pinning (max 3 per kind per user, enforced in code)
ALTER TABLE media      ADD COLUMN IF NOT EXISTS is_pinned TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE storybooks ADD COLUMN IF NOT EXISTS is_pinned TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE db         ADD COLUMN IF NOT EXISTS is_pinned TINYINT(1) NOT NULL DEFAULT 0;

-- public traveller handle for profile URLs (never exposes the email)
ALTER TABLE signup ADD COLUMN IF NOT EXISTS handle VARCHAR(40) NULL;
ALTER TABLE signup ADD COLUMN IF NOT EXISTS bio VARCHAR(240) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_signup_handle ON signup (handle);

-- likes + comments on journals (storybooks and posts already have them)
CREATE TABLE IF NOT EXISTS journal_likes (
    entry_id   BIGINT UNSIGNED NOT NULL,
    eml        VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entry_id, eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS journal_comments (
    comment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id   BIGINT UNSIGNED NOT NULL,
    eml        VARCHAR(190) NOT NULL,
    body       VARCHAR(600) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY idx_jc_entry (entry_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
