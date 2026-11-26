ALTER TABLE Staff
    ADD COLUMN can_manage_groomer_schedules TINYINT(1) NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_staff_groomer_schedule_permission CHECK (can_manage_groomer_schedules IN (0,1));

ALTER TABLE Groomers
    ADD COLUMN user_id INT UNSIGNED NULL AFTER groomer_id,
    ADD UNIQUE KEY uq_groomer_user (user_id),
    ADD CONSTRAINT fk_groomer_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL;

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
