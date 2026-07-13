-- Neura Cakrawira Solusi | SeWaroeng
-- Migration: 001 - Enable PostGIS extension
-- Enables spatial data support for Kurir Toko geolocation features.

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;
