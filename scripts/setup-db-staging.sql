-- ==============================================================================
-- SeWaroeng — Staging PostgreSQL Database & Role Setup Script
-- Target: Staging Environment ONLY (sewaroeng_staging)
-- ==============================================================================
-- SAFETY GUARANTEES:
-- 1. Provisions ONLY the staging database (sewaroeng_staging).
-- 2. Strictly DOES NOT touch, create, grant, or modify 'sewaroeng' (dev) or
--    'sewaroeng_production' (production) or 'sewaroeng_test' (testing).
-- 3. Creates/alters 'sewaroeng_app' with strictly NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE.
--    Tenant Row-Level Security (RLS) remains strictly enforced.
-- 4. Installs the 'btree_gist' extension in 'sewaroeng_staging' prior to migrations.
-- ==============================================================================

-- 1. Create or verify non-superuser application role
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'sewaroeng_app') THEN
        -- Operator note: Match password with DB_PASSWORD in backend/.env on staging VPS
        CREATE ROLE sewaroeng_app WITH LOGIN PASSWORD 'sewaroeng_staging_password' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    ELSE
        -- Ensure privilege boundary is strictly maintained (never SUPERUSER or BYPASSRLS)
        ALTER ROLE sewaroeng_app WITH NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

-- 2. Create Staging Database owned by sewaroeng_app (idempotent)
SELECT 'CREATE DATABASE sewaroeng_staging OWNER sewaroeng_app'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sewaroeng_staging')\gexec

GRANT ALL PRIVILEGES ON DATABASE sewaroeng_staging TO sewaroeng_app;

-- 3. Connect to sewaroeng_staging to install extensions and configure schema
\c sewaroeng_staging

-- Required by exclusion constraints and overlapping shift/reservation checks
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- Ensure public schema is owned by the app role with proper access rights
ALTER SCHEMA public OWNER TO sewaroeng_app;
GRANT ALL ON SCHEMA public TO sewaroeng_app;
