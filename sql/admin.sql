-- =====================================================================
-- JourneyAI — admin panel, per-user settings, mail log, login throttling
-- Run:  mysql -u root project < sql/admin.sql        (safe to re-run)
-- =====================================================================

-- users: role + suspend flag + timestamps
ALTER TABLE signup ADD COLUMN IF NOT EXISTS role ENUM('user','admin') NOT NULL DEFAULT 'user';
ALTER TABLE signup ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE signup ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE signup ADD COLUMN IF NOT EXISTS last_login DATETIME NULL;

-- site-wide settings editable from the admin panel (secrets are stored encrypted, is_secret=1)
CREATE TABLE IF NOT EXISTS settings (
    skey        VARCHAR(80)  NOT NULL,
    svalue      TEXT NULL,
    is_secret   TINYINT(1) NOT NULL DEFAULT 0,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- every email the app tries to send (so admins can see what happened)
CREATE TABLE IF NOT EXISTS email_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_addr     VARCHAR(190) NOT NULL,
    subject     VARCHAR(255) NOT NULL,
    status      ENUM('sent','failed','not_configured') NOT NULL,
    error       VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_el_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- failed-login throttling (by email + ip)
CREATE TABLE IF NOT EXISTS login_attempts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    eml         VARCHAR(190) NOT NULL,
    ip          VARCHAR(45) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la (eml, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- per-user preferences: prefill Plan a Trip + personalise Home
CREATE TABLE IF NOT EXISTS user_prefs (
    eml           VARCHAR(190) NOT NULL,
    home_city     VARCHAR(120) NULL,
    default_budget INT NULL,
    travel_style  VARCHAR(20) NULL,
    party_size    TINYINT UNSIGNED NULL,
    interests     VARCHAR(255) NULL,          -- comma separated
    notify_email  TINYINT(1) NOT NULL DEFAULT 1,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- defaults (INSERT IGNORE so re-running never overwrites what an admin set)
INSERT IGNORE INTO settings (skey, svalue, is_secret) VALUES
 ('site_name', 'JourneyAI', 0),
 ('allow_registration', '1', 0),
 ('smtp_enabled', '0', 0),
 ('smtp_host', 'smtp.gmail.com', 0),
 ('smtp_port', '587', 0),
 ('smtp_encryption', 'tls', 0),
 ('smtp_user', '', 0),
 ('smtp_pass', '', 1),
 ('smtp_from_email', '', 0),
 ('smtp_from_name', 'JourneyAI', 0),
 ('dev_show_reset_link', '0', 0),
 ('ml_service_url', 'http://127.0.0.1:5000', 0);
