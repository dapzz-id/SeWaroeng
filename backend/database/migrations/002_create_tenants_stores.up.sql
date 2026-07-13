-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 002 - Core tables: tenants, stores
-- Multi-tenant foundation. All tenant data isolated via tenant_id UUID.

-- ─── TENANTS ──────────────────────────────────────────────────────────────────
CREATE TYPE tenant_status AS ENUM ('pending_verification', 'active', 'suspended');

CREATE TABLE tenants (
    id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(100) NOT NULL UNIQUE,
    email           VARCHAR(255) NOT NULL UNIQUE,
    phone           VARCHAR(20),
    status          tenant_status NOT NULL DEFAULT 'pending_verification',
    settings        JSONB        NOT NULL DEFAULT '{}',
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- B-Tree indexes
CREATE INDEX idx_tenants_email  ON tenants (email);
CREATE INDEX idx_tenants_slug   ON tenants (slug);
CREATE INDEX idx_tenants_status ON tenants (status);

-- ─── STORES ───────────────────────────────────────────────────────────────────
CREATE TYPE store_type AS ENUM ('warung', 'coffeeshop', 'grosir', 'minimarket');

CREATE TABLE stores (
    id              UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       UUID         NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name            VARCHAR(255) NOT NULL,
    description     TEXT,
    logo_url        VARCHAR(500),
    banner_url      VARCHAR(500),
    address         TEXT,
    city            VARCHAR(100),
    province        VARCHAR(100),
    postal_code     VARCHAR(10),
    location        GEOGRAPHY(POINT, 4326),          -- PostGIS spatial type
    operational_hours JSONB      NOT NULL DEFAULT '{}',
    is_open         BOOLEAN      NOT NULL DEFAULT TRUE,
    store_type      store_type   NOT NULL DEFAULT 'warung',
    max_capacity    INTEGER      NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- B-Tree + GIST indexes
CREATE INDEX idx_stores_tenant_id ON stores (tenant_id);
CREATE INDEX idx_stores_is_open   ON stores (is_open);
CREATE INDEX idx_stores_location  ON stores USING GIST (location);   -- Spatial index
