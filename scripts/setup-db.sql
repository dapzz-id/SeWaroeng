-- PostgreSQL Setup Script for SeWaroeng
-- Creates non-superuser application role without superuser or bypassrls privileges.

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'sewaroeng_app') THEN
        CREATE ROLE sewaroeng_app WITH LOGIN PASSWORD 'sewaroeng_password' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    ELSE
        ALTER ROLE sewaroeng_app WITH NOSUPERUSER NOBYPASSRLS;
    END IF;
END $$;

GRANT ALL PRIVILEGES ON DATABASE sewaroeng TO sewaroeng_app;
GRANT ALL PRIVILEGES ON DATABASE sewaroeng_test TO sewaroeng_app;
ALTER SCHEMA public OWNER TO sewaroeng_app;
GRANT ALL ON SCHEMA public TO sewaroeng_app;

\c sewaroeng
CREATE EXTENSION IF NOT EXISTS btree_gist;

\c sewaroeng_test
CREATE EXTENSION IF NOT EXISTS btree_gist;
