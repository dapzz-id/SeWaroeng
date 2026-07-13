-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 010 - In-app notifications (global, maintenance, promo)

CREATE TABLE notifications (
    id          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    title       VARCHAR(255) NOT NULL,
    body        TEXT         NOT NULL,
    type        VARCHAR(50)  NOT NULL DEFAULT 'general',  -- general, maintenance, promo
    scope       VARCHAR(50)  NOT NULL DEFAULT 'all',      -- all, sellers, buyers
    created_by  UUID         REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_notifications_type      ON notifications (type);
CREATE INDEX idx_notifications_scope     ON notifications (scope);
CREATE INDEX idx_notifications_created   ON notifications (created_at DESC);
