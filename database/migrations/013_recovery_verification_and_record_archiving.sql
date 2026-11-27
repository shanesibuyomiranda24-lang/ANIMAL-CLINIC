ALTER TABLE PasswordRecoveryRequests
    ADD COLUMN verification_method VARCHAR(32) NULL AFTER reviewed_at,
    ADD COLUMN verification_result VARCHAR(20) NOT NULL DEFAULT 'Not verified' AFTER verification_method,
    ADD COLUMN verification_notes TEXT NULL AFTER verification_result,
    ADD COLUMN verified_by_user_id INT UNSIGNED NULL AFTER verification_notes,
    ADD COLUMN verified_at DATETIME NULL AFTER verified_by_user_id,
    ADD COLUMN rejection_reason VARCHAR(500) NULL AFTER verified_at,
    ADD COLUMN completed_at DATETIME NULL AFTER rejection_reason,
    ADD KEY idx_password_recovery_verification (status, verification_result),
    ADD KEY idx_password_recovery_verifier (verified_by_user_id),
    ADD CONSTRAINT chk_password_recovery_verification
        CHECK (verification_result IN ('Not verified','Verified')),
    ADD CONSTRAINT chk_password_recovery_verification_method
        CHECK (verification_method IS NULL OR verification_method IN ('in_person','registered_contact','other')),
    ADD CONSTRAINT fk_password_recovery_verifier
        FOREIGN KEY (verified_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL;

ALTER TABLE Users
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by_user_id INT UNSIGNED NULL,
    ADD COLUMN restored_at DATETIME NULL,
    ADD COLUMN restored_by_user_id INT UNSIGNED NULL,
    ADD KEY idx_users_archived (archived_at, restored_at),
    ADD CONSTRAINT fk_users_archived_by
        FOREIGN KEY (archived_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_restored_by
        FOREIGN KEY (restored_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL;

ALTER TABLE Pets
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by_user_id INT UNSIGNED NULL,
    ADD COLUMN restored_at DATETIME NULL,
    ADD COLUMN restored_by_user_id INT UNSIGNED NULL,
    ADD KEY idx_pets_archived (archived_at, restored_at),
    ADD CONSTRAINT fk_pets_archived_by
        FOREIGN KEY (archived_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_pets_restored_by
        FOREIGN KEY (restored_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL;

ALTER TABLE Appointments
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by_user_id INT UNSIGNED NULL,
    ADD COLUMN restored_at DATETIME NULL,
    ADD COLUMN restored_by_user_id INT UNSIGNED NULL,
    ADD KEY idx_appointments_archived (archived_at, restored_at),
    ADD CONSTRAINT fk_appointments_archived_by
        FOREIGN KEY (archived_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_appointments_restored_by
        FOREIGN KEY (restored_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL;
