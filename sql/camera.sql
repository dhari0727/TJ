-- =====================================================================
-- JourneyAI - private camera roll (safe to re-run)
-- Run:  mysql -u root project < sql/camera.sql
-- Photos live on disk under private/camera/<hash>/ (deny-all) and are served only by camera-img.php.
-- =====================================================================
CREATE TABLE IF NOT EXISTS camera_folders (
  folder_id  BIGINT AUTO_INCREMENT PRIMARY KEY,
  eml        VARCHAR(190) NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  created_at DATETIME     NOT NULL,
  UNIQUE KEY uq_camera_folder (eml, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS camera_photos (
  photo_id    BIGINT AUTO_INCREMENT PRIMARY KEY,
  eml         VARCHAR(190) NOT NULL,
  folder_id   BIGINT NULL,
  filepath    VARCHAR(255) NOT NULL,
  thumbpath   VARCHAR(255) NOT NULL,
  caption     VARCHAR(200) NOT NULL DEFAULT '',
  place       VARCHAR(120) NOT NULL DEFAULT '',
  frame       VARCHAR(30)  NOT NULL DEFAULT 'none',
  filter_name VARCHAR(30)  NOT NULL DEFAULT 'original',
  taken_at    DATETIME     NOT NULL,
  bytes       INT NOT NULL DEFAULT 0,
  INDEX idx_camera_owner (eml, folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
