-- Minimal-privilege DBConsole admin role for PostgreSQL — NOT the superuser.
-- CREATEDB lets it create/drop databases; CREATEROLE lets it manage login
-- roles and grant on them. It is deliberately NOT a SUPERUSER.

DO
$$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'db_console_admin') THEN
        CREATE ROLE db_console_admin WITH LOGIN CREATEDB CREATEROLE PASSWORD 'admin-secret-change-me';
    END IF;
END
$$;
