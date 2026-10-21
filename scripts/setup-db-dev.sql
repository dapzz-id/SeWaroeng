-- ==============================================================================
-- SeWaroeng — Local Development & CI Test Database Setup Script
-- Target: Local development (sewaroeng) and automated tests (sewaroeng_test)
-- ==============================================================================
-- DO NOT RUN ON REMOTE STAGING OR PRODUCTION SERVERS.
-- ==============================================================================

-- 1. Create or verify non-superuser application role
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'sewaroeng_app') THEN
        CREATE ROLE sewaroeng_app WITH LOGIN PASSWORD 'sewaroeng_password' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    ELSE
        ALTER ROLE sewaroeng_app WITH NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

-- 2. Local Development Database (sewaroeng)
SELECT 'CREATE DATABASE sewaroeng OWNER sewaroeng_app'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sewaroeng')\gexec

GRANT ALL PRIVILEGES ON DATABASE sewaroeng TO sewaroeng_app;

\c sewaroeng
CREATE EXTENSION IF NOT EXISTS btree_gist;
ALTER SCHEMA public OWNER TO sewaroeng_app;
GRANT ALL ON SCHEMA public TO sewaroeng_app;

-- 3. Automated Test Database (sewaroeng_test)
SELECT 'CREATE DATABASE sewaroeng_test OWNER sewaroeng_app'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sewaroeng_test')\gexec

GRANT ALL PRIVILEGES ON DATABASE sewaroeng_test TO sewaroeng_app;

\c sewaroeng_test
CREATE EXTENSION IF NOT EXISTS btree_gist;
ALTER SCHEMA public OWNER TO sewaroeng_app;
GRANT ALL ON SCHEMA public TO sewaroeng_app;
