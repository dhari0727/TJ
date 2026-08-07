-- =====================================================================
-- JourneyAI — Storybook Journal schema
-- =====================================================================
-- Purpose: Paginated, themeable, print-ready journal feature.
-- Run:  mysql -u root project < sql/storybook.sql
-- SAFE TO RE-RUN: uses CREATE TABLE IF NOT EXISTS / ALTER IGNORE.
-- Depends on: sql/migrations.sql (db, signup), sql/media.sql (media)
-- =====================================================================

-- ---------------------------------------------------------------------
-- storybooks — one row per journal book
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS storybooks (
    book_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    eml           VARCHAR(190) NOT NULL,
    title         VARCHAR(255) NOT NULL,
    subtitle      VARCHAR(300) NULL,
    theme         VARCHAR(40)  NOT NULL DEFAULT 'floral',
    cover_img     VARCHAR(255) NULL,
    visibility    ENUM('private','link','public') NOT NULL DEFAULT 'private',
    share_token   CHAR(22) NULL,
    page_order    ENUM('manual','chronological') NOT NULL DEFAULT 'manual',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (book_id),
    KEY idx_sb_eml (eml),
    UNIQUE KEY uq_sb_token (share_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- storybook_pages — one row per page in a book
-- photo_1..4 store file paths; decorations is a JSON array of placed
-- items [{type:'emoji',content:'🌸',x:20,y:40},{type:'sticker',...}].
-- cost_entry_id links to db.entry_id for the cost-card template.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS storybook_pages (
    page_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    book_id       INT UNSIGNED NOT NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    template      VARCHAR(40) NOT NULL DEFAULT 'story',
    title         VARCHAR(255) NULL,
    body_text     TEXT NULL,
    page_date     DATE NULL,
    photo_1       VARCHAR(255) NULL,
    photo_1_cap   VARCHAR(500) NULL,
    photo_2       VARCHAR(255) NULL,
    photo_2_cap   VARCHAR(500) NULL,
    photo_3       VARCHAR(255) NULL,
    photo_3_cap   VARCHAR(500) NULL,
    photo_4       VARCHAR(255) NULL,
    photo_4_cap   VARCHAR(500) NULL,
    decorations   JSON NULL,
    cost_entry_id BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (page_id),
    KEY idx_sbp_book (book_id, sort_order),
    CONSTRAINT fk_sbp_book FOREIGN KEY (book_id) REFERENCES storybooks(book_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- storybooks_themes — curated theme definitions (seeded by
-- sql/storybook-themes.sql). palette + fonts are JSON so the frontend
-- can apply them as CSS custom properties.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS storybooks_themes (
    theme_id      VARCHAR(40) NOT NULL,
    label         VARCHAR(80) NOT NULL,
    description   VARCHAR(300) NULL,
    palette       JSON NOT NULL,
    fonts         JSON NOT NULL,
    bg_class      VARCHAR(60) NULL,
    emoji_set     JSON NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (theme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- storybook_comments — per-page comments (public journals)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS storybook_comments (
    comment_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id       INT UNSIGNED NOT NULL,
    eml           VARCHAR(190) NOT NULL,
    body          TEXT NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY idx_sbc_page (page_id),
    CONSTRAINT fk_sbc_page FOREIGN KEY (page_id) REFERENCES storybook_pages(page_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- storybook_likes — per-book likes (public journals)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS storybook_likes (
    book_id       INT UNSIGNED NOT NULL,
    eml           VARCHAR(190) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (book_id, eml),
    KEY idx_sbl_eml (eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Extend media table: link photos to storybooks
-- ---------------------------------------------------------------------
SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = 'project' AND TABLE_NAME = 'media' AND COLUMN_NAME = 'book_id');
SET @sql = IF(@exists = 0,
    'ALTER TABLE media ADD COLUMN book_id INT UNSIGNED NULL AFTER entry_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                   WHERE TABLE_SCHEMA = 'project' AND TABLE_NAME = 'media' AND INDEX_NAME = 'idx_media_book');
SET @sql2 = IF(@idx_exists = 0,
    'ALTER TABLE media ADD KEY idx_media_book (book_id)',
    'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
