ALTER TABLE Appointments
    ADD COLUMN payment_timing_preference VARCHAR(24) NULL AFTER client_notes,
    ADD COLUMN preferred_payment_method VARCHAR(30) NULL AFTER payment_timing_preference,
    ADD CONSTRAINT chk_appt_payment_preference CHECK (
        (payment_timing_preference IS NULL AND preferred_payment_method IS NULL) OR
        (payment_timing_preference IS NOT NULL AND preferred_payment_method IS NOT NULL
         AND payment_timing_preference IN ('At clinic','Advance request')
         AND preferred_payment_method IN ('Cash','Card','GCash','Maya','MariBank','Bank Transfer','Other / ask clinic'))
    );

ALTER TABLE Payments
    DROP CONSTRAINT chk_payment_method,
    ADD CONSTRAINT chk_payment_method CHECK (
        payment_method IN ('Cash','Card','GCash','Maya','MariBank','Bank Transfer','Online','Other')
    );
