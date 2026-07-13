-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 008 - Table: bookings
-- Used for seat reservation in coffeeshops/restos

CREATE TYPE booking_status AS ENUM ('pending', 'confirmed', 'cancelled', 'completed');

CREATE TABLE bookings (
    id              UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
    store_id        UUID            NOT NULL REFERENCES stores(id) ON DELETE CASCADE,
    user_id         UUID            NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    booking_time    TIMESTAMPTZ     NOT NULL,
    pax             INTEGER         NOT NULL DEFAULT 1,
    status          booking_status  NOT NULL DEFAULT 'pending',
    notes           TEXT,
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

-- Indexes for fast querying (Crowd level checks etc)
CREATE INDEX idx_bookings_store_id ON bookings (store_id);
CREATE INDEX idx_bookings_status   ON bookings (status);
CREATE INDEX idx_bookings_time     ON bookings (booking_time);
