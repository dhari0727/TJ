-- Sessions are invalidated everywhere when the password changes (see connection.php).
ALTER TABLE signup ADD COLUMN IF NOT EXISTS session_epoch INT NOT NULL DEFAULT 0;
