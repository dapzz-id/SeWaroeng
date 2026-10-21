-- ==============================================================================
-- SeWaroeng — Production PostgreSQL Database & Role Setup Template
-- Target: Production Environment (sewaroeng_production)
-- ==============================================================================
-- STATUS: INACTIVE TEMPLATE — DO NOT EXECUTE IN STAGING OR LOCAL DEVELOPMENT
-- ==============================================================================
-- Operational Safety Rules:
-- 1. This script is intended strictly for manual, authorized DBA execution during
--    the scheduled production provisioning window.
-- 2. It must NEVER be invoked by staging automation, CI/CD, or deployment scripts.
-- 3. Production credentials must be generated with high entropy and stored in a
--    secure secrets vault (e.g. HashiCorp Vault, AWS Secrets Manager, GitHub Secrets).
-- 4. Application runtime role MUST retain NOSUPERUSER NOBYPASSRLS to preserve tenant RLS boundaries.
-- ==============================================================================

-- ==============================================================================
-- PHASE 1: CLOUD PROVIDER / MANAGED HOSTING PROVISIONING (OUTSIDE THIS SCRIPT)
-- ==============================================================================
-- On managed cloud services (Neon, Supabase, AWS RDS, DigitalOcean Managed DB):
--   - CREATE DATABASE is typically handled in the provider web console or dashboard.
--   - Superuser credentials (e.g. 'postgres' or 'doadmin') are provided via provider connection string.
--   - If your provider restricts CREATE DATABASE via SQL, create database 'sewaroeng_production'
--     via your cloud control panel first, then connect directly to it.
-- ==============================================================================

-- ==============================================================================
-- PHASE 2: DATABASE & ROLE CREATION (SELF-HOSTED / VPS / PRIVILEGED DBA)
-- ==============================================================================

-- 1. Create Dedicated Roles
-- Option A (Dual-Role Model - RECOMMENDED FOR ENTERPRISE ISOLATION):
--   - sewaroeng_prod_migrator: Schema & table owner, executes 'php artisan migrate' (DDL).
--   - sewaroeng_prod_app: Unprivileged runtime role, executes web app & workers (DML only).
DO $$
BEGIN
    -- 1A. Schema Migrator Role (DDL permissions)
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'sewaroeng_prod_migrator') THEN
        CREATE ROLE sewaroeng_prod_migrator WITH LOGIN PASSWORD 'REPLACE_WITH_STRONG_MIGRATOR_SECRET'
            NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    ELSE
        ALTER ROLE sewaroeng_prod_migrator WITH NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;

    -- 1B. Application Runtime Role (DML only, strict least-privilege)
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'sewaroeng_prod_app') THEN
        CREATE ROLE sewaroeng_prod_app WITH LOGIN PASSWORD 'REPLACE_WITH_STRONG_RUNTIME_SECRET'
            NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    ELSE
        ALTER ROLE sewaroeng_prod_app WITH NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

-- 2. Create Production Database owned by sewaroeng_prod_migrator (idempotent, self-hosted only)
SELECT 'CREATE DATABASE sewaroeng_production OWNER sewaroeng_prod_migrator'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sewaroeng_production')\gexec

GRANT ALL PRIVILEGES ON DATABASE sewaroeng_production TO sewaroeng_prod_migrator;
GRANT CONNECT ON DATABASE sewaroeng_production TO sewaroeng_prod_app;

-- ==============================================================================
-- PHASE 3: EXTENSIONS & PRIVILEGE MODEL CONFIGURATION (RUN INSIDE sewaroeng_production)
-- ==============================================================================
\c sewaroeng_production

-- 3. Install Required Extensions (Must be run as database admin/superuser)
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- 4. Configure Schema Ownership (Owned by Migrator role)
ALTER SCHEMA public OWNER TO sewaroeng_prod_migrator;
GRANT ALL ON SCHEMA public TO sewaroeng_prod_migrator;
GRANT USAGE ON SCHEMA public TO sewaroeng_prod_app;

-- 5. Default Privileges for Runtime Application Role:
-- Any tables created by the migrator will automatically grant DML to the runtime role
ALTER DEFAULT PRIVILEGES FOR ROLE sewaroeng_prod_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO sewaroeng_prod_app;

ALTER DEFAULT PRIVILEGES FOR ROLE sewaroeng_prod_migrator IN SCHEMA public
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO sewaroeng_prod_app;

-- Grant existing tables and sequences (if any exist)
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO sewaroeng_prod_app;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO sewaroeng_prod_app;

-- Revoke dangerous DDL permissions from the runtime role
REVOKE CREATE ON SCHEMA public FROM sewaroeng_prod_app;

-- 6. Explicit Privileges for System Infrastructure Tables (Database Cache & Queue)
-- These tables are used by Laravel database cache, distributed locks, queue, and sessions.
-- Runtime role (sewaroeng_prod_app) requires SELECT, INSERT, UPDATE, DELETE to operate.
DO $$
BEGIN
    -- Grant on cache & locks if already present
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'cache') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.cache TO sewaroeng_prod_app;
    END IF;
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'cache_locks') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.cache_locks TO sewaroeng_prod_app;
    END IF;
    -- Grant on queue & jobs if already present
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'jobs') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.jobs TO sewaroeng_prod_app;
    END IF;
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'job_batches') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.job_batches TO sewaroeng_prod_app;
    END IF;
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'failed_jobs') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.failed_jobs TO sewaroeng_prod_app;
    END IF;
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'sessions') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.sessions TO sewaroeng_prod_app;
    END IF;
END $$;

-- ==============================================================================
-- PHASE 4: VERIFICATION AUDIT QUERIES
-- ==============================================================================
-- 4.1 Verify both roles have NO SUPERUSER and NO BYPASS RLS:
SELECT rolname, rolsuper, rolbypassrls, rolcanlogin
FROM pg_roles
WHERE rolname IN ('sewaroeng_prod_migrator', 'sewaroeng_prod_app');
-- Expected output:
--         rolname         | rolsuper | rolbypassrls | rolcanlogin
-- ------------------------+----------+--------------+-------------
--  sewaroeng_prod_migrator| f        | f            | t
--  sewaroeng_prod_app     | f        | f            | t

-- 4.2 Verify btree_gist extension is installed:
SELECT extname, extversion FROM pg_extension WHERE extname = 'btree_gist';

-- 4.3 Post-Migration Audit: Verify table ownership & runtime DML privileges
-- (Execute this after 'php artisan migrate' has run to verify default privileges took effect)
SELECT 
    table_name,
    table_type,
    has_table_privilege('sewaroeng_prod_app', 'public.' || table_name, 'SELECT') AS can_select,
    has_table_privilege('sewaroeng_prod_app', 'public.' || table_name, 'INSERT') AS can_insert,
    has_table_privilege('sewaroeng_prod_app', 'public.' || table_name, 'UPDATE') AS can_update,
    has_table_privilege('sewaroeng_prod_app', 'public.' || table_name, 'DELETE') AS can_delete
FROM information_schema.tables
WHERE table_schema = 'public' AND table_name IN ('cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'sessions', 'tenants', 'outlets', 'users')
ORDER BY table_name;

-- 4.4 Verify Row-Level Security (RLS) status on tenant tables:
SELECT tablename, rowsecurity, forcerowsecurity 
FROM pg_tables 
WHERE schemaname = 'public' AND tablename IN ('tenants', 'outlets', 'users', 'whatsapp_messages', 'orders')
ORDER BY tablename;

-- ==============================================================================
-- ARCHITECTURAL NOTE ON ROW-LEVEL SECURITY (RLS) & SYSTEM TABLES:
-- 1. All SeWaroeng business tables execute:
--      ALTER TABLE <table_name> ENABLE ROW LEVEL SECURITY;
--      ALTER TABLE <table_name> FORCE ROW LEVEL SECURITY;
-- 2. Because FORCE ROW LEVEL SECURITY is enabled on every tenant table, even the
--    table owner (sewaroeng_prod_migrator) is subject to RLS policies.
-- 3. Because sewaroeng_prod_app has rolbypassrls = false and rolsuper = false,
--    it is strictly constrained by tenant boundary checks via 'app.current_tenant_id'.
-- 4. Infrastructure tables (cache, cache_locks, jobs, job_batches, failed_jobs, sessions)
--    are global application services and do not have RLS enabled.
--    Tenant boundary isolation for cache is guaranteed by tenant-scoped cache keys
--    (e.g., 'idempotency:record:{tenant_id}:{key}', 'tenant_subscription_entitlements:{tenant_id}').
-- 5. Execution Order Lifecycle:
--    Step A: DBA runs setup-db-production.sql (creates DB, roles, extensions, default privileges).
--    Step B: deploy-production.sh executes 'php artisan migrate' as sewaroeng_prod_migrator.
--    Step C: Tables created in Step B automatically inherit DML grants for sewaroeng_prod_app.
--    Step D: deploy-production.sh caches configuration for sewaroeng_prod_app runtime.
-- ==============================================================================
