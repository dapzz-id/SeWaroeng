-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 006 - Payments, wallets, wallet transactions

-- ─── PAYMENTS ─────────────────────────────────────────────────────────────────
CREATE TYPE payment_gateway AS ENUM ('midtrans', 'paypal', 'wise', 'wallet', 'cod', 'pos');

CREATE TABLE payments (
    id              UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id        UUID            NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    gateway         payment_gateway NOT NULL,
    external_id     VARCHAR(255),                         -- Gateway's transaction ID
    status          payment_status  NOT NULL DEFAULT 'unpaid',
    amount          NUMERIC(15,2)   NOT NULL,
    currency        VARCHAR(10)     NOT NULL DEFAULT 'IDR',
    payload         JSONB           NOT NULL DEFAULT '{}', -- Full gateway response
    webhook_payload JSONB           DEFAULT '{}',
    paid_at         TIMESTAMPTZ,
    expires_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payments_order_id    ON payments (order_id);
CREATE INDEX idx_payments_external_id ON payments (external_id) WHERE external_id IS NOT NULL;
CREATE INDEX idx_payments_status      ON payments (status);
CREATE INDEX idx_payments_gateway     ON payments (gateway);

-- ─── WALLETS ──────────────────────────────────────────────────────────────────
CREATE TABLE wallets (
    id          UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID          NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    balance     NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK (balance >= 0),
    created_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_wallets_user_id ON wallets (user_id);

-- ─── WALLET TRANSACTIONS ──────────────────────────────────────────────────────
CREATE TYPE wallet_tx_type AS ENUM ('topup', 'payment', 'refund', 'transfer_in', 'transfer_out', 'cashback');

CREATE TABLE wallet_transactions (
    id              UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
    wallet_id       UUID           NOT NULL REFERENCES wallets(id) ON DELETE RESTRICT,
    type            wallet_tx_type NOT NULL,
    amount          NUMERIC(15,2)  NOT NULL,
    balance_after   NUMERIC(15,2)  NOT NULL,                  -- Snapshot for audit
    reference_id    UUID,                                      -- FK to orders/pos_transactions
    reference_type  VARCHAR(50),                               -- 'order', 'pos_transaction', 'topup'
    description     TEXT,
    created_at      TIMESTAMPTZ    NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_wallet_tx_wallet_id   ON wallet_transactions (wallet_id);
CREATE INDEX idx_wallet_tx_created_at  ON wallet_transactions (created_at DESC);
CREATE INDEX idx_wallet_tx_reference   ON wallet_transactions (reference_id) WHERE reference_id IS NOT NULL;
