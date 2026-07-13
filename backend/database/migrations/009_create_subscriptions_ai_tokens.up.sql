-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 009 - SaaS Subscriptions & AI Token Quotas
-- Manages monthly subscription billing and Gemini AI token usage tracking.

CREATE TYPE subscription_status AS ENUM ('active', 'expired', 'cancelled', 'trial');

-- ─── SUBSCRIPTIONS ───────────────────────────────────────────────────────────
CREATE TABLE subscriptions (
    id              UUID                PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       UUID                NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    status          subscription_status NOT NULL DEFAULT 'trial',
    plan_name       VARCHAR(100)        NOT NULL DEFAULT 'basic',
    price           NUMERIC(15,2)       NOT NULL DEFAULT 250000,   -- Rp 250k/bulan
    started_at      TIMESTAMPTZ         NOT NULL DEFAULT NOW(),
    expires_at      TIMESTAMPTZ         NOT NULL,
    payment_ref     VARCHAR(255),                                  -- Midtrans/Payment gateway ref
    created_at      TIMESTAMPTZ         NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ         NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_subscriptions_tenant_id ON subscriptions (tenant_id);
CREATE INDEX idx_subscriptions_status    ON subscriptions (status);
CREATE INDEX idx_subscriptions_expires   ON subscriptions (expires_at);

-- ─── AI TOKEN QUOTAS ─────────────────────────────────────────────────────────
CREATE TABLE ai_token_quotas (
    id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       UUID          NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    total_tokens    BIGINT        NOT NULL DEFAULT 1000000,        -- 1M tokens awal
    used_tokens     BIGINT        NOT NULL DEFAULT 0,
    reset_at        TIMESTAMPTZ   NOT NULL,                        -- Kapan token direset (per billing cycle)
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_ai_token_quotas_tenant ON ai_token_quotas (tenant_id);

-- ─── AI TOKEN USAGE LOG ──────────────────────────────────────────────────────
CREATE TABLE ai_token_usage_logs (
    id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       UUID          NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    tokens_used     INTEGER       NOT NULL,
    feature         VARCHAR(100)  NOT NULL DEFAULT 'ai_insights',  -- Which feature consumed it
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_token_usage_tenant   ON ai_token_usage_logs (tenant_id);
CREATE INDEX idx_ai_token_usage_created  ON ai_token_usage_logs (created_at DESC);
