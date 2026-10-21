-- ==============================================================================
-- DEPRECATED: Monolithic setup-db.sql has been decommissioned for safety.
-- ==============================================================================
-- To prevent accidental cross-environment resource creation and database modification,
-- database provisioning has been strictly isolated by environment:
--
-- For Staging:             scripts/setup-db-staging.sql
-- For Local Dev & Test:    scripts/setup-db-dev.sql
-- For Production:          scripts/setup-db-production.sql (INACTIVE)
--
-- Executing this monolithic script is intentionally blocked.
-- ==============================================================================

DO $$
BEGIN
    RAISE EXCEPTION 'SAFETY HALT: scripts/setup-db.sql is deprecated. Use scripts/setup-db-staging.sql for staging, or scripts/setup-db-dev.sql for local development.';
END $$;
