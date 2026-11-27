CREATE TABLE IF NOT EXISTS PasswordRecoveryRequests (
    request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_email VARCHAR(254) NOT NULL,
    user_id INT UNSIGNED NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Pending',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_ip_address VARCHAR(45) NULL,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    PRIMARY KEY (request_id),
    KEY idx_password_recovery_status_time (status, submitted_at),
    KEY idx_password_recovery_email_status (request_email, status),
    KEY idx_password_recovery_ip_time (request_ip_address, submitted_at),
    CONSTRAINT chk_password_recovery_status CHECK (status IN ('Pending','Approved','Rejected')),
    CONSTRAINT fk_password_recovery_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_password_recovery_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
