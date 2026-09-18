-- rider.co.ke — PostgreSQL schema (Neon, used on Vercel).
-- Mirrors schema.sql (MySQL/MariaDB, used for local development) exactly in
-- shape; only dialect differs. Differences from the MySQL version:
--   * AUTO_INCREMENT -> BIGSERIAL, UNSIGNED dropped (no unsigned ints in PG)
--   * ENUM(...) -> VARCHAR + CHECK constraint
--   * ON UPDATE CURRENT_TIMESTAMP dropped (every UPDATE in src/Controllers
--     already sets updated_at = NOW() explicitly, so no trigger is needed)
--   * inline INDEX/UNIQUE KEY -> separate CREATE INDEX / named CONSTRAINT
--   * ENGINE=InnoDB dropped (not a Postgres concept)
-- See planning/00-portfolio/ui-implementation-plan.md for why this file
-- exists (Vercel's Marketplace has no MySQL-compatible option; Neon
-- Postgres was the user's choice for the live Vercel deployment).

-- ============================================================
-- SHARED CORE TABLES — identical shape across all five platforms
-- ============================================================

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    national_id_number VARCHAR(20) NULL,
    account_type VARCHAR(20) NOT NULL CHECK (account_type IN ('customer', 'provider', 'admin')),
    status VARCHAR(30) NOT NULL DEFAULT 'pending_verification' CHECK (status IN ('active', 'suspended', 'banned', 'pending_verification')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE roles (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE permissions (
    id BIGSERIAL PRIMARY KEY,
    "key" VARCHAR(150) NOT NULL UNIQUE
);

CREATE TABLE role_permissions (
    role_id BIGINT NOT NULL REFERENCES roles(id),
    permission_id BIGINT NOT NULL REFERENCES permissions(id),
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    user_id BIGINT NOT NULL REFERENCES users(id),
    role_id BIGINT NOT NULL REFERENCES roles(id),
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE payments (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id),
    booking_id BIGINT NULL,
    order_id BIGINT NULL,
    type VARCHAR(20) NOT NULL CHECK (type IN ('charge', 'payout', 'refund', 'commission')),
    method VARCHAR(20) NOT NULL CHECK (method IN ('mpesa_stk', 'mpesa_c2b', 'mpesa_b2c', 'card')),
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    external_reference VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'failed', 'reversed')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_payments_external_reference ON payments(external_reference);

CREATE TABLE payment_callbacks_log (
    id BIGSERIAL PRIMARY KEY,
    checkout_request_id VARCHAR(100) NOT NULL UNIQUE,
    raw_payload JSON NOT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE escrow_transactions (
    id BIGSERIAL PRIMARY KEY,
    payment_id BIGINT NOT NULL REFERENCES payments(id),
    booking_id BIGINT NOT NULL,
    held_amount DECIMAL(12,2) NOT NULL,
    retention_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    release_condition VARCHAR(30) NOT NULL CHECK (release_condition IN ('auto_timeout', 'customer_confirmation', 'admin_release', 'dispute_resolution')),
    release_at TIMESTAMP NULL,
    released_at TIMESTAMP NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'held' CHECK (status IN ('held', 'released', 'partially_released', 'refunded'))
);

CREATE TABLE commission_rules (
    id BIGSERIAL PRIMARY KEY,
    platform VARCHAR(20) NOT NULL DEFAULT 'rider' CHECK (platform IN ('laundry', 'rider', 'construction', 'solar', 'event')),
    category VARCHAR(100) NOT NULL,
    commission_type VARCHAR(20) NOT NULL CHECK (commission_type IN ('percentage', 'flat_fee', 'tiered')),
    value DECIMAL(10,2) NOT NULL,
    min_transaction_value DECIMAL(12,2) NULL,
    max_transaction_value DECIMAL(12,2) NULL,
    effective_from TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    effective_to TIMESTAMP NULL
);

-- Default standard-tier commission for completed trips: prd.md proposes 15-25% and
-- flags it as needing stakeholder input; 18% is the assumed midpoint (MVP_STATUS.md).
INSERT INTO commission_rules (platform, category, commission_type, value, effective_from)
VALUES ('rider', 'rider_trip', 'percentage', 18.00, CURRENT_TIMESTAMP);

CREATE TABLE kyc_documents (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id),
    document_type VARCHAR(30) NOT NULL CHECK (document_type IN ('national_id', 'kra_pin', 'business_registration', 'insurance_certificate', 'professional_certification', 'proof_of_address', 'driving_license', 'vehicle_logbook')),
    file_reference VARCHAR(500) NOT NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (verification_status IN ('pending', 'verified', 'rejected', 'expired')),
    verified_by BIGINT NULL REFERENCES users(id),
    verified_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL
);

CREATE TABLE reviews (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL,
    reviewer_id BIGINT NOT NULL REFERENCES users(id),
    reviewee_id BIGINT NOT NULL REFERENCES users(id),
    rating SMALLINT NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- category values for this platform: safety_incident, fare_dispute, route_deviation, lost_item, other
CREATE TABLE disputes (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL,
    raised_by BIGINT NOT NULL REFERENCES users(id),
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    evidence_urls JSON NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'under_review', 'resolved_refund', 'resolved_partial', 'resolved_no_action', 'escalated')),
    resolved_by BIGINT NULL REFERENCES users(id),
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL
);

CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    actor_id BIGINT NULL REFERENCES users(id),
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT NOT NULL,
    before_state JSON NULL,
    after_state JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PLATFORM-SPECIFIC TABLES — rider.co.ke
-- ============================================================

CREATE TABLE rider_trips (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    rider_id BIGINT NULL REFERENCES users(id),
    trip_type VARCHAR(30) NOT NULL CHECK (trip_type IN ('passenger_motorcycle', 'passenger_car', 'parcel_delivery')),
    status VARCHAR(20) NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'matched', 'rider_en_route', 'in_progress', 'completed', 'cancelled', 'disputed')),
    pickup_lat DECIMAL(10,7) NOT NULL,
    pickup_lng DECIMAL(10,7) NOT NULL,
    pickup_address VARCHAR(500) NULL,
    destination_lat DECIMAL(10,7) NOT NULL,
    destination_lng DECIMAL(10,7) NOT NULL,
    destination_address VARCHAR(500) NULL,
    recipient_phone_number VARCHAR(20) NULL,
    estimated_distance_km DECIMAL(6,2) NOT NULL DEFAULT 0,
    estimated_duration_min INT NOT NULL DEFAULT 0,
    surge_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.0,
    estimated_fare DECIMAL(10,2) NOT NULL,
    final_fare DECIMAL(10,2) NULL,
    payment_id BIGINT NULL REFERENCES payments(id),
    requested_at TIMESTAMP NULL,
    matched_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    proof_of_delivery_url VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE trip_pings (
    id BIGSERIAL PRIMARY KEY,
    trip_id BIGINT NOT NULL REFERENCES rider_trips(id),
    rider_id BIGINT NOT NULL REFERENCES users(id),
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    speed_kmh DECIMAL(6,2) NULL,
    heading_degrees DECIMAL(5,2) NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_trip_pings_trip ON trip_pings(trip_id, recorded_at);

-- Tracks the offer-cascade dispatch flow referenced in
-- planning/02-rider-co-ke/api-endpoints.md's accept/decline endpoints and
-- open-questions.md #2 (30s offer window, cascading to next-nearest rider
-- on decline/timeout). Not in the original database-schema.md — added as
-- the implementation detail needed to make that cascade logic real.
CREATE TABLE trip_dispatch_offers (
    id BIGSERIAL PRIMARY KEY,
    trip_id BIGINT NOT NULL REFERENCES rider_trips(id),
    rider_id BIGINT NOT NULL REFERENCES users(id),
    status VARCHAR(20) NOT NULL DEFAULT 'offered' CHECK (status IN ('offered', 'accepted', 'declined', 'expired', 'superseded')),
    distance_km DECIMAL(6,2) NULL,
    offered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    expires_at TIMESTAMP NOT NULL
);
CREATE INDEX idx_dispatch_offers_trip ON trip_dispatch_offers(trip_id);
CREATE INDEX idx_dispatch_offers_rider_status ON trip_dispatch_offers(rider_id, status);

CREATE TABLE rider_vehicle_documents (
    id BIGSERIAL PRIMARY KEY,
    rider_id BIGINT NOT NULL REFERENCES users(id),
    vehicle_type VARCHAR(20) NOT NULL CHECK (vehicle_type IN ('motorcycle', 'car', 'tuk_tuk')),
    plate_number VARCHAR(20) NOT NULL,
    driving_license_kyc_document_id BIGINT NOT NULL REFERENCES kyc_documents(id),
    insurance_kyc_document_id BIGINT NOT NULL REFERENCES kyc_documents(id),
    logbook_kyc_document_id BIGINT NULL REFERENCES kyc_documents(id),
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (verification_status IN ('pending', 'verified', 'rejected')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rider_availability (
    rider_id BIGINT PRIMARY KEY REFERENCES users(id),
    is_online BOOLEAN NOT NULL DEFAULT FALSE,
    current_lat DECIMAL(10,7) NULL,
    current_lng DECIMAL(10,7) NULL,
    last_ping_at TIMESTAMP NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rider_subscriptions (
    id BIGSERIAL PRIMARY KEY,
    rider_id BIGINT NOT NULL REFERENCES users(id),
    plan_type VARCHAR(20) NOT NULL CHECK (plan_type IN ('weekly', 'monthly')),
    discounted_commission_rate DECIMAL(5,2) NOT NULL,
    subscription_fee DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'expired', 'cancelled')),
    current_period_start DATE NOT NULL,
    current_period_end DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE loyalty_points_ledger (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    trip_id BIGINT NULL REFERENCES rider_trips(id),
    points_change INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rider_performance_tiers (
    rider_id BIGINT PRIMARY KEY REFERENCES users(id),
    average_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    completion_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    tier VARCHAR(20) NOT NULL DEFAULT 'standard' CHECK (tier IN ('standard', 'priority', 'elite')),
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE saved_places (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    label VARCHAR(100) NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL
);

CREATE TABLE preferred_riders (
    customer_id BIGINT NOT NULL REFERENCES users(id),
    rider_id BIGINT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, rider_id)
);

ALTER TABLE disputes ADD CONSTRAINT fk_disputes_booking FOREIGN KEY (booking_id) REFERENCES rider_trips(id);
ALTER TABLE reviews ADD CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES rider_trips(id);
ALTER TABLE escrow_transactions ADD CONSTRAINT fk_escrow_booking FOREIGN KEY (booking_id) REFERENCES rider_trips(id);

-- ============================================================
-- E-COMMERCE STORE — shared shape across all five platforms
-- ============================================================

CREATE TABLE store_products (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT NULL REFERENCES users(id),
    category VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    image_urls JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'out_of_stock', 'inactive')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE store_orders (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    payment_id BIGINT NULL REFERENCES payments(id),
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'fulfilled', 'cancelled')),
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_address VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE store_order_items (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES store_orders(id),
    product_id BIGINT NOT NULL REFERENCES store_products(id),
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL
);

ALTER TABLE payments ADD CONSTRAINT fk_payments_store_order FOREIGN KEY (order_id) REFERENCES store_orders(id);
