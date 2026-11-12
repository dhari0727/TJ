-- =====================================================================
-- JourneyAI - connected social accounts and post history (safe to re-run)
-- Run:  mysql -u root project < sql/social.sql
-- Tokens are stored AES-256-GCM encrypted (key in private/social.key), never in plain text.
-- =====================================================================
CREATE TABLE IF NOT EXISTS social_accounts (
  account_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
  eml          VARCHAR(190) NOT NULL,
  provider     VARCHAR(20)  NOT NULL,
  remote_id    VARCHAR(190) NOT NULL,
  handle       VARCHAR(190) NOT NULL DEFAULT '',
  display_name VARCHAR(190) NOT NULL DEFAULT '',
  token_enc    TEXT NULL,
  refresh_enc  TEXT NULL,
  expires_at   DATETIME NULL,
  scopes       VARCHAR(255) NOT NULL DEFAULT '',
  meta         TEXT NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'active',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_social_account (eml, provider, remote_id),
  KEY idx_social_owner (eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS social_posts (
  post_id    BIGINT AUTO_INCREMENT PRIMARY KEY,
  eml        VARCHAR(190) NOT NULL,
  account_id BIGINT NULL,
  provider   VARCHAR(20)  NOT NULL,
  kind       VARCHAR(20)  NOT NULL,
  item_id    VARCHAR(190) NOT NULL DEFAULT '',
  remote_id  VARCHAR(255) NOT NULL DEFAULT '',
  remote_url VARCHAR(500) NOT NULL DEFAULT '',
  status     ENUM('queued','posted','failed') NOT NULL DEFAULT 'queued',
  error      VARCHAR(255) NOT NULL DEFAULT '',
  req_key    VARCHAR(40)  NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_social_posts_owner (eml, created_at),
  UNIQUE KEY uq_social_req (account_id, req_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
