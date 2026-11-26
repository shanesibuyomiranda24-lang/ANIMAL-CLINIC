CREATE TABLE IF NOT EXISTS VetScheduleRequests (
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

CREATE TABLE IF NOT EXISTS VetScheduleRequestDays (
    request_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (request_id,day_of_week),
    CONSTRAINT chk_vet_schedule_request_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_vet_schedule_request_hours CHECK (end_time > start_time),
    CONSTRAINT fk_vet_schedule_request_day FOREIGN KEY (request_id) REFERENCES VetScheduleRequests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS VetScheduleRequestBreaks (
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
