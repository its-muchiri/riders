-- rider.co.ke — database schema
-- Shared core tables (planning/00-portfolio/shared-database-schema.md) +
-- platform-specific extension tables (planning/02-rider-co-ke/database-schema.md).
-- MySQL/MariaDB dialect, per shared-architecture.md's stack assumption.

-- ============================================================
-- SHARED CORE TABLES — identical shape across all five platforms
-- ============================================================

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    national_id_number VARCHAR(20) NULL,
    account_type ENUM('customer', 'provider', 'admin') NOT NULL,
    status ENUM('active', 'suspended', 'banned', 'pending_verification') NOT NULL DEFAULT 'pending_verification',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    type ENUM('charge', 'payout', 'refund', 'commission') NOT NULL,
    method ENUM('mpesa_stk', 'mpesa_c2b', 'mpesa_b2c', 'card') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    external_reference VARCHAR(100) NULL,
    status ENUM('pending', 'completed', 'failed', 'reversed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_payments_external_reference (external_reference)
) ENGINE=InnoDB;

CREATE TABLE payment_callbacks_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checkout_request_id VARCHAR(100) NOT NULL UNIQUE,
    raw_payload JSON NOT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE escrow_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NOT NULL,
    held_amount DECIMAL(12,2) NOT NULL,
    retention_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    release_condition ENUM('auto_timeout', 'customer_confirmation', 'admin_release', 'dispute_resolution') NOT NULL,
    release_at TIMESTAMP NULL,
    released_at TIMESTAMP NULL,
    status ENUM('held', 'released', 'partially_released', 'refunded') NOT NULL DEFAULT 'held',
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE commission_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform ENUM('laundry', 'rider', 'construction', 'solar', 'event') NOT NULL DEFAULT 'rider',
    category VARCHAR(100) NOT NULL,
    commission_type ENUM('percentage', 'flat_fee', 'tiered') NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    min_transaction_value DECIMAL(12,2) NULL,
    max_transaction_value DECIMAL(12,2) NULL,
    effective_from TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    effective_to TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE kyc_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    -- driving_license and vehicle_logbook extend the shared taxonomy in
    -- shared-database-schema.md specifically for this platform's vehicle
    -- KYC needs (see rider_vehicle_documents below) — not part of the
    -- generic cross-platform enum, since no other platform needs them.
    document_type ENUM('national_id', 'kra_pin', 'business_registration', 'insurance_certificate', 'professional_certification', 'proof_of_address', 'driving_license', 'vehicle_logbook') NOT NULL,
    file_reference VARCHAR(500) NOT NULL,
    verification_status ENUM('pending', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    verified_by BIGINT UNSIGNED NULL,
    verified_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    reviewee_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewer_id) REFERENCES users(id),
    FOREIGN KEY (reviewee_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- category values for this platform: safety_incident, fare_dispute, route_deviation, lost_item, other
CREATE TABLE disputes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    raised_by BIGINT UNSIGNED NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    evidence_urls JSON NULL,
    status ENUM('open', 'under_review', 'resolved_refund', 'resolved_partial', 'resolved_no_action', 'escalated') NOT NULL DEFAULT 'open',
    resolved_by BIGINT UNSIGNED NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (raised_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    before_state JSON NULL,
    after_state JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- PLATFORM-SPECIFIC TABLES — rider.co.ke
-- (planning/02-rider-co-ke/database-schema.md)
-- ============================================================

CREATE TABLE rider_trips (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    rider_id BIGINT UNSIGNED NULL,
    trip_type ENUM('passenger_motorcycle', 'passenger_car', 'parcel_delivery') NOT NULL,
    status ENUM('requested', 'matched', 'rider_en_route', 'in_progress', 'completed', 'cancelled', 'disputed') NOT NULL DEFAULT 'requested',
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
    payment_id BIGINT UNSIGNED NULL,
    requested_at TIMESTAMP NULL,
    matched_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    proof_of_delivery_url VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (rider_id) REFERENCES users(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

-- High-write-volume table (one row per ~5-10s ping during an active trip) —
-- define a retention/archival job before production, per open-questions.md #8.
CREATE TABLE trip_pings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    rider_id BIGINT UNSIGNED NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    speed_kmh DECIMAL(6,2) NULL,
    heading_degrees DECIMAL(5,2) NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES rider_trips(id),
    FOREIGN KEY (rider_id) REFERENCES users(id),
    INDEX idx_trip_pings_trip (trip_id, recorded_at)
) ENGINE=InnoDB;

CREATE TABLE rider_vehicle_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_id BIGINT UNSIGNED NOT NULL,
    vehicle_type ENUM('motorcycle', 'car', 'tuk_tuk') NOT NULL,
    plate_number VARCHAR(20) NOT NULL,
    driving_license_kyc_document_id BIGINT UNSIGNED NOT NULL,
    insurance_kyc_document_id BIGINT UNSIGNED NOT NULL,
    logbook_kyc_document_id BIGINT UNSIGNED NULL,
    verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rider_id) REFERENCES users(id),
    FOREIGN KEY (driving_license_kyc_document_id) REFERENCES kyc_documents(id),
    FOREIGN KEY (insurance_kyc_document_id) REFERENCES kyc_documents(id),
    FOREIGN KEY (logbook_kyc_document_id) REFERENCES kyc_documents(id)
) ENGINE=InnoDB;

CREATE TABLE rider_availability (
    rider_id BIGINT UNSIGNED PRIMARY KEY,
    is_online BOOLEAN NOT NULL DEFAULT FALSE,
    current_lat DECIMAL(10,7) NULL,
    current_lng DECIMAL(10,7) NULL,
    last_ping_at TIMESTAMP NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rider_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE rider_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_id BIGINT UNSIGNED NOT NULL,
    plan_type ENUM('weekly', 'monthly') NOT NULL,
    discounted_commission_rate DECIMAL(5,2) NOT NULL,
    subscription_fee DECIMAL(10,2) NOT NULL,
    status ENUM('active', 'expired', 'cancelled') NOT NULL DEFAULT 'active',
    current_period_start DATE NOT NULL,
    current_period_end DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rider_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Loyalty points — added as a platform-specific extension (not part of the
-- original database-schema.md, which only specified this table for
-- laundry.co.ke) since rider.co.ke's prd.md also calls for a loyalty
-- program; same shape as laundry's, with trip_id replacing booking_id.
CREATE TABLE loyalty_points_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    trip_id BIGINT UNSIGNED NULL,
    points_change INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (trip_id) REFERENCES rider_trips(id)
) ENGINE=InnoDB;

CREATE TABLE rider_performance_tiers (
    rider_id BIGINT UNSIGNED PRIMARY KEY,
    average_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    completion_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    tier ENUM('standard', 'priority', 'elite') NOT NULL DEFAULT 'standard',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rider_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE saved_places (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE preferred_riders (
    customer_id BIGINT UNSIGNED NOT NULL,
    rider_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, rider_id),
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (rider_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- disputes.booking_id and reviews.booking_id both point at rider_trips.id;
ALTER TABLE disputes ADD CONSTRAINT fk_disputes_booking FOREIGN KEY (booking_id) REFERENCES rider_trips(id);
ALTER TABLE reviews ADD CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES rider_trips(id);
ALTER TABLE escrow_transactions ADD CONSTRAINT fk_escrow_booking FOREIGN KEY (booking_id) REFERENCES rider_trips(id);

-- ============================================================
-- E-COMMERCE STORE — shared shape across all five platforms
-- (see planning/00-portfolio/shared-architecture.md)
-- ============================================================

CREATE TABLE store_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_id BIGINT UNSIGNED NULL,
    category VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    image_urls JSON NULL,
    status ENUM('active', 'out_of_stock', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE store_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    status ENUM('pending', 'paid', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending',
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_address VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE store_order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES store_orders(id),
    FOREIGN KEY (product_id) REFERENCES store_products(id)
) ENGINE=InnoDB;

ALTER TABLE payments ADD CONSTRAINT fk_payments_store_order FOREIGN KEY (order_id) REFERENCES store_orders(id);
