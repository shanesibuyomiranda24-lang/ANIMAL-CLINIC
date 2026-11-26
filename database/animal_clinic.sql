-- ================================================================
-- ANIMAL_CLINIC - complete fresh database
-- MariaDB 10.11+ / phpMyAdmin
-- ================================================================

DROP DATABASE IF EXISTS animal_clinic_management;
CREATE DATABASE animal_clinic_management
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE animal_clinic_management;

SET GLOBAL max_allowed_packet = GREATEST(@@GLOBAL.max_allowed_packet, 4194304);

-- Accounts and clinic users
CREATE TABLE PetOwners (
    owner_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(254) NULL,
    phone VARCHAR(20) NULL,
    address VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_id),
    UNIQUE KEY uq_owner_email (email)
) ENGINE=InnoDB;

CREATE TABLE Staff (
    staff_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    `role` VARCHAR(50) NOT NULL DEFAULT 'Clinic Staff',
    contact_number VARCHAR(20) NULL,
    can_manage_vet_schedules TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_groomer_schedules TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (staff_id),
    CONSTRAINT chk_staff_groomer_schedule_permission CHECK (can_manage_groomer_schedules IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE Veterinarians (
    vet_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    license_number VARCHAR(20) NOT NULL,
    contact_number VARCHAR(20) NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    max_appointments_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (vet_id),
    UNIQUE KEY uq_vet_license (license_number),
    CONSTRAINT chk_vet_available CHECK (is_available IN (0,1)),
    CONSTRAINT chk_vet_daily_capacity CHECK (max_appointments_per_day BETWEEN 1 AND 100)
) ENGINE=InnoDB;

CREATE TABLE Groomers (
    groomer_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (groomer_id),
    UNIQUE KEY uq_groomer_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE Users (
    user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    `role` VARCHAR(20) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    owner_id INT UNSIGNED NULL,
    staff_id INT UNSIGNED NULL,
    vet_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_email (email),
    UNIQUE KEY uq_user_owner (owner_id),
    UNIQUE KEY uq_user_staff (staff_id),
    UNIQUE KEY uq_user_vet (vet_id),
    KEY idx_user_role_status (`role`,status),
    CONSTRAINT chk_user_role CHECK (`role` IN ('admin','staff','vet','client')),
    CONSTRAINT chk_user_status CHECK (status IN ('active','inactive')),
    CONSTRAINT fk_user_owner FOREIGN KEY (owner_id) REFERENCES PetOwners(owner_id) ON DELETE SET NULL,
    CONSTRAINT fk_user_staff FOREIGN KEY (staff_id) REFERENCES Staff(staff_id) ON DELETE SET NULL,
    CONSTRAINT fk_user_vet FOREIGN KEY (vet_id) REFERENCES Veterinarians(vet_id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE Groomers
    ADD CONSTRAINT fk_groomer_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL;

-- Pets and records
CREATE TABLE Pets (
    pet_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id INT UNSIGNED NOT NULL,
    pet_name VARCHAR(50) NOT NULL,
    species VARCHAR(50) NOT NULL,
    breed VARCHAR(50) NULL,
    birth_date DATE NULL,
    gender ENUM('Male','Female') NULL,
    photo_data MEDIUMBLOB NULL,
    photo_mime VARCHAR(30) NULL,
    photo_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (pet_id),
    KEY idx_pet_owner (owner_id,pet_name),
    CONSTRAINT fk_pet_owner FOREIGN KEY (owner_id) REFERENCES PetOwners(owner_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE Services (
    service_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_name VARCHAR(100) NOT NULL,
    service_category VARCHAR(100) NOT NULL DEFAULT 'Other',
    base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (service_id),
    UNIQUE KEY uq_service_name (service_name),
    CONSTRAINT chk_service_price CHECK (base_price >= 0),
    CONSTRAINT chk_service_duration CHECK (duration_minutes > 0),
    CONSTRAINT chk_service_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB;

-- Scheduling
CREATE TABLE VetSchedules (
    schedule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vet_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (schedule_id),
    UNIQUE KEY uq_vet_day (vet_id,day_of_week),
    CONSTRAINT chk_schedule_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_schedule_time CHECK (end_time > start_time),
    CONSTRAINT fk_schedule_vet FOREIGN KEY (vet_id) REFERENCES Veterinarians(vet_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE VetScheduleBreaks (
    break_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vet_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (break_id),
    KEY idx_vet_break (vet_id,day_of_week),
    CONSTRAINT chk_break_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_break_time CHECK (end_time > start_time),
    CONSTRAINT fk_break_vet FOREIGN KEY (vet_id) REFERENCES Veterinarians(vet_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE VetLeaves (
    leave_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vet_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason VARCHAR(150) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Approved',
    PRIMARY KEY (leave_id),
    KEY idx_leave_vet_range (vet_id,start_date,end_date),
    CONSTRAINT chk_leave_range CHECK (end_date >= start_date),
    CONSTRAINT chk_leave_status CHECK (status IN ('Pending','Approved','Rejected')),
    CONSTRAINT fk_leave_vet FOREIGN KEY (vet_id) REFERENCES Veterinarians(vet_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE VetScheduleRequests (
    request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vet_id INT UNSIGNED NOT NULL,
    submitted_by_user_id INT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Pending',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(255) NULL,
    PRIMARY KEY (request_id),
    KEY idx_vet_schedule_request (vet_id,status,submitted_at),
    CONSTRAINT chk_vet_schedule_request_status CHECK (status IN ('Pending','Approved','Rejected')),
    CONSTRAINT fk_vet_schedule_request_vet FOREIGN KEY (vet_id) REFERENCES Veterinarians(vet_id) ON DELETE CASCADE,
    CONSTRAINT fk_vet_schedule_request_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES Users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vet_schedule_request_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE VetScheduleRequestDays (
    request_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (request_id,day_of_week),
    CONSTRAINT chk_vet_schedule_request_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_vet_schedule_request_hours CHECK (end_time > start_time),
    CONSTRAINT fk_vet_schedule_request_day FOREIGN KEY (request_id) REFERENCES VetScheduleRequests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE VetScheduleRequestBreaks (
    break_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (break_id),
    KEY idx_vet_schedule_request_break (request_id,day_of_week),
    CONSTRAINT chk_vet_schedule_request_break_time CHECK (end_time > start_time),
    CONSTRAINT fk_vet_schedule_request_break_day FOREIGN KEY (request_id,day_of_week)
        REFERENCES VetScheduleRequestDays(request_id,day_of_week) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE GroomerSchedules (
    schedule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    groomer_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (schedule_id),
    UNIQUE KEY uq_groomer_day (groomer_id,day_of_week),
    CONSTRAINT chk_groomer_schedule_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_groomer_schedule_time CHECK (end_time > start_time),
    CONSTRAINT fk_groomer_schedule_profile FOREIGN KEY (groomer_id) REFERENCES Groomers(groomer_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE GroomerScheduleBreaks (
    break_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    groomer_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (break_id),
    KEY idx_groomer_break (groomer_id,day_of_week),
    CONSTRAINT chk_groomer_break_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_groomer_break_time CHECK (end_time > start_time),
    CONSTRAINT fk_groomer_break_profile FOREIGN KEY (groomer_id) REFERENCES Groomers(groomer_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE GroomerLeaves (
    leave_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    groomer_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason VARCHAR(150) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Pending',
    submitted_by_user_id INT UNSIGNED NOT NULL,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(255) NULL,
    PRIMARY KEY (leave_id),
    KEY idx_groomer_leave_range (groomer_id,start_date,end_date),
    CONSTRAINT chk_groomer_leave_range CHECK (end_date >= start_date),
    CONSTRAINT chk_groomer_leave_status CHECK (status IN ('Pending','Approved','Rejected')),
    CONSTRAINT fk_groomer_leave_profile FOREIGN KEY (groomer_id) REFERENCES Groomers(groomer_id) ON DELETE CASCADE,
    CONSTRAINT fk_groomer_leave_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES Users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_groomer_leave_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE GroomerScheduleRequests (
    request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    groomer_id INT UNSIGNED NOT NULL,
    submitted_by_user_id INT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Pending',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(255) NULL,
    PRIMARY KEY (request_id),
    KEY idx_groomer_schedule_request (groomer_id,status,submitted_at),
    CONSTRAINT chk_groomer_schedule_request_status CHECK (status IN ('Pending','Approved','Rejected')),
    CONSTRAINT fk_groomer_schedule_request_profile FOREIGN KEY (groomer_id) REFERENCES Groomers(groomer_id) ON DELETE CASCADE,
    CONSTRAINT fk_groomer_schedule_request_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES Users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_groomer_schedule_request_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE GroomerScheduleRequestDays (
    request_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (request_id,day_of_week),
    CONSTRAINT chk_groomer_schedule_request_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_groomer_schedule_request_hours CHECK (end_time > start_time),
    CONSTRAINT fk_groomer_schedule_request_day FOREIGN KEY (request_id) REFERENCES GroomerScheduleRequests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE GroomerScheduleRequestBreaks (
    break_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (break_id),
    KEY idx_groomer_schedule_request_break (request_id,day_of_week),
    CONSTRAINT chk_groomer_schedule_request_break_time CHECK (end_time > start_time),
    CONSTRAINT fk_groomer_schedule_request_break_day FOREIGN KEY (request_id,day_of_week)
        REFERENCES GroomerScheduleRequestDays(request_id,day_of_week) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ClinicHolidays (
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(100) NOT NULL,
    PRIMARY KEY (holiday_date)
) ENGINE=InnoDB;

CREATE TABLE VetServices (
    vet_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (vet_id,service_id),
    CONSTRAINT fk_vs_vet FOREIGN KEY (vet_id) REFERENCES Veterinarians(vet_id) ON DELETE CASCADE,
    CONSTRAINT fk_vs_service FOREIGN KEY (service_id) REFERENCES Services(service_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Appointments and workflow history
CREATE TABLE Appointments (
    appointment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pet_id INT UNSIGNED NOT NULL,
    vet_id INT UNSIGNED NULL,
    staff_id INT UNSIGNED NULL,
    appointment_date DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    client_notes VARCHAR(255) NULL,
    payment_timing_preference VARCHAR(24) NULL,
    preferred_payment_method VARCHAR(30) NULL,
    cancellation_reason VARCHAR(255) NULL,
    rejection_reason VARCHAR(255) NULL,
    approved_at DATETIME NULL,
    pickup_ready_at DATETIME NULL,
    picked_up_at DATETIME NULL,
    walk_in TINYINT(1) NOT NULL DEFAULT 0,
    is_reschedule_request TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (appointment_id),
    KEY idx_appt_vet_date (vet_id,appointment_date),
    KEY idx_appt_pet_date (pet_id,appointment_date),
    KEY idx_appt_status (status,appointment_date),
    CONSTRAINT chk_appt_status CHECK (
        status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation','Complete','Cancelled','Rejected')
    ),
    CONSTRAINT chk_appt_duration CHECK (duration_minutes > 0),
    CONSTRAINT chk_appt_payment_preference CHECK (
        (payment_timing_preference IS NULL AND preferred_payment_method IS NULL) OR
        (payment_timing_preference IS NOT NULL AND preferred_payment_method IS NOT NULL
         AND payment_timing_preference IN ('At clinic','Advance request')
         AND preferred_payment_method IN ('Cash','Card','GCash','Maya','MariBank','Bank Transfer','Other / ask clinic'))
    ),
    CONSTRAINT chk_walkin CHECK (walk_in IN (0,1)),
    CONSTRAINT fk_appt_pet FOREIGN KEY (pet_id) REFERENCES Pets(pet_id) ON DELETE RESTRICT,
    CONSTRAINT fk_appt_vet FOREIGN KEY (vet_id) REFERENCES Veterinarians(vet_id) ON DELETE SET NULL,
    CONSTRAINT fk_appt_staff FOREIGN KEY (staff_id) REFERENCES Staff(staff_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE AppointmentServices (
    appointment_service_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    groomer_id INT UNSIGNED NULL,
    charged_price DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (appointment_service_id),
    UNIQUE KEY uq_appt_service (appointment_id,service_id),
    KEY idx_appt_service (service_id),
    CONSTRAINT chk_charged_price CHECK (charged_price >= 0),
    CONSTRAINT fk_as_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE CASCADE,
    CONSTRAINT fk_as_service FOREIGN KEY (service_id) REFERENCES Services(service_id) ON DELETE RESTRICT,
    CONSTRAINT fk_as_groomer FOREIGN KEY (groomer_id) REFERENCES Groomers(groomer_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE AppointmentStatusHistory (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    previous_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NOT NULL,
    changed_by_user_id INT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    KEY idx_appt_history (appointment_id,changed_at),
    CONSTRAINT fk_ash_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE CASCADE,
    CONSTRAINT fk_ash_user FOREIGN KEY (changed_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE AppointmentReschedules (
    reschedule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    previous_vet_id INT UNSIGNED NULL,
    previous_date DATETIME NOT NULL,
    new_vet_id INT UNSIGNED NULL,
    new_date DATETIME NOT NULL,
    requested_by_user_id INT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reschedule_id),
    CONSTRAINT fk_reschedule_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE CASCADE,
    CONSTRAINT fk_reschedule_previous_vet FOREIGN KEY (previous_vet_id) REFERENCES Veterinarians(vet_id) ON DELETE SET NULL,
    CONSTRAINT fk_reschedule_new_vet FOREIGN KEY (new_vet_id) REFERENCES Veterinarians(vet_id) ON DELETE SET NULL,
    CONSTRAINT fk_reschedule_user FOREIGN KEY (requested_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Medical, prescription and vaccination records
CREATE TABLE MedicalRecords (
    record_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    symptoms TEXT NULL,
    examination TEXT NULL,
    diagnosis TEXT NOT NULL,
    treatment TEXT NULL,
    recommendations TEXT NULL,
    follow_up_date DATE NULL,
    is_finalized TINYINT(1) NOT NULL DEFAULT 0,
    finalized_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (record_id),
    UNIQUE KEY uq_medical_appt (appointment_id),
    CONSTRAINT chk_medical_final CHECK (is_finalized IN (0,1)),
    CONSTRAINT fk_med_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE CASCADE,
    CONSTRAINT fk_med_user FOREIGN KEY (created_by_user_id) REFERENCES Users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE Vaccinations (
    vaccination_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pet_id INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    administered_by_user_id INT UNSIGNED NULL,
    vaccination_name VARCHAR(100) NOT NULL,
    vaccination_date DATE NOT NULL,
    next_due_date DATE NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (vaccination_id),
    KEY idx_vax_pet_date (pet_id,vaccination_date),
    KEY idx_vax_due (next_due_date),
    CONSTRAINT chk_vax_due CHECK (next_due_date IS NULL OR next_due_date >= vaccination_date),
    CONSTRAINT fk_vax_pet FOREIGN KEY (pet_id) REFERENCES Pets(pet_id) ON DELETE CASCADE,
    CONSTRAINT fk_vax_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE SET NULL,
    CONSTRAINT fk_vax_user FOREIGN KEY (administered_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Inventory
CREATE TABLE Inventory (
    inventory_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    minimum_stock INT UNSIGNED NOT NULL DEFAULT 5,
    expiration_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (inventory_id),
    KEY idx_inventory_category (category),
    KEY idx_inventory_expiration (expiration_date),
    CONSTRAINT chk_inventory_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_inventory_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

CREATE TABLE Prescriptions (
    prescription_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    inventory_id INT UNSIGNED NULL,
    medication_name VARCHAR(100) NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    instructions VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Draft',
    dispensed_by_user_id INT UNSIGNED NULL,
    dispensed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prescription_id),
    KEY idx_rx_appt (appointment_id),
    KEY idx_rx_inventory (inventory_id),
    CONSTRAINT chk_rx_qty CHECK (quantity > 0),
    CONSTRAINT chk_rx_status CHECK (status IN ('Draft','Finalized','Dispensed','Cancelled')),
    CONSTRAINT fk_rx_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE CASCADE,
    CONSTRAINT fk_rx_inventory FOREIGN KEY (inventory_id) REFERENCES Inventory(inventory_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rx_user FOREIGN KEY (dispensed_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE InventoryTransactions (
    transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inventory_id INT UNSIGNED NOT NULL,
    change_qty INT NOT NULL,
    reason VARCHAR(60) NOT NULL,
    appointment_id INT UNSIGNED NULL,
    prescription_id INT UNSIGNED NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (transaction_id),
    KEY idx_inventory_tx (inventory_id,created_at),
    CONSTRAINT fk_tx_inventory FOREIGN KEY (inventory_id) REFERENCES Inventory(inventory_id) ON DELETE RESTRICT,
    CONSTRAINT fk_tx_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_prescription FOREIGN KEY (prescription_id) REFERENCES Prescriptions(prescription_id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_user FOREIGN KEY (created_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Billing: immutable invoice lines and payment corrections
CREATE TABLE Invoices (
    invoice_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    invoice_no VARCHAR(30) NOT NULL,
    invoice_status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
    generated_by_user_id INT UNSIGNED NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalized_at DATETIME NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(255) NULL,
    PRIMARY KEY (invoice_id),
    UNIQUE KEY uq_invoice_no (invoice_no),
    UNIQUE KEY uq_invoice_appt (appointment_id),
    CONSTRAINT chk_invoice_status CHECK (invoice_status IN ('Unpaid','Partially Paid','Paid','Void')),
    CONSTRAINT fk_invoice_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_user FOREIGN KEY (generated_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE InvoiceLines (
    line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id BIGINT UNSIGNED NOT NULL,
    line_type VARCHAR(20) NOT NULL,
    source_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (line_id),
    KEY idx_invoice_lines (invoice_id),
    CONSTRAINT chk_invoice_line_type CHECK (line_type IN ('Service','Medication','Other')),
    CONSTRAINT chk_invoice_qty CHECK (quantity > 0),
    CONSTRAINT chk_invoice_price CHECK (unit_price >= 0),
    CONSTRAINT fk_invoice_line FOREIGN KEY (invoice_id) REFERENCES Invoices(invoice_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id BIGINT UNSIGNED NULL,
    appointment_id INT UNSIGNED NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'Verified',
    payment_status VARCHAR(20) NOT NULL DEFAULT 'Recorded',
    transaction_reference VARCHAR(100) NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_by_user_id INT UNSIGNED NULL,
    void_reason VARCHAR(255) NULL,
    corrected_at DATETIME NULL,
    PRIMARY KEY (payment_id),
    KEY idx_payment_invoice (invoice_id,payment_date),
    KEY idx_payment_appt (appointment_id,payment_date),
    CONSTRAINT chk_payment_amount CHECK (amount_paid > 0),
    CONSTRAINT chk_payment_method CHECK (payment_method IN ('Cash','Card','GCash','Maya','MariBank','Bank Transfer','Online','Other')),
    CONSTRAINT chk_payment_verification CHECK (verification_status IN ('Pending','Verified','Failed')),
    CONSTRAINT chk_payment_status CHECK (payment_status IN ('Recorded','Voided','Corrected')),
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES Invoices(invoice_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_appt FOREIGN KEY (appointment_id) REFERENCES Appointments(appointment_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_user FOREIGN KEY (received_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE PaymentCorrections (
    correction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    previous_amount DECIMAL(10,2) NOT NULL,
    new_amount DECIMAL(10,2) NOT NULL,
    previous_status VARCHAR(20) NOT NULL,
    new_status VARCHAR(20) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    corrected_by_user_id INT UNSIGNED NULL,
    corrected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (correction_id),
    CONSTRAINT fk_correction_payment FOREIGN KEY (payment_id) REFERENCES Payments(payment_id) ON DELETE RESTRICT,
    CONSTRAINT fk_correction_user FOREIGN KEY (corrected_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE Receipts (
    receipt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    receipt_no VARCHAR(30) NOT NULL,
    issued_by_user_id INT UNSIGNED NULL,
    issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (receipt_id),
    UNIQUE KEY uq_receipt_no (receipt_no),
    UNIQUE KEY uq_receipt_payment (payment_id),
    CONSTRAINT fk_receipt_payment FOREIGN KEY (payment_id) REFERENCES Payments(payment_id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipt_user FOREIGN KEY (issued_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Notifications and accountability
CREATE TABLE Notifications (
    notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    category VARCHAR(30) NOT NULL,
    title VARCHAR(120) NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) NULL,
    event_key VARCHAR(120) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (notification_id),
    UNIQUE KEY uq_notification_event (user_id,event_key),
    KEY idx_notification_user (user_id,is_read,created_at),
    CONSTRAINT chk_notification_read CHECK (is_read IN (0,1)),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE admin_notification_reads (
    user_id INT UNSIGNED NOT NULL,
    notification_key VARCHAR(190) NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,notification_key),
    KEY idx_admin_read_date (read_at),
    CONSTRAINT fk_admin_read_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ActivityLog (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    entity VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    previous_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,
    details VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_activity_user_date (user_id,created_at),
    KEY idx_activity_entity (entity,entity_id),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Initial account and services
-- Password: admin123; bcrypt hash, not plaintext.
INSERT INTO Users(full_name,email,password_hash,`role`,status)
VALUES ('Animal Clinic Admin','animalclinic@gmail.com','$2y$12$Ux/kCGQ/LymkE8Ip/sAPd.Quj4qjno7fsu9zNV9xuJOUnJ5wKKNh2','admin','active');

-- Demo accounts for local testing only. All three accounts use password: admin123.
-- Change these passwords before deploying the application.
INSERT INTO Veterinarians(full_name,license_number,contact_number)
VALUES ('Dr. Maria Santos','VET-SEED-001',NULL);
SET @seed_vet_id = LAST_INSERT_ID();

INSERT INTO Users(full_name,email,password_hash,`role`,status,vet_id)
VALUES ('Dr. Maria Santos','vet@animalclinic.com',
        '$2y$12$Ux/kCGQ/LymkE8Ip/sAPd.Quj4qjno7fsu9zNV9xuJOUnJ5wKKNh2','vet','active',@seed_vet_id);

INSERT INTO Staff(full_name,`role`)
VALUES ('Alex Reyes','Clinic Staff');
SET @seed_staff_id = LAST_INSERT_ID();

INSERT INTO Users(full_name,email,password_hash,`role`,status,staff_id)
VALUES ('Alex Reyes','staff@animalclinic.com',
        '$2y$12$Ux/kCGQ/LymkE8Ip/sAPd.Quj4qjno7fsu9zNV9xuJOUnJ5wKKNh2','staff','active',@seed_staff_id);

INSERT INTO Services(service_name,service_category,base_price,duration_minutes,description,is_active) VALUES
('Consultation','Preventive & Routine Care',500.00,30,'General veterinary examination',1),
('Vaccination','Vaccinations & Immunization',350.00,30,'Routine vaccination',1),
('Deworming','Vaccinations & Immunization',250.00,30,'Routine deworming',1),
('Grooming','Grooming & Hygiene',800.00,60,'Basic grooming service',1),
('Basic Grooming (Bath & Blow Dry)','Grooming & Hygiene',450.00,60,NULL,1),
('Full Grooming (Bath, Haircut, Nail Trim, Ear Cleaning)','Grooming & Hygiene',700.00,60,NULL,1),
('Nail Trimming','Grooming & Hygiene',150.00,60,NULL,1),
('Ear Cleaning & Flea Check','Grooming & Hygiene',200.00,60,NULL,1),
('Medicated Bath / Anti-Tick & Flea Treatment','Grooming & Hygiene',600.00,60,NULL,1),
('Anal Gland Expression','Grooming & Hygiene',250.00,60,NULL,1),
('CBC (Complete Blood Count)','Diagnostics & Laboratory Services',900.00,30,'Complete blood count to support veterinary diagnosis, estimated starting rate.',1),
('Blood Chemistry Panel','Diagnostics & Laboratory Services',1800.00,45,'Blood chemistry screening, estimated starting rate.',1),
('Urinalysis','Diagnostics & Laboratory Services',500.00,30,'Urine testing to support urinary and general health assessment, estimated starting rate.',1),
('Fecal Examination','Diagnostics & Laboratory Services',350.00,30,'Stool testing for intestinal parasites and other concerns, estimated starting rate.',1),
('Other / Request a Quote','Other',500.00,30,'Initial consultation for an unlisted service. Describe the request in clinic notes. Additional tests or procedures will be quoted separately.',1);

INSERT IGNORE INTO VetServices(vet_id,service_id)
SELECT v.vet_id,s.service_id FROM Veterinarians v CROSS JOIN Services s;

DELIMITER $$

-- Prevent overlapping appointments for a veterinarian.
DROP TRIGGER IF EXISTS trg_appt_overlap_ins$$
CREATE TRIGGER trg_appt_overlap_ins BEFORE INSERT ON Appointments FOR EACH ROW
BEGIN
    IF NEW.vet_id IS NOT NULL
       AND NEW.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation')
       AND EXISTS (
           SELECT 1 FROM Appointments a
           WHERE a.vet_id=NEW.vet_id
             AND a.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation')
             AND a.appointment_date < DATE_ADD(NEW.appointment_date,INTERVAL NEW.duration_minutes MINUTE)
             AND DATE_ADD(a.appointment_date,INTERVAL a.duration_minutes MINUTE) > NEW.appointment_date
       ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Veterinarian has an overlapping appointment.';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_appt_overlap_upd$$
CREATE TRIGGER trg_appt_overlap_upd BEFORE UPDATE ON Appointments FOR EACH ROW
BEGIN
    IF NEW.vet_id IS NOT NULL
       AND NEW.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation')
       AND EXISTS (
           SELECT 1 FROM Appointments a
           WHERE a.vet_id=NEW.vet_id
             AND a.appointment_id<>NEW.appointment_id
             AND a.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation')
             AND a.appointment_date < DATE_ADD(NEW.appointment_date,INTERVAL NEW.duration_minutes MINUTE)
             AND DATE_ADD(a.appointment_date,INTERVAL a.duration_minutes MINUTE) > NEW.appointment_date
       ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='Veterinarian has an overlapping appointment.';
    END IF;

    IF NEW.status='Complete' AND OLD.status<>NEW.status
       AND NOT (
           NEW.vet_id IS NULL
           AND EXISTS (
               SELECT 1
               FROM AppointmentServices aps
               JOIN Services s ON s.service_id=aps.service_id
               WHERE aps.appointment_id=NEW.appointment_id
                 AND s.service_category='Grooming & Hygiene'
           )
           AND NOT EXISTS (
               SELECT 1
               FROM AppointmentServices aps
               JOIN Services s ON s.service_id=aps.service_id
               WHERE aps.appointment_id=NEW.appointment_id
                 AND s.service_category<>'Grooming & Hygiene'
           )
       )
       AND NOT EXISTS (SELECT 1 FROM MedicalRecords m WHERE m.appointment_id=NEW.appointment_id AND m.is_finalized=1)
    THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT='The consultation medical record must be finalized before completion.';
    END IF;

    IF NEW.status<>OLD.status THEN
        INSERT INTO AppointmentStatusHistory
            (appointment_id,previous_status,new_status,changed_by_user_id,reason)
        VALUES
            (NEW.appointment_id,OLD.status,NEW.status,
             @current_user_id,
             IF(NEW.status='Cancelled',NEW.cancellation_reason,IF(NEW.status='Rejected',NEW.rejection_reason,NULL)));
    END IF;
END$$

-- A prescription remains Draft/Finalized until staff/vet actually dispenses it.
DROP TRIGGER IF EXISTS trg_prescription_dispense$$
CREATE TRIGGER trg_prescription_dispense BEFORE UPDATE ON Prescriptions FOR EACH ROW
BEGIN
    DECLARE stock_qty INT;
    IF OLD.status<>NEW.status AND NEW.status='Dispensed' THEN
        IF NEW.inventory_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A stocked medicine is required for dispensing.';
        END IF;
        SELECT quantity INTO stock_qty FROM Inventory WHERE inventory_id=NEW.inventory_id FOR UPDATE;
        IF stock_qty IS NULL OR stock_qty<NEW.quantity THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Insufficient medicine stock.';
        END IF;
        UPDATE Inventory SET quantity=quantity-NEW.quantity WHERE inventory_id=NEW.inventory_id;
        INSERT INTO InventoryTransactions
            (inventory_id,change_qty,reason,appointment_id,prescription_id,created_by_user_id)
        VALUES
            (NEW.inventory_id,-NEW.quantity,'Medicine dispensed',NEW.appointment_id,NEW.prescription_id,@current_user_id);
    END IF;
END$$

-- Low-stock and replenishment audit notifications.
DROP TRIGGER IF EXISTS trg_inventory_low_stock$$
CREATE TRIGGER trg_inventory_low_stock AFTER UPDATE ON Inventory FOR EACH ROW
BEGIN
    IF NEW.quantity<=NEW.minimum_stock AND OLD.quantity>NEW.minimum_stock THEN
        INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
        SELECT user_id,'inventory','Low medicine stock',
               CONCAT(NEW.item_name,' is at or below minimum stock (',NEW.quantity,' left).'),
               'admin/inventory.php',CONCAT('low_stock:',NEW.inventory_id)
        FROM Users WHERE `role`='admin' AND status='active';
    END IF;
    IF NEW.quantity>OLD.quantity THEN
        INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
        SELECT user_id,'inventory','Medicine stock replenished',
               CONCAT(NEW.item_name,' stock increased to ',NEW.quantity,'.'),
               'admin/inventory.php',CONCAT('stock_in:',NEW.inventory_id,':',UNIX_TIMESTAMP())
        FROM Users WHERE `role`='admin' AND status='active';
    END IF;
END$$

-- A payment must be against an existing invoice and Verified.
DROP TRIGGER IF EXISTS trg_payment_validate$$
CREATE TRIGGER trg_payment_validate BEFORE INSERT ON Payments FOR EACH ROW
BEGIN
    DECLARE invoice_appt INT UNSIGNED;
    DECLARE invoice_status VARCHAR(20);
    DECLARE invoice_total DECIMAL(12,2);
    DECLARE paid_total DECIMAL(12,2);
    DECLARE resolved_invoice_id BIGINT UNSIGNED;

    IF NEW.invoice_id IS NULL AND NEW.appointment_id IS NOT NULL THEN
        SELECT invoice_id INTO resolved_invoice_id FROM Invoices
        WHERE appointment_id=NEW.appointment_id LIMIT 1;
        SET NEW.invoice_id=resolved_invoice_id;
    END IF;

    IF NEW.invoice_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment requires an invoice.';
    END IF;

    SELECT i.appointment_id,i.invoice_status INTO invoice_appt,invoice_status
    FROM Invoices i WHERE i.invoice_id=NEW.invoice_id;

    IF invoice_status='Void' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payments cannot be added to a void invoice.';
    END IF;

    IF NEW.verification_status<> 'Verified' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only verified payments can update balances.';
    END IF;

    SELECT COALESCE(SUM(line_total),0) INTO invoice_total
    FROM InvoiceLines WHERE invoice_id=NEW.invoice_id;

    SELECT COALESCE(SUM(amount_paid),0) INTO paid_total
    FROM Payments
    WHERE invoice_id=NEW.invoice_id
      AND payment_status='Recorded';

    IF NEW.amount_paid<=0 OR paid_total+NEW.amount_paid>invoice_total THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid payment amount or payment exceeds invoice balance.';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_payment_refresh_status$$
CREATE TRIGGER trg_payment_refresh_status AFTER INSERT ON Payments FOR EACH ROW
BEGIN
    DECLARE total DECIMAL(12,2);
    DECLARE paid DECIMAL(12,2);
    SELECT COALESCE(SUM(line_total),0) INTO total FROM InvoiceLines WHERE invoice_id=NEW.invoice_id;
    SELECT COALESCE(SUM(amount_paid),0) INTO paid FROM Payments
    WHERE invoice_id=NEW.invoice_id AND payment_status='Recorded';
    UPDATE Invoices
    SET invoice_status=CASE
        WHEN paid<=0 THEN 'Unpaid'
        WHEN paid>=total THEN 'Paid'
        ELSE 'Partially Paid'
    END
    WHERE invoice_id=NEW.invoice_id;
END$$

DELIMITER ;

-- Reporting and operational views
CREATE OR REPLACE VIEW v_invoices AS
SELECT i.invoice_id,i.invoice_no,i.appointment_id,a.appointment_date,
       p.pet_id,p.pet_name,p.owner_id,o.full_name AS owner_name,
       COALESCE(l.invoice_total,0) AS invoice_total,
       COALESCE(pay.amount_paid,0) AS amount_paid,
       GREATEST(COALESCE(l.invoice_total,0)-COALESCE(pay.amount_paid,0),0) AS balance_due,
       i.invoice_status AS payment_status,i.generated_at
FROM Invoices i
JOIN Appointments a ON a.appointment_id=i.appointment_id
JOIN Pets p ON p.pet_id=a.pet_id
JOIN PetOwners o ON o.owner_id=p.owner_id
LEFT JOIN (SELECT invoice_id,SUM(line_total) AS invoice_total FROM InvoiceLines GROUP BY invoice_id) l ON l.invoice_id=i.invoice_id
LEFT JOIN (SELECT invoice_id,SUM(amount_paid) AS amount_paid FROM Payments WHERE payment_status='Recorded' GROUP BY invoice_id) pay ON pay.invoice_id=i.invoice_id;

CREATE OR REPLACE VIEW v_unpaid_balances AS
SELECT * FROM v_invoices WHERE balance_due>0 AND payment_status<> 'Void';

CREATE OR REPLACE VIEW v_daily_sales AS
SELECT DATE(payment_date) AS sale_date,COUNT(*) AS payments,SUM(amount_paid) AS total_sales
FROM Payments
WHERE payment_status='Recorded' AND verification_status='Verified'
GROUP BY DATE(payment_date);

CREATE OR REPLACE VIEW v_upcoming_appointments AS
SELECT a.appointment_id,a.appointment_date,a.duration_minutes,a.status,
       p.pet_name,o.full_name AS owner_name,v.full_name AS vet_name
FROM Appointments a
JOIN Pets p ON p.pet_id=a.pet_id
JOIN PetOwners o ON o.owner_id=p.owner_id
LEFT JOIN Veterinarians v ON v.vet_id=a.vet_id
WHERE a.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation')
  AND a.appointment_date>=CURRENT_DATE;

CREATE OR REPLACE VIEW v_vaccination_log AS
SELECT v.*,p.pet_name,p.owner_id
FROM Vaccinations v JOIN Pets p ON p.pet_id=v.pet_id;

CREATE OR REPLACE VIEW v_inventory_status AS
SELECT i.*,
       CASE
           WHEN expiration_date IS NOT NULL AND expiration_date<CURRENT_DATE THEN 'Expired'
           WHEN quantity=0 THEN 'Out of Stock'
           WHEN quantity<=minimum_stock THEN 'Low Stock'
           WHEN expiration_date IS NOT NULL AND expiration_date<=DATE_ADD(CURRENT_DATE,INTERVAL 30 DAY) THEN 'Expiring Soon'
           ELSE 'In Stock'
       END AS stock_status
FROM Inventory i;

DELIMITER $$

-- Generate an immutable invoice after consultation completion.
DROP PROCEDURE IF EXISTS sp_generate_invoice$$
CREATE PROCEDURE sp_generate_invoice(
    IN p_appointment_id INT UNSIGNED,
    IN p_user_id INT UNSIGNED
)
BEGIN
    DECLARE v_invoice_id BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(30);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;
    SELECT status INTO v_status FROM Appointments WHERE appointment_id=p_appointment_id FOR UPDATE;
    IF v_status<> 'Complete' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invoices can only be generated for completed consultations.';
    END IF;
    IF EXISTS (SELECT 1 FROM Invoices WHERE appointment_id=p_appointment_id) THEN
        SELECT invoice_id INTO v_invoice_id FROM Invoices WHERE appointment_id=p_appointment_id;
        COMMIT;
        SELECT v_invoice_id AS invoice_id;
    ELSE
        INSERT INTO Invoices(appointment_id,invoice_no,invoice_status,generated_by_user_id)
        VALUES(p_appointment_id,CONCAT('INV-',LPAD(p_appointment_id,6,'0')),'Unpaid',p_user_id);
        SET v_invoice_id=LAST_INSERT_ID();

        INSERT INTO InvoiceLines(invoice_id,line_type,source_id,description,quantity,unit_price,line_total)
        SELECT v_invoice_id,'Service',aps.service_id,s.service_name,1,aps.charged_price,aps.charged_price
        FROM AppointmentServices aps JOIN Services s ON s.service_id=aps.service_id
        WHERE aps.appointment_id=p_appointment_id;

        INSERT INTO InvoiceLines(invoice_id,line_type,source_id,description,quantity,unit_price,line_total)
        SELECT v_invoice_id,'Medication',rx.prescription_id,
               COALESCE(rx.medication_name,i.item_name),rx.quantity,
               COALESCE(rx.unit_price,i.unit_price),
               rx.quantity*COALESCE(rx.unit_price,i.unit_price)
        FROM Prescriptions rx
        LEFT JOIN Inventory i ON i.inventory_id=rx.inventory_id
        WHERE rx.appointment_id=p_appointment_id AND rx.status='Finalized';

        INSERT INTO ActivityLog(user_id,action,entity,entity_id,previous_status,new_status,details)
        VALUES(p_user_id,'invoice.generated','invoice',v_invoice_id,NULL,'Unpaid',
               CONCAT('Invoice generated for appointment #',p_appointment_id));
        COMMIT;
        SELECT v_invoice_id AS invoice_id;
    END IF;
END$$

DROP PROCEDURE IF EXISTS sp_record_payment$$
CREATE PROCEDURE sp_record_payment(
    IN p_invoice_id BIGINT UNSIGNED,
    IN p_amount DECIMAL(10,2),
    IN p_method VARCHAR(30),
    IN p_user_id INT UNSIGNED,
    IN p_reference VARCHAR(100)
)
BEGIN
    DECLARE v_payment_id BIGINT UNSIGNED;
    DECLARE v_appt_id INT UNSIGNED;
    DECLARE v_owner_id INT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;
    SELECT appointment_id INTO v_appt_id FROM Invoices WHERE invoice_id=p_invoice_id FOR UPDATE;
    SELECT p.owner_id INTO v_owner_id
    FROM Appointments a JOIN Pets p ON p.pet_id=a.pet_id
    WHERE a.appointment_id=v_appt_id;

    INSERT INTO Payments(invoice_id,appointment_id,amount_paid,payment_method,verification_status,
                         payment_status,transaction_reference,payment_date,received_by_user_id)
    VALUES(p_invoice_id,v_appt_id,p_amount,p_method,
           IF(p_method='Cash','Verified','Verified'),'Recorded',p_reference,NOW(),p_user_id);
    SET v_payment_id=LAST_INSERT_ID();
    INSERT INTO Receipts(payment_id,receipt_no,issued_by_user_id)
    VALUES(v_payment_id,CONCAT('RCT-',LPAD(v_payment_id,8,'0')),p_user_id);
    INSERT INTO ActivityLog(user_id,action,entity,entity_id,previous_status,new_status,details)
    VALUES(p_user_id,'payment.recorded','payment',v_payment_id,NULL,'Recorded',
           CONCAT('Payment ',p_amount,' via ',p_method,' for invoice #',p_invoice_id));

    INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
    SELECT u.user_id,'billing','Payment recorded',
           CONCAT('Your payment of ₱',FORMAT(p_amount,2),' was recorded.'),
           'client/billing.php',CONCAT('payment:',v_payment_id)
    FROM Users u WHERE u.`role`='client' AND u.owner_id=v_owner_id;

    INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
    SELECT u.user_id,'billing','Payment received',
           CONCAT('Payment ₱',FORMAT(p_amount,2),' received for invoice #',p_invoice_id,'.'),
           'admin/admin_reports.php',CONCAT('payment_admin:',v_payment_id)
    FROM Users u WHERE u.`role`='admin' AND u.status='active';
    COMMIT;
    SELECT v_payment_id AS payment_id;
END$$

DROP PROCEDURE IF EXISTS sp_void_payment$$
CREATE PROCEDURE sp_void_payment(
    IN p_payment_id BIGINT UNSIGNED,
    IN p_reason VARCHAR(255),
    IN p_user_id INT UNSIGNED
)
BEGIN
    DECLARE v_invoice_id BIGINT UNSIGNED;
    DECLARE v_amount DECIMAL(10,2);
    DECLARE v_payment_status VARCHAR(20);
    DECLARE v_owner_id INT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN ROLLBACK; RESIGNAL; END;

    IF p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason))=0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A correction reason is required.';
    END IF;

    START TRANSACTION;
    SELECT invoice_id,amount_paid,payment_status
    INTO v_invoice_id,v_amount,v_payment_status
    FROM Payments WHERE payment_id=p_payment_id FOR UPDATE;
    IF v_invoice_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment not found.';
    END IF;
    IF v_payment_status<>'Recorded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only recorded payments can be voided.';
    END IF;

    SELECT p.owner_id INTO v_owner_id
    FROM Invoices i
    JOIN Appointments a ON a.appointment_id=i.appointment_id
    JOIN Pets p ON p.pet_id=a.pet_id
    WHERE i.invoice_id=v_invoice_id;

    INSERT INTO PaymentCorrections
        (payment_id,previous_amount,new_amount,previous_status,new_status,reason,corrected_by_user_id)
    VALUES(p_payment_id,v_amount,0,'Recorded','Voided',p_reason,p_user_id);
    UPDATE Payments
    SET payment_status='Voided',void_reason=p_reason,corrected_at=NOW()
    WHERE payment_id=p_payment_id;
    INSERT INTO ActivityLog(user_id,action,entity,entity_id,previous_status,new_status,details)
    VALUES(p_user_id,'payment.voided','payment',p_payment_id,'Recorded','Voided',p_reason);

    UPDATE Invoices i
    SET invoice_status=CASE
        WHEN COALESCE((SELECT SUM(amount_paid) FROM Payments WHERE invoice_id=i.invoice_id AND payment_status='Recorded'),0)<=0 THEN 'Unpaid'
        WHEN COALESCE((SELECT SUM(amount_paid) FROM Payments WHERE invoice_id=i.invoice_id AND payment_status='Recorded'),0)>=
             (SELECT SUM(line_total) FROM InvoiceLines WHERE invoice_id=i.invoice_id) THEN 'Paid'
        ELSE 'Partially Paid'
    END
    WHERE invoice_id=v_invoice_id;

    INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
    SELECT u.user_id,'billing','Payment voided',
           CONCAT('A payment of ₱',FORMAT(v_amount,2),' for invoice #',v_invoice_id,' was voided.'),
           'client/billing.php',CONCAT('payment_voided:',p_payment_id)
    FROM Users u WHERE u.`role`='client' AND u.owner_id=v_owner_id AND u.status='active';

    INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
    SELECT u.user_id,'billing','Payment correction',
           CONCAT('Payment ₱',FORMAT(v_amount,2),' for invoice #',v_invoice_id,' was voided.'),
           'admin/admin_reports.php',CONCAT('payment_voided_admin:',p_payment_id)
    FROM Users u WHERE u.`role`='admin' AND u.status='active';

    COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_adjust_inventory$$
CREATE PROCEDURE sp_adjust_inventory(
    IN p_inventory_id INT UNSIGNED,
    IN p_change_qty INT,
    IN p_reason VARCHAR(60),
    IN p_user_id INT UNSIGNED
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN ROLLBACK; RESIGNAL; END;
    IF p_change_qty=0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Inventory adjustment cannot be zero.';
    END IF;
    START TRANSACTION;
    UPDATE Inventory
    SET quantity=quantity+p_change_qty
    WHERE inventory_id=p_inventory_id AND quantity+p_change_qty>=0;
    IF ROW_COUNT()<>1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Inventory item missing or stock would become negative.';
    END IF;
    INSERT INTO InventoryTransactions(inventory_id,change_qty,reason,created_by_user_id)
    VALUES(p_inventory_id,p_change_qty,p_reason,p_user_id);
    INSERT INTO ActivityLog(user_id,action,entity,entity_id,previous_status,new_status,details)
    VALUES(p_user_id,'inventory.adjusted','inventory',p_inventory_id,NULL,NULL,
           CONCAT('Adjustment ',p_change_qty,' - ',p_reason));
    COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_daily_sales_summary$$
CREATE PROCEDURE sp_daily_sales_summary(IN p_from DATE,IN p_to DATE)
BEGIN
    SELECT sale_date,payments,total_sales
    FROM v_daily_sales
    WHERE sale_date BETWEEN p_from AND p_to
    ORDER BY sale_date;
END$$

DELIMITER ;
