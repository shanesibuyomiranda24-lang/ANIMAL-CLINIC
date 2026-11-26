ALTER TABLE Appointments
    ADD COLUMN IF NOT EXISTS picked_up_at DATETIME NULL AFTER pickup_ready_at;

DELIMITER $$

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

DELIMITER ;
