-- =====================================================================
--  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
--  BCSP-064 : Bachelor of Computer Applications (IGNOU)
--  ---------------------------------------------------------------
--  Student      : Gagan Sahay
--  Enrolment No : 2400652732
--  Regional Ctr : 39 (NOIDA)
--  Study Centre : 07107 (Maharaja Agrasen College)
--  Guide        : Soumik Laik
--  ---------------------------------------------------------------
--  FILE    : lsbms_schema.sql
--  PURPOSE : Data Definition Language (DDL) script. Creates the
--            complete relational schema -- 14 tables in Third Normal
--            Form (3NF) with primary keys, foreign keys, referential
--            actions, CHECK constraints and supporting indexes.
--  ENGINE  : InnoDB  (chosen for FOREIGN KEY + transaction support)
--  CHARSET : utf8mb4 (full Unicode, including Indian language text)
-- =====================================================================

DROP DATABASE IF EXISTS lsbms_db;
CREATE DATABASE lsbms_db
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;
USE lsbms_db;

SET FOREIGN_KEY_CHECKS = 1;


-- =====================================================================
-- TABLE 1 : users
-- ---------------------------------------------------------------------
-- Single sign-on table for all three actors of the system. A discri-
-- minator column `role` distinguishes customer / provider / admin.
--
-- DESIGN NOTE (Normalisation): a single `users` table is preferred over
-- three separate login tables because name/email/password/phone are
-- common to every actor. Storing them once removes the update anomaly
-- that would arise if a person's contact details had to be changed in
-- multiple tables. Attributes specific ONLY to a provider (hourly rate,
-- experience, skills) are moved out to the `providers` table -- this is
-- what takes the design from 2NF to 3NF.
-- =====================================================================
CREATE TABLE users (
    user_id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    full_name      VARCHAR(100)  NOT NULL,
    email          VARCHAR(150)  NOT NULL,
    password_hash  VARCHAR(255)  NOT NULL  COMMENT 'bcrypt output of password_hash(); never plaintext',
    phone          VARCHAR(15)   NOT NULL,
    address        VARCHAR(255)      NULL,
    city           VARCHAR(60)       NULL,
    pincode        VARCHAR(6)        NULL,
    profile_photo  VARCHAR(255)      NULL  COMMENT 'Relative path under assets/uploads/',
    role           ENUM('customer','provider','admin') NOT NULL DEFAULT 'customer',
    status         ENUM('active','suspended')          NOT NULL DEFAULT 'active',
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id),
    UNIQUE  KEY uq_users_email (email),
    KEY     idx_users_role     (role),
    KEY     idx_users_city     (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 2 : categories
-- ---------------------------------------------------------------------
-- Master list of service types (Plumbing, Electrical, Cleaning ...).
-- Maintained by the Admin module. Referenced by providers, services
-- and maintenance_plans.
-- =====================================================================
CREATE TABLE categories (
    category_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    category_name  VARCHAR(80)   NOT NULL,
    description    VARCHAR(255)      NULL,
    icon           VARCHAR(40)       NULL  COMMENT 'Emoji / icon shown on the landing page',
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (category_id),
    UNIQUE  KEY uq_categories_name (category_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 3 : providers
-- ---------------------------------------------------------------------
-- Professional profile that EXTENDS a row of `users` where role =
-- 'provider'. One-to-one with users, many-to-one with categories.
--
-- DESIGN NOTE: avg_rating and total_jobs are DERIVED (denormalised)
-- values. They are deliberately stored -- not computed on every page
-- load -- because provider listings are the most frequently read screen
-- in the system. They are recalculated inside a transaction whenever
-- feedback is inserted, so they can never drift from the `feedback`
-- table. This is a conscious, documented denormalisation for read
-- performance.
-- =====================================================================
CREATE TABLE providers (
    provider_id         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED   NOT NULL,
    category_id         INT UNSIGNED   NOT NULL,
    experience_years    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    hourly_rate         DECIMAL(8,2)   NOT NULL DEFAULT 0.00,
    bio                 TEXT               NULL,
    skills              VARCHAR(255)       NULL  COMMENT 'Comma separated skill tags',
    service_area        VARCHAR(150)       NULL  COMMENT 'Localities / cities served',
    id_proof            VARCHAR(255)       NULL,
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by         INT UNSIGNED       NULL,
    verified_at         DATETIME           NULL,
    avg_rating          DECIMAL(3,2)   NOT NULL DEFAULT 0.00 COMMENT 'Derived from feedback; kept in sync transactionally',
    total_reviews       INT UNSIGNED   NOT NULL DEFAULT 0,
    total_jobs          INT UNSIGNED   NOT NULL DEFAULT 0,
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (provider_id),
    UNIQUE  KEY uq_providers_user (user_id),
    KEY     idx_providers_category     (category_id),
    KEY     idx_providers_verification (verification_status),
    KEY     idx_providers_rating       (avg_rating),

    CONSTRAINT fk_providers_user
        FOREIGN KEY (user_id)     REFERENCES users (user_id)
        ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_providers_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_providers_verifier
        FOREIGN KEY (verified_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 4 : provider_availability
-- ---------------------------------------------------------------------
-- Weekly recurring working hours. The booking engine intersects a
-- requested slot with these rows before accepting a booking.
-- day_of_week follows PHP date('w') : 0 = Sunday ... 6 = Saturday.
-- =====================================================================
CREATE TABLE provider_availability (
    availability_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id     INT UNSIGNED NOT NULL,
    day_of_week     TINYINT UNSIGNED NOT NULL COMMENT '0=Sun .. 6=Sat',
    start_time      TIME         NOT NULL,
    end_time        TIME         NOT NULL,
    is_available    TINYINT(1)   NOT NULL DEFAULT 1,

    PRIMARY KEY (availability_id),
    UNIQUE  KEY uq_avail_provider_day (provider_id, day_of_week),
    CONSTRAINT fk_avail_provider
        FOREIGN KEY (provider_id) REFERENCES providers (provider_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 5 : services
-- ---------------------------------------------------------------------
-- Individual priced offerings published by a provider, e.g.
-- "Tap leakage repair - Rs.300 - 45 minutes".
-- =====================================================================
CREATE TABLE services (
    service_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id      INT UNSIGNED NOT NULL,
    category_id      INT UNSIGNED NOT NULL,
    service_name     VARCHAR(120) NOT NULL,
    description      TEXT             NULL,
    base_price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (service_id),
    KEY idx_services_provider (provider_id),
    KEY idx_services_category (category_id),
    KEY idx_services_active   (is_active),

    CONSTRAINT fk_services_provider
        FOREIGN KEY (provider_id) REFERENCES providers (provider_id)
        ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_services_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 6 : bookings
-- ---------------------------------------------------------------------
-- The central transaction table of the system. Every service request
-- raised by a customer against a provider lives here.
--
-- `booking_code` is a human friendly reference (LSB-2026-000123) shown
-- to the user and printed on the invoice; the surrogate `booking_id`
-- remains the internal primary key.
-- =====================================================================
CREATE TABLE bookings (
    booking_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_code        VARCHAR(20)  NOT NULL,
    user_id             INT UNSIGNED NOT NULL COMMENT 'The customer raising the request',
    provider_id         INT UNSIGNED NOT NULL,
    service_id          INT UNSIGNED     NULL COMMENT 'NULL when booked directly against a provider',
    booking_date        DATE         NOT NULL,
    booking_time        TIME         NOT NULL,
    duration_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    service_address     VARCHAR(255) NOT NULL,
    city                VARCHAR(60)      NULL,
    pincode             VARCHAR(6)       NULL,
    problem_description TEXT             NULL,
    status              ENUM('pending','confirmed','in_progress','completed','cancelled','rejected')
                        NOT NULL DEFAULT 'pending',
    estimated_cost      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    final_cost          DECIMAL(10,2)     NULL,
    cancellation_reason VARCHAR(255)      NULL,
    is_maintenance      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = auto-raised by an AMC contract',
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (booking_id),
    UNIQUE  KEY uq_bookings_code (booking_code),
    KEY idx_bookings_user     (user_id),
    KEY idx_bookings_provider (provider_id),
    KEY idx_bookings_service  (service_id),
    KEY idx_bookings_status   (status),
    KEY idx_bookings_date     (booking_date),
    -- Composite index: the slot-conflict query filters on exactly this
    -- triple, so it is served entirely from the index.
    KEY idx_bookings_slot     (provider_id, booking_date, booking_time),

    CONSTRAINT fk_bookings_user
        FOREIGN KEY (user_id)     REFERENCES users (user_id)
        ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_provider
        FOREIGN KEY (provider_id) REFERENCES providers (provider_id)
        ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_service
        FOREIGN KEY (service_id)  REFERENCES services (service_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 7 : booking_status_history
-- ---------------------------------------------------------------------
-- Immutable audit trail. Every transition of bookings.status appends a
-- row here inside the same transaction as the UPDATE, so the history
-- can never disagree with the current status.
-- =====================================================================
CREATE TABLE booking_status_history (
    history_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id  INT UNSIGNED NOT NULL,
    old_status  VARCHAR(20)      NULL COMMENT 'NULL on the first (creation) row',
    new_status  VARCHAR(20)  NOT NULL,
    changed_by  INT UNSIGNED     NULL,
    remarks     VARCHAR(255)     NULL,
    changed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (history_id),
    KEY idx_bsh_booking (booking_id),
    CONSTRAINT fk_bsh_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (booking_id)
        ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_bsh_user
        FOREIGN KEY (changed_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 8 : maintenance_plans      [ MAINTENANCE MODULE ]
-- ---------------------------------------------------------------------
-- Catalogue of Annual Maintenance Contract (AMC) products, e.g.
-- "AC Quarterly Care -- 4 visits per year -- Rs.2400".
-- =====================================================================
CREATE TABLE maintenance_plans (
    plan_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id     INT UNSIGNED NOT NULL,
    plan_name       VARCHAR(120) NOT NULL,
    description     TEXT             NULL,
    frequency       ENUM('monthly','quarterly','half_yearly','yearly') NOT NULL DEFAULT 'quarterly',
    visits_per_year TINYINT UNSIGNED NOT NULL DEFAULT 4,
    price           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    duration_months TINYINT UNSIGNED NOT NULL DEFAULT 12,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (plan_id),
    KEY idx_plans_category (category_id),
    CONSTRAINT fk_plans_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 9 : maintenance_contracts  [ MAINTENANCE MODULE ]
-- ---------------------------------------------------------------------
-- A customer's live subscription to a maintenance_plan, serviced by a
-- chosen provider. `next_due_date` drives the reminder / notification
-- engine and rolls forward as each visit is completed.
-- =====================================================================
CREATE TABLE maintenance_contracts (
    contract_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_code   VARCHAR(20)  NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    provider_id     INT UNSIGNED NOT NULL,
    plan_id         INT UNSIGNED NOT NULL,
    start_date      DATE         NOT NULL,
    end_date        DATE         NOT NULL,
    next_due_date   DATE             NULL COMMENT 'NULL once every entitled visit is used',
    visits_used     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    total_visits    TINYINT UNSIGNED NOT NULL,
    amount_paid     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    service_address VARCHAR(255)     NULL,
    status          ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (contract_id),
    UNIQUE  KEY uq_contract_code (contract_code),
    KEY idx_contract_user     (user_id),
    KEY idx_contract_provider (provider_id),
    KEY idx_contract_plan     (plan_id),
    KEY idx_contract_status   (status),
    KEY idx_contract_due      (next_due_date),

    CONSTRAINT fk_contract_user
        FOREIGN KEY (user_id)     REFERENCES users (user_id)
        ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_contract_provider
        FOREIGN KEY (provider_id) REFERENCES providers (provider_id)
        ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_contract_plan
        FOREIGN KEY (plan_id)     REFERENCES maintenance_plans (plan_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 10 : maintenance_visits    [ MAINTENANCE MODULE ]
-- ---------------------------------------------------------------------
-- The individual scheduled visits generated from a contract. When a
-- visit falls due it is converted into a normal booking row (with
-- bookings.is_maintenance = 1) so that the provider's job queue and the
-- status workflow stay uniform across ad-hoc and AMC work.
-- =====================================================================
CREATE TABLE maintenance_visits (
    visit_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id        INT UNSIGNED NOT NULL,
    booking_id         INT UNSIGNED     NULL COMMENT 'Set when the visit is converted to a booking',
    visit_number       TINYINT UNSIGNED NOT NULL,
    scheduled_date     DATE         NOT NULL,
    completed_date     DATE             NULL,
    status             ENUM('scheduled','due','completed','missed') NOT NULL DEFAULT 'scheduled',
    technician_remarks VARCHAR(255)     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (visit_id),
    UNIQUE  KEY uq_visit_contract_no (contract_id, visit_number),
    KEY idx_visit_booking   (booking_id),
    KEY idx_visit_status    (status),
    KEY idx_visit_scheduled (scheduled_date),

    CONSTRAINT fk_visit_contract
        FOREIGN KEY (contract_id) REFERENCES maintenance_contracts (contract_id)
        ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_visit_booking
        FOREIGN KEY (booking_id)  REFERENCES bookings (booking_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 11 : feedback
-- ---------------------------------------------------------------------
-- Star rating + comment, allowed only against a COMPLETED booking.
-- The UNIQUE key on booking_id enforces the 1:1 cardinality stated in
-- the synopsis ER diagram -- one booking may be reviewed exactly once.
-- =====================================================================
CREATE TABLE feedback (
    feedback_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id  INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL COMMENT 'Whole stars, 1 to 5',
    comments    TEXT             NULL,
    is_approved TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Admin moderation flag',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (feedback_id),
    UNIQUE  KEY uq_feedback_booking (booking_id),
    KEY idx_feedback_provider (provider_id),
    KEY idx_feedback_user     (user_id),
    KEY idx_feedback_approved (is_approved),

    CONSTRAINT fk_feedback_booking
        FOREIGN KEY (booking_id)  REFERENCES bookings (booking_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_feedback_user
        FOREIGN KEY (user_id)     REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_feedback_provider
        FOREIGN KEY (provider_id) REFERENCES providers (provider_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 12 : payments
-- ---------------------------------------------------------------------
-- Payment record against a booking. Per the approved synopsis, live
-- gateway integration is OUT OF SCOPE -- settlement is simulated and
-- only the mode / status / reference are persisted.
-- =====================================================================
CREATE TABLE payments (
    payment_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id      INT UNSIGNED NOT NULL,
    invoice_no      VARCHAR(24)  NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    payment_mode    ENUM('cash','upi','card','netbanking') NOT NULL DEFAULT 'cash',
    payment_status  ENUM('pending','paid','refunded')      NOT NULL DEFAULT 'pending',
    transaction_ref VARCHAR(60)      NULL COMMENT 'Simulated reference; no live gateway',
    paid_at         DATETIME         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (payment_id),
    UNIQUE  KEY uq_payments_invoice (invoice_no),
    UNIQUE  KEY uq_payments_booking (booking_id),
    KEY idx_payments_status (payment_status),

    CONSTRAINT fk_payments_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (booking_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 13 : notifications
-- ---------------------------------------------------------------------
-- In-app message queue. A row is written on every booking status
-- change, provider verification decision and maintenance due date.
-- =====================================================================
CREATE TABLE notifications (
    notification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    title           VARCHAR(120) NOT NULL,
    message         VARCHAR(255) NOT NULL,
    link            VARCHAR(255)     NULL,
    icon            VARCHAR(16)      NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (notification_id),
    KEY idx_notif_user_read (user_id, is_read),
    CONSTRAINT fk_notif_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- TABLE 14 : activity_log
-- ---------------------------------------------------------------------
-- Security / audit log. Records logins, failed logins, administrative
-- actions and data changes together with the originating IP address.
-- =====================================================================
CREATE TABLE activity_log (
    log_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED     NULL COMMENT 'NULL for failed logins of unknown accounts',
    action     VARCHAR(60)  NOT NULL,
    entity     VARCHAR(40)      NULL,
    entity_id  INT UNSIGNED     NULL,
    details    VARCHAR(255)     NULL,
    ip_address VARCHAR(45)      NULL COMMENT 'Sized for IPv6',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (log_id),
    KEY idx_log_user   (user_id),
    KEY idx_log_action (action),
    KEY idx_log_time   (created_at),
    CONSTRAINT fk_log_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  VIEWS -- used by the Reports module (Module 10)
-- =====================================================================

-- Flattened provider directory used by the public search screen.
CREATE OR REPLACE VIEW vw_provider_directory AS
SELECT  p.provider_id,
        u.user_id,
        u.full_name,
        u.email,
        u.phone,
        u.city,
        u.pincode,
        u.status              AS account_status,
        c.category_id,
        c.category_name,
        p.experience_years,
        p.hourly_rate,
        p.skills,
        p.service_area,
        p.avg_rating,
        p.total_reviews,
        p.total_jobs,
        p.verification_status
FROM providers  p
JOIN users      u ON u.user_id     = p.user_id
JOIN categories c ON c.category_id = p.category_id;

-- Per-category booking + revenue rollup for the admin report screen.
CREATE OR REPLACE VIEW vw_category_performance AS
SELECT  c.category_id,
        c.category_name,
        COUNT(DISTINCT p.provider_id) AS total_providers,
        COUNT(b.booking_id)           AS total_bookings,
        SUM(b.status = 'completed')   AS completed_bookings,
        SUM(b.status = 'cancelled')   AS cancelled_bookings,
        COALESCE(SUM(CASE WHEN b.status = 'completed'
                          THEN b.final_cost END), 0) AS total_revenue
FROM categories c
LEFT JOIN providers p ON p.category_id = c.category_id
LEFT JOIN bookings  b ON b.provider_id = p.provider_id
GROUP BY c.category_id, c.category_name;

-- =====================================================================
--  END OF SCHEMA -- 14 tables, 2 views
-- =====================================================================


-- =====================================================================
--  DECLARATIVE INTEGRITY CONSTRAINTS
-- ---------------------------------------------------------------------
--  Applied as a separate block so that the constraint set can be
--  presented as a single unit in the Design Document ("Data integrity
--  and constraints"). These are CHECK constraints enforced by the
--  database engine itself -- they hold even if a row is inserted
--  outside the application, which is exactly why domain rules belong
--  here as well as in the PHP validation layer.
--
--  Supported by MariaDB 10.2.1+ and MySQL 8.0.16+.
-- =====================================================================

-- Domain rules on users -----------------------------------------------
ALTER TABLE users
    ADD CONSTRAINT chk_users_phone
        CHECK (phone REGEXP '^[0-9]{10}$'),
    ADD CONSTRAINT chk_users_pincode
        CHECK (pincode IS NULL OR pincode REGEXP '^[0-9]{6}$'),
    ADD CONSTRAINT chk_users_email
        CHECK (email LIKE '%_@_%._%');

-- Domain rules on providers -------------------------------------------
ALTER TABLE providers
    ADD CONSTRAINT chk_providers_rate
        CHECK (hourly_rate >= 0),
    ADD CONSTRAINT chk_providers_rating
        CHECK (avg_rating >= 0 AND avg_rating <= 5),
    ADD CONSTRAINT chk_providers_experience
        CHECK (experience_years <= 60);

-- A working window must be a real interval -----------------------------
ALTER TABLE provider_availability
    ADD CONSTRAINT chk_avail_day
        CHECK (day_of_week >= 0 AND day_of_week <= 6),
    ADD CONSTRAINT chk_avail_range
        CHECK (end_time > start_time);

-- Priced offerings ------------------------------------------------------
ALTER TABLE services
    ADD CONSTRAINT chk_services_price
        CHECK (base_price >= 0),
    ADD CONSTRAINT chk_services_duration
        CHECK (duration_minutes >= 15 AND duration_minutes <= 600);

-- Money on a booking can never be negative ------------------------------
ALTER TABLE bookings
    ADD CONSTRAINT chk_bookings_estimated
        CHECK (estimated_cost >= 0),
    ADD CONSTRAINT chk_bookings_final
        CHECK (final_cost IS NULL OR final_cost >= 0),
    ADD CONSTRAINT chk_bookings_duration
        CHECK (duration_minutes >= 15 AND duration_minutes <= 600);

-- AMC plan sanity -------------------------------------------------------
ALTER TABLE maintenance_plans
    ADD CONSTRAINT chk_plans_price
        CHECK (price >= 0),
    ADD CONSTRAINT chk_plans_visits
        CHECK (visits_per_year >= 1 AND visits_per_year <= 12);

-- A contract must run forwards, and cannot over-consume its visits ------
ALTER TABLE maintenance_contracts
    ADD CONSTRAINT chk_contract_dates
        CHECK (end_date > start_date),
    ADD CONSTRAINT chk_contract_visits
        CHECK (visits_used <= total_visits);

-- Ratings are whole stars, one to five ----------------------------------
ALTER TABLE feedback
    ADD CONSTRAINT chk_feedback_rating
        CHECK (rating >= 1 AND rating <= 5);

-- Invoice amounts -------------------------------------------------------
ALTER TABLE payments
    ADD CONSTRAINT chk_payments_amount
        CHECK (amount >= 0);

-- =====================================================================
--  END OF CONSTRAINTS BLOCK
-- =====================================================================
