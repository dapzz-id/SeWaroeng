-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 007 - Digital products, store couriers, POS transactions

-- ─── DIGITAL TRANSACTIONS ─────────────────────────────────────────────────────
CREATE TYPE digital_tx_status AS ENUM ('pending', 'processing', 'completed', 'failed', 'refunded');

CREATE TABLE digital_transactions (
    id              UUID              PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id        UUID              NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    order_item_id   UUID              NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT,
    provider        VARCHAR(50)       NOT NULL,              -- 'digiflazz', 'stub', etc.
    provider_ref    VARCHAR(255),                            -- Provider transaction ID
    product_code    VARCHAR(100)      NOT NULL,
    target_number   VARCHAR(50),                             -- Phone number, customer ID, etc.
    sn_encrypted    TEXT,                                    -- AES-256-GCM encrypted serial number
    status          digital_tx_status NOT NULL DEFAULT 'pending',
    retry_count     SMALLINT          NOT NULL DEFAULT 0,
    error_message   TEXT,
    fulfilled_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ       NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ       NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_digital_tx_order_id ON digital_transactions (order_id);
CREATE INDEX idx_digital_tx_status   ON digital_transactions (status);

-- ─── STORE COURIERS (Kurir Toko) ──────────────────────────────────────────────
CREATE TABLE store_couriers (
    id              UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       UUID           NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name            VARCHAR(100)   NOT NULL,
    description     TEXT,
    radius_km       NUMERIC(6,2)   NOT NULL DEFAULT 5,
    rate_per_km     NUMERIC(10,2)  NOT NULL DEFAULT 0,
    min_fee         NUMERIC(10,2)  NOT NULL DEFAULT 0,
    max_weight_gram INTEGER,
    location        GEOGRAPHY(POINT, 4326) NOT NULL,         -- Pickup/origin point
    is_active       BOOLEAN        NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ    NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_store_couriers_tenant_id ON store_couriers (tenant_id);
CREATE INDEX idx_store_couriers_active    ON store_couriers (is_active);
CREATE INDEX idx_store_couriers_location  ON store_couriers USING GIST (location);  -- GIST spatial index

-- Add FK to orders table for courier
ALTER TABLE orders ADD COLUMN courier_name VARCHAR(100);

-- ─── POS TRANSACTIONS ─────────────────────────────────────────────────────────
CREATE TYPE pos_payment_method AS ENUM ('cash', 'qris', 'debit', 'wallet');

CREATE TABLE pos_transactions (
    id          UUID               PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id   UUID               NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    staff_id    UUID               NOT NULL REFERENCES users(id)   ON DELETE RESTRICT,
    total       NUMERIC(15,2)      NOT NULL,
    amount_paid NUMERIC(15,2)      NOT NULL,
    change_due  NUMERIC(15,2)      NOT NULL DEFAULT 0,
    method      pos_payment_method NOT NULL DEFAULT 'cash',
    notes       TEXT,
    synced_at   TIMESTAMPTZ,                                       -- NULL = pending sync
    created_at  TIMESTAMPTZ        NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_pos_tx_tenant_id  ON pos_transactions (tenant_id);
CREATE INDEX idx_pos_tx_staff_id   ON pos_transactions (staff_id);
CREATE INDEX idx_pos_tx_synced_at  ON pos_transactions (synced_at) WHERE synced_at IS NULL;
CREATE INDEX idx_pos_tx_created_at ON pos_transactions (created_at DESC);

-- ─── POS TRANSACTION ITEMS ────────────────────────────────────────────────────
CREATE TABLE pos_transaction_items (
    id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    pos_tx_id       UUID          NOT NULL REFERENCES pos_transactions(id) ON DELETE CASCADE,
    product_id      UUID          NOT NULL REFERENCES products(id)          ON DELETE RESTRICT,
    product_name    VARCHAR(255)  NOT NULL,  -- Snapshot
    price           NUMERIC(15,2) NOT NULL,  -- Snapshot
    quantity        INTEGER       NOT NULL CHECK (quantity > 0),
    subtotal        NUMERIC(15,2) NOT NULL
);

CREATE INDEX idx_pos_tx_items_pos_tx_id ON pos_transaction_items (pos_tx_id);
