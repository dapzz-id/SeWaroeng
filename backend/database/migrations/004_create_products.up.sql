-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 004 - Product categories and products

-- ─── PRODUCT CATEGORIES ───────────────────────────────────────────────────────
CREATE TABLE product_categories (
    id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id   UUID         NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    parent_id   UUID         REFERENCES product_categories(id) ON DELETE SET NULL,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(100) NOT NULL,
    icon_url    VARCHAR(500),
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, slug)
);

CREATE INDEX idx_product_categories_tenant_id ON product_categories (tenant_id);
CREATE INDEX idx_product_categories_parent_id ON product_categories (parent_id);

-- ─── PRODUCTS ─────────────────────────────────────────────────────────────────
CREATE TYPE product_type   AS ENUM ('physical', 'digital');
CREATE TYPE product_status AS ENUM ('active', 'inactive', 'archived');

CREATE TABLE products (
    id              UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       UUID           NOT NULL REFERENCES tenants(id)           ON DELETE CASCADE,
    category_id     UUID           REFERENCES product_categories(id)         ON DELETE SET NULL,
    name            VARCHAR(255)   NOT NULL,
    slug            VARCHAR(255)   NOT NULL,
    description     TEXT,
    price           NUMERIC(15,2)  NOT NULL CHECK (price >= 0),
    compare_price   NUMERIC(15,2)  CHECK (compare_price >= 0),
    stock           INTEGER        NOT NULL DEFAULT 0 CHECK (stock >= 0),
    weight_gram     INTEGER        NOT NULL DEFAULT 0,                        -- For shipping calc
    product_type    product_type   NOT NULL DEFAULT 'physical',
    status          product_status NOT NULL DEFAULT 'active',
    images          JSONB          NOT NULL DEFAULT '[]',                     -- Array of image URLs
    meta            JSONB          NOT NULL DEFAULT '{}',                     -- Flexible product attributes
    created_at      TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, slug)
);

-- B-Tree indexes (heavily queried columns)
CREATE INDEX idx_products_tenant_id   ON products (tenant_id);
CREATE INDEX idx_products_category_id ON products (category_id);
CREATE INDEX idx_products_status      ON products (status);
CREATE INDEX idx_products_type        ON products (product_type);
CREATE INDEX idx_products_price       ON products (price);
-- Full-text search index
CREATE INDEX idx_products_name_fts ON products USING GIN (to_tsvector('indonesian', name));
