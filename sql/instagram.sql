-- =====================================================================
-- JourneyAI - short-lived public links for images Instagram has to fetch (safe to re-run)
-- Run:  mysql -u root project < sql/instagram.sql
-- Only images a member explicitly queued by confirming an Instagram post are ever put here.
-- The token in the URL is random (256 bits) and only its SHA-256 is stored. Rows and files expire after 15 minutes.
-- =====================================================================
CREATE TABLE IF NOT EXISTS ig_media_queue (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64)     NOT NULL,
  eml        VARCHAR(190) NOT NULL,
  post_id    BIGINT       NULL,
  path       VARCHAR(190) NOT NULL,
  bytes      INT          NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME     NOT NULL,
  hits       INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ig_token (token_hash),
  KEY idx_ig_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
