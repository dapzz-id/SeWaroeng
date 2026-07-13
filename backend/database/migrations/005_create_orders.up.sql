-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 005 - Orders, order items, order documents

-- ─── ORDERS ───────────────────────────────────────────────────────────────────
CREATE TYPE order_status   AS ENUM (
    'pending_payment', 'payment_confirmed', 'processing',
    'shipped', 'delivered', 'completed', 'cancelled', 'refunded'
);
CREATE TYPE payment_method AS ENUM (
    'midtrans_snap', 'midtrans_qris', 'midtrans_va',
    'paypal', 'wise', 'wallet', 'cod', 'pos_cash', 'pos_qris'
);
CREATE TYPE payment_status AS ENUM ('unpaid', 'paid', 'failed', 'refunded', 'expired');

CREATE TABLE orders (
    id                  UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id           UUID           NOT NULL REFERENCES tenants(id)       ON DELETE RESTRICT,
    customer_id         UUID           NOT NULL REFERENCES users(id)         ON DELETE RESTRICT,
    shipping_address_id UUID           REFERENCES user_addresses(id)         ON DELETE SET NULL,
    courier_id          UUID,                                                 -- FK to store_couriers (added later)
    order_number        VARCHAR(50)    NOT NULL UNIQUE,                       -- Human-readable: SW-20260713-XXXX
    status              order_status   NOT NULL DEFAULT 'pending_payment',
    payment_method      payment_method,
    payment_status      payment_status NOT NULL DEFAULT 'unpaid',
    subtotal            NUMERIC(15,2)  NOT NULL DEFAULT 0,
    shipping_cost       NUMERIC(15,2)  NOT NULL DEFAULT 0,
    discount_amount     NUMERIC(15,2)  NOT NULL DEFAULT 0,
    total               NUMERIC(15,2)  NOT NULL DEFAULT 0,
    notes               TEXT,
    tracking_number     VARCHAR(100),
    shipped_at          TIMESTAMPTZ,
    delivered_at        TIMESTAMPTZ,
    completed_at        TIMESTAMPTZ,
    cancelled_at        TIMESTAMPTZ,
    cancellation_reason TEXT,
    created_at          TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ    NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_orders_tenant_id    ON orders (tenant_id);
CREATE INDEX idx_orders_customer_id  ON orders (customer_id);
CREATE INDEX idx_orders_status       ON orders (status);
CREATE INDEX idx_orders_payment_status ON orders (payment_status);
CREATE INDEX idx_orders_created_at   ON orders (created_at DESC);

-- ─── ORDER ITEMS ──────────────────────────────────────────────────────────────
CREATE TABLE order_items (
    id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id        UUID          NOT NULL REFERENCES orders(id)   ON DELETE CASCADE,
    product_id      UUID          NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    product_name    VARCHAR(255)  NOT NULL,                          -- Snapshot at order time
    product_image   VARCHAR(500),
    price           NUMERIC(15,2) NOT NULL,                          -- Snapshot at order time
    quantity        INTEGER       NOT NULL CHECK (quantity > 0),
    subtotal        NUMERIC(15,2) NOT NULL,
    meta            JSONB         NOT NULL DEFAULT '{}',
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_order_items_order_id   ON order_items (order_id);
CREATE INDEX idx_order_items_product_id ON order_items (product_id);

-- ─── ORDER DOCUMENTS ──────────────────────────────────────────────────────────
CREATE TYPE document_type AS ENUM ('invoice', 'receipt', 'digital_proof', 'shipping_label');

CREATE TABLE order_documents (
    id          UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id    UUID          NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    doc_type    document_type NOT NULL,
    file_url    VARCHAR(500)  NOT NULL,
    file_size   INTEGER,
    created_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_order_documents_order_id ON order_documents (order_id);
