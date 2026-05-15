
DROP DATABASE IF EXISTS mctbs;
CREATE DATABASE IF NOT EXISTS mctbs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mctbs;

-- =============================================
-- USERS TABLE (all roles)
-- =============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('sysadmin','owner','admin','guest') NOT NULL DEFAULT 'guest',
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(30),
    profile_photo VARCHAR(255),
    email_verified_at DATETIME NULL,                                    -- FIX: DATETIME NULL (not TIMESTAMP NULL)
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_deactivated TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =============================================
-- INVITATIONS TABLE
-- =============================================
CREATE TABLE invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    role ENUM('owner','admin') NOT NULL,
    invited_by INT NOT NULL,
    owner_id INT NULL COMMENT 'For admin invites, which owner they belong to',
    used_at DATETIME NULL,                                              -- FIX: DATETIME NULL (not TIMESTAMP NULL)
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =============================================
-- EMAIL VERIFICATIONS
-- =============================================
CREATE TABLE email_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =============================================
-- PASSWORD RESETS
-- =============================================
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- OWNER-ADMIN RELATIONSHIP
-- =============================================
CREATE TABLE owner_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    admin_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_owner_admin (owner_id, admin_id),
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =============================================
-- TRANSIENT HOUSES
-- =============================================
CREATE TABLE transient_houses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    address VARCHAR(500) NOT NULL,
    city VARCHAR(100) NOT NULL,
    barangay VARCHAR(100),
    contact_number VARCHAR(30),
    amenities TEXT COMMENT 'JSON array of amenities',
    cover_photo VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =============================================
-- TRANSIENT UNITS
-- =============================================
CREATE TABLE transient_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    house_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    max_guests INT NOT NULL DEFAULT 1,
    price_per_night DECIMAL(10,2) NOT NULL,
    downpayment_amount DECIMAL(10,2) NOT NULL,
    amenities TEXT COMMENT 'JSON array',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (house_id) REFERENCES transient_houses(id) ON DELETE CASCADE
);

-- =============================================
-- UNIT PHOTOS
-- =============================================
CREATE TABLE unit_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    is_cover TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES transient_units(id) ON DELETE CASCADE
);

-- =============================================
-- UNIT CALENDAR / AVAILABILITY
-- =============================================
CREATE TABLE unit_calendar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('available','booked','maintenance','blocked') DEFAULT 'available',
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_unit_date (unit_id, date),
    FOREIGN KEY (unit_id) REFERENCES transient_units(id) ON DELETE CASCADE
);

-- =============================================
-- BOOKINGS
-- =============================================
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_code VARCHAR(30) NOT NULL UNIQUE,                          -- FIX: bumped from VARCHAR(20) to VARCHAR(30)
    unit_id INT NOT NULL,
    guest_id INT NOT NULL,
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    num_guests INT NOT NULL,
    total_nights INT NOT NULL,
    price_per_night DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    downpayment_amount DECIMAL(10,2) NOT NULL,
    remaining_balance DECIMAL(10,2) NOT NULL,
    status ENUM('pending','accepted','rejected','cancelled','completed') DEFAULT 'pending',
    payment_status ENUM('unpaid','downpaid','partially_paid','paid') DEFAULT 'unpaid',
    cancellation_policy_acknowledged TINYINT(1) DEFAULT 0,
    guest_notes TEXT,
    admin_notes TEXT,
    rejection_reason TEXT,
    cancellation_reason TEXT,
    cancelled_by INT NULL,
    payment_deadline DATETIME NULL,                                    -- FIX: DATETIME NULL (not TIMESTAMP NULL)
    confirmed_at DATETIME NULL,                                        -- FIX: DATETIME NULL (not TIMESTAMP NULL)
    completed_at DATETIME NULL,                                        -- FIX: DATETIME NULL (not TIMESTAMP NULL)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES transient_units(id) ON DELETE CASCADE,
    FOREIGN KEY (guest_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================
-- BOOKING QUEUE (for waitlisted bookings)
-- =============================================
CREATE TABLE booking_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    booking_id INT NOT NULL COMMENT 'The pending booking waiting',
    queue_expires_at TIMESTAMP NOT NULL COMMENT '2 days from when they were queued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES transient_units(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- =============================================
-- PAYMENTS
-- =============================================
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    payment_type ENUM('downpayment','full','partial','balance') NOT NULL,
    payment_method ENUM('cash','gcash','bank_transfer') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference_number VARCHAR(255),
    receipt_path VARCHAR(255),
    num_guests INT,
    checkout_date DATE,
    notes TEXT,
    status ENUM('pending','verified','rejected') DEFAULT 'pending',
    verified_by INT NULL,
    verified_at DATETIME NULL,                                         -- FIX: DATETIME NULL (not TIMESTAMP NULL)
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================
-- NOTIFICATIONS
-- =============================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(500),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =============================================
-- DIGITAL RECEIPTS
-- =============================================
CREATE TABLE receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- =============================================
-- SEED: System Admin account
-- Password: Admin@1234 (bcrypt)
-- =============================================
INSERT INTO users (email, password, role, first_name, last_name, email_verified_at)
VALUES (
    'sysadmin@mctbs.com',
    '$2b$12$lhxP0xw22jPMV4iEMK5YteB9iZCGd6xTPHpqvzSjfpGiJoma2JA0i', -- FIX: correct bcrypt hash for Admin@1234
    'sysadmin',
    'System',
    'Admin',
    NOW()
);





-- chnages --

-- Add extra guest charge to bookings
ALTER TABLE `bookings` ADD COLUMN `extra_guest_charge` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `num_guests`;

-- Remove downpayment_amount from transient_units
ALTER TABLE `transient_units` DROP COLUMN `downpayment_amount`;

-- Create booking_damages table
CREATE TABLE `booking_damages` (
    `id`          INT            NOT NULL AUTO_INCREMENT,
    `booking_id`  INT            NOT NULL,
    `description` VARCHAR(255)   NOT NULL,
    `quantity`    INT            NOT NULL DEFAULT 1,
    `unit_price`  DECIMAL(10,2)  NOT NULL,
    `total_price` DECIMAL(10,2)  NOT NULL,
    `added_by`    INT            NOT NULL,
    `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_damages_booking` (`booking_id`),
    KEY `fk_damages_added_by` (`added_by`),
    CONSTRAINT `fk_damages_booking`  FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_damages_added_by` FOREIGN KEY (`added_by`)   REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Add policies column to transient_houses
ALTER TABLE `transient_houses` ADD COLUMN `policies` TEXT DEFAULT NULL AFTER `amenities`;

-- Create booking_reviews table
CREATE TABLE `booking_reviews` (
    `id`          INT            NOT NULL AUTO_INCREMENT,
    `booking_id`  INT            NOT NULL,
    `guest_id`    INT            NOT NULL,
    `rating`      TINYINT(1)     NOT NULL DEFAULT 5,
    `comment`     TEXT           NOT NULL,
    `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_booking_review` (`booking_id`),
    KEY `fk_review_booking` (`booking_id`),
    KEY `fk_review_guest` (`guest_id`),
    CONSTRAINT `fk_review_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_review_guest`   FOREIGN KEY (`guest_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ALTER TABLE -- 
ALTER TABLE `invitations` MODIFY COLUMN `role` ENUM('owner','admin','sysadmin') NOT NULL;
ALTER TABLE `bookings` ADD COLUMN `archive_note` TEXT AFTER `admin_notes`;



-- Add refund and edit tracking columns to bookings
ALTER TABLE `bookings` 
    ADD COLUMN `cancelled_by_role` VARCHAR(20) DEFAULT NULL AFTER `cancelled_by`,
    ADD COLUMN `refund_status` ENUM('none','requested','refunded') NOT NULL DEFAULT 'none' AFTER `cancelled_by_role`,
    ADD COLUMN `refund_details` TEXT DEFAULT NULL AFTER `refund_status`,
    ADD COLUMN `additional_downpayment_required` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `refund_details`;

-- Booking edits log table
CREATE TABLE `booking_edits` (
    `id`              INT            NOT NULL AUTO_INCREMENT,
    `booking_id`      INT            NOT NULL,
    `edited_by`       INT            NOT NULL,
    `old_check_in`    DATE           NOT NULL,
    `old_check_out`   DATE           NOT NULL,
    `old_num_guests`  INT            NOT NULL,
    `old_total`       DECIMAL(10,2)  NOT NULL,
    `new_check_in`    DATE           NOT NULL,
    `new_check_out`   DATE           NOT NULL,
    `new_num_guests`  INT            NOT NULL,
    `new_total`       DECIMAL(10,2)  NOT NULL,
    `reason`          TEXT           DEFAULT NULL,
    `additional_downpayment` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_edits_booking` (`booking_id`),
    KEY `fk_edits_editor`  (`edited_by`),
    CONSTRAINT `fk_edits_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_edits_editor`  FOREIGN KEY (`edited_by`)  REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Update the ENUM for unit_calendar
UPDATE unit_calendar 
SET status = 'Unavailable' 
WHERE status IN ('maintenance', 'blocked');

UPDATE unit_calendar 
SET status = 'Available' 
WHERE status = 'available';

UPDATE unit_calendar 
SET status = 'Booked' 
WHERE status = 'booked';


--
ALTER TABLE unit_calendar 
MODIFY status ENUM('Unavailable','Available','Booked') 
DEFAULT 'Available';


ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;


-- =============================================
-- MIGRATION: Assign staff to a specific transient house
-- Run this on your mctbs database ONCE
-- =============================================
 
-- Step 1: Add house_id to invitations table
ALTER TABLE invitations
    ADD COLUMN house_id INT NULL AFTER owner_id,
    ADD CONSTRAINT fk_invitations_house
        FOREIGN KEY (house_id) REFERENCES transient_houses(id) ON DELETE SET NULL;
 
-- Step 2: Add house_id to owner_admins table
ALTER TABLE owner_admins
    ADD COLUMN house_id INT NULL AFTER admin_id,
    ADD CONSTRAINT fk_owner_admins_house
        FOREIGN KEY (house_id) REFERENCES transient_houses(id) ON DELETE SET NULL;
 


-- added tables for archiving bookskings and recipts
-- =============================================
-- RECEIPTS ARCHIVE
-- =============================================
CREATE TABLE receipts_archive (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- PAYMENTS ARCHIVE (for old payment records)
-- =============================================
CREATE TABLE payments_archive (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    payment_type ENUM('downpayment','full','partial','balance') NOT NULL,
    payment_method ENUM('cash','gcash','bank_transfer') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference_number VARCHAR(255),
    receipt_path VARCHAR(255),
    num_guests INT,
    checkout_date DATE,
    notes TEXT,
    status ENUM('pending','verified','rejected') DEFAULT 'pending',
    verified_by INT NULL,
    verified_at DATETIME NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- BOOKINGS ARCHIVE (for old completed/cancelled bookings)
-- =============================================
CREATE TABLE bookings_archive (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_code VARCHAR(30) NOT NULL UNIQUE,
    unit_id INT NOT NULL,
    guest_id INT NOT NULL,
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    num_guests INT NOT NULL,
    total_nights INT NOT NULL,
    price_per_night DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    downpayment_amount DECIMAL(10,2) NOT NULL,
    remaining_balance DECIMAL(10,2) NOT NULL,
    status ENUM('pending','accepted','rejected','cancelled','completed') DEFAULT 'pending',
    payment_status ENUM('unpaid','downpaid','partially_paid','paid') DEFAULT 'unpaid',
    cancellation_policy_acknowledged TINYINT(1) DEFAULT 0,
    guest_notes TEXT,
    admin_notes TEXT,
    archive_note TEXT,
    rejection_reason TEXT,
    cancellation_reason TEXT,
    cancelled_by INT NULL,
    payment_deadline DATETIME NULL,
    confirmed_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);




-- =============================================
-- MCTBS DATABASE OPTIMIZATION SCRIPT
-- Physical Database Design & Performance
-- Advanced Database Systems - Final Project
-- =============================================
-- Safe to run on existing database.
-- Only ADDS indexes — no data is modified.
-- Run this in phpMyAdmin > SQL tab
-- =============================================

USE mctbs;

-- =============================================
-- 1. BOOKINGS TABLE
--    Most queried table in the system.
--    Covers: guest booking history, admin
--    status filtering, unit availability range.
-- =============================================

-- Guest views their own bookings filtered by status
ALTER TABLE bookings 
    ADD INDEX idx_bookings_guest_status (guest_id, status);

-- Admin/owner filters bookings by unit and date range
ALTER TABLE bookings 
    ADD INDEX idx_bookings_unit_dates (unit_id, check_in, check_out);

-- Filter bookings by status alone (admin dashboard)
ALTER TABLE bookings 
    ADD INDEX idx_bookings_status (status);

-- Filter cancelled bookings by who cancelled
ALTER TABLE bookings 
    ADD INDEX idx_bookings_cancelled_by (cancelled_by);


-- =============================================
-- 2. PAYMENTS TABLE
--    Admins verify payments by status.
--    Booking detail pages load all payments
--    for a given booking.
-- =============================================

-- Load all payments for a booking + filter by status
ALTER TABLE payments 
    ADD INDEX idx_payments_booking_status (booking_id, status);

-- Admin dashboard: all pending payments to verify
ALTER TABLE payments 
    ADD INDEX idx_payments_status (status);

-- Filter by payment method (analytics: GCash vs cash etc.)
ALTER TABLE payments 
    ADD INDEX idx_payments_method (payment_method);


-- =============================================
-- 3. NOTIFICATIONS TABLE
--    Queried on every page load for the
--    notification bell (unread count per user).
-- =============================================

-- Unread notifications per user, sorted by newest
ALTER TABLE notifications 
    ADD INDEX idx_notif_user_unread (user_id, is_read, created_at);


-- =============================================
-- 4. TRANSIENT HOUSES TABLE
--    Guest browsing filters active houses
--    by owner. JOIN performance on owner_id.
-- =============================================

-- Active houses per owner
ALTER TABLE transient_houses 
    ADD INDEX idx_houses_owner_active (owner_id, is_active);

-- Filter by city (guests browsing by location)
ALTER TABLE transient_houses 
    ADD INDEX idx_houses_city (city);

-- Filter by barangay
ALTER TABLE transient_houses 
    ADD INDEX idx_houses_barangay (barangay);


-- =============================================
-- 5. TRANSIENT UNITS TABLE
--    Units are loaded per house constantly.
--    Active filter applied on every listing page.
-- =============================================

-- Active units per house
ALTER TABLE transient_units 
    ADD INDEX idx_units_house_active (house_id, is_active);

-- Filter/sort units by price range
ALTER TABLE transient_units 
    ADD INDEX idx_units_price (price_per_night);


-- =============================================
-- 6. UNIT CALENDAR TABLE
--    Already has UNIQUE KEY on (unit_id, date)
--    which covers availability checks.
--    Adding status index for admin queries.
-- =============================================

-- Filter calendar entries by status (e.g. all blocked dates)
ALTER TABLE unit_calendar 
    ADD INDEX idx_calendar_status (unit_id, status);


-- =============================================
-- 7. BOOKING REVIEWS TABLE
--    Rating aggregation per guest profile.
--    Analytics: average rating queries.
-- =============================================

-- Guest profile: all reviews by this guest
ALTER TABLE booking_reviews 
    ADD INDEX idx_reviews_guest (guest_id);

-- Analytics: filter/group by rating value
ALTER TABLE booking_reviews 
    ADD INDEX idx_reviews_rating (rating);


-- Queue lookup: active queue entries per unit
ALTER TABLE booking_queue 
    ADD INDEX idx_queue_unit_dates (unit_id, check_in, check_out);


-- =============================================
-- 11. INVITATIONS TABLE
--     Admin looks up invitations by email
--     and by owner. Token lookup already
--     covered by UNIQUE constraint.
-- =============================================

-- Look up invitations sent to a specific email
ALTER TABLE invitations 
    ADD INDEX idx_invitations_email (email);

-- Look up all invitations sent by an owner
ALTER TABLE invitations 
    ADD INDEX idx_invitations_owner (owner_id);


-- =============================================
-- 12. OWNER_ADMINS TABLE
--     Look up all admins under an owner,
--     or which owner an admin belongs to.
--     Already has UNIQUE KEY on (owner_id, admin_id).
-- =============================================

-- Find which owner an admin belongs to
ALTER TABLE owner_admins 
    ADD INDEX idx_owner_admins_admin (admin_id);


-- =============================================
-- 13. USERS TABLE
--     Role-based filtering for admin panels.
--     Soft-delete support via deleted_at.
-- =============================================

-- Filter users by role (sysadmin panel)
ALTER TABLE users 
    ADD INDEX idx_users_role (role);

-- Soft-delete filter: active vs deleted users
ALTER TABLE users 
    ADD INDEX idx_users_deleted (deleted_at);
