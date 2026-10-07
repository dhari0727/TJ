-- =====================================================================
-- JourneyAI — least-privilege database user for production.
-- Replace CHANGE_ME with a long random password, run as a MySQL admin, then give the app the
-- credentials via environment variables or config/app.local.php (see docs/DEPLOYMENT.md).
--   mysql -u root -p < sql/create-db-user.sql
-- The app needs data access only: it never creates or drops tables at runtime.
-- =====================================================================
CREATE USER IF NOT EXISTS 'journeyai'@'localhost' IDENTIFIED BY 'CHANGE_ME';
GRANT SELECT, INSERT, UPDATE, DELETE ON project.* TO 'journeyai'@'localhost';
-- the ML service reads the same database (and writes journal_features during a rebuild)
FLUSH PRIVILEGES;
