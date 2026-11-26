ALTER TABLE Veterinarians
    ADD COLUMN max_appointments_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    ADD CONSTRAINT chk_vet_daily_capacity CHECK (max_appointments_per_day BETWEEN 1 AND 100);

ALTER TABLE Staff
    ADD COLUMN can_manage_vet_schedules TINYINT(1) NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_staff_schedule_permission CHECK (can_manage_vet_schedules IN (0,1));
