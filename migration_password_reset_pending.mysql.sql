-- Batch B2: student password recovery (temporary password flow).
-- An admin-issued temporary password sets this flag; the dashboard
-- gates on it (change-password.php) until the student picks a real
-- password. MySQL/MariaDB version - the PostgreSQL version lives in
-- migration_password_reset_pending.sql.
ALTER TABLE users
    ADD COLUMN password_reset_pending BOOLEAN NOT NULL DEFAULT 0;
