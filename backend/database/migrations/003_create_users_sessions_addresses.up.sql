-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 003 - Users, Sessions, User Addresses

-- ─── USERS ────────────────────────────────────────────────────────────────────
CREATE TYPE user_role AS ENUM ('owner', 'staff', 'customer');

CREATE TABLE users (
    id              UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       UUID         REFERENCES tenants(id) ON DELETE SET NULL,  -- NULL for customers
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(255) NOT NULL,
    phone           VARCHAR(20),
    avatar_url      VARCHAR(500),
    role            user_role    NOT NULL DEFAULT 'customer',
    email_verified_at TIMESTAMPTZ,
    deleted_at      TIMESTAMPTZ,                          -- Soft delete
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- B-Tree indexes for frequent queries
CREATE INDEX idx_users_tenant_id  ON users (tenant_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_users_email      ON users (email)     WHERE deleted_at IS NULL;
CREATE INDEX idx_users_role       ON users (role)      WHERE deleted_at IS NULL;

-- ─── SESSIONS ────────────────────────────────────────────────────────────────
-- Tracks refresh tokens in DB (Session Token itself is in Redis)
CREATE TABLE sessions (
    id                  UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id             UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    refresh_token_hash  VARCHAR(255) NOT NULL UNIQUE,  -- SHA256 of refresh token
    device_info         JSONB        NOT NULL DEFAULT '{}',
    ip_address          INET,
    user_agent          TEXT,
    expires_at          TIMESTAMPTZ  NOT NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_sessions_user_id   ON sessions (user_id);
CREATE INDEX idx_sessions_expires_at ON sessions (expires_at);

-- ─── USER ADDRESSES ───────────────────────────────────────────────────────────
CREATE TABLE user_addresses (
    id              UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label           VARCHAR(50)  NOT NULL DEFAULT 'Rumah',  -- e.g. Rumah, Kantor
    recipient_name  VARCHAR(255) NOT NULL,
    phone           VARCHAR(20),
    address         TEXT         NOT NULL,
    city            VARCHAR(100) NOT NULL,
    province        VARCHAR(100) NOT NULL,
    postal_code     VARCHAR(10)  NOT NULL,
    location        GEOGRAPHY(POINT, 4326),               -- For PostGIS shipping calc
    is_default      BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_user_addresses_user_id  ON user_addresses (user_id);
CREATE INDEX idx_user_addresses_location ON user_addresses USING GIST (location);
