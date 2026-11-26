ALTER TABLE Services
    ADD COLUMN IF NOT EXISTS service_category VARCHAR(100) NOT NULL DEFAULT 'Other' AFTER service_name;

ALTER TABLE Appointments
    ADD COLUMN IF NOT EXISTS pickup_ready_at DATETIME NULL AFTER approved_at;

UPDATE Services
SET service_category = CASE
    WHEN service_name IN ('Consultation', 'General Consultation / Physical Exam', 'Routine Health Check-up', 'Follow-up Check-up', 'Microchip Implantation', 'Health Certificate Issuance')
        THEN 'Preventive & Routine Care'
    WHEN service_name IN ('Vaccination', 'Deworming', 'Anti-Rabies Vaccine', '5-in-1 Core Vaccine (Dogs)', '6-in-1 Core Vaccine (Dogs)', '4-in-1 Core Vaccine (Cats)', 'Deworming (per dose)', 'Heartworm Preventive Injection')
        THEN 'Vaccinations & Immunization'
    WHEN service_name IN (
        'Grooming',
        'Basic Grooming (Bath & Blow Dry)',
        'Full Grooming (Bath, Haircut, Nail Trim, Ear Cleaning)',
        'Nail Trimming',
        'Ear Cleaning & Flea Check',
        'Medicated Bath / Anti-Tick & Flea Treatment',
        'Anal Gland Expression'
    )
        THEN 'Grooming & Hygiene'
    WHEN service_name IN (
        'Canine Spay (Female Dog)',
        'Canine Neuter (Male Dog)',
        'Feline Spay (Female Cat)',
        'Feline Neuter (Male Cat)',
        'Minor Wound Dressing & Suturing',
        'Dental Scaling & Polishing'
    )
        THEN 'Surgery & Minor Procedures'
    WHEN service_name IN ('CBC (Complete Blood Count)', 'Blood Chemistry Panel', 'Urinalysis', 'Fecal Examination', 'Complete Blood Count (CBC)', 'Fecalysis / Parasite Exam', 'X-Ray Examination (per view)', 'Abdominal Ultrasound', 'Parvovirus Test Kit', 'Distemper Test Kit')
        THEN 'Diagnostics & Laboratory Services'
    ELSE service_category
END;

UPDATE Services
SET duration_minutes = 60
WHERE service_name IN (
    'Basic Grooming (Bath & Blow Dry)',
    'Full Grooming (Bath, Haircut, Nail Trim, Ear Cleaning)',
    'Nail Trimming',
    'Ear Cleaning & Flea Check',
    'Medicated Bath / Anti-Tick & Flea Treatment',
    'Anal Gland Expression'
);

INSERT IGNORE INTO Services
    (service_name, service_category, base_price, duration_minutes, description, is_active)
VALUES
    ('Basic Grooming (Bath & Blow Dry)', 'Grooming & Hygiene', 450.00, 60, NULL, 1),
    ('Full Grooming (Bath, Haircut, Nail Trim, Ear Cleaning)', 'Grooming & Hygiene', 700.00, 60, NULL, 1),
    ('Nail Trimming', 'Grooming & Hygiene', 150.00, 60, NULL, 1),
    ('Ear Cleaning & Flea Check', 'Grooming & Hygiene', 200.00, 60, NULL, 1),
    ('Medicated Bath / Anti-Tick & Flea Treatment', 'Grooming & Hygiene', 600.00, 60, NULL, 1),
    ('Anal Gland Expression', 'Grooming & Hygiene', 250.00, 60, NULL, 1);

INSERT IGNORE INTO VetServices (vet_id, service_id)
SELECT v.vet_id, s.service_id
FROM Veterinarians v
CROSS JOIN Services s
WHERE s.service_category = 'Grooming & Hygiene';
