INSERT IGNORE INTO Services(service_name,base_price,duration_minutes,description,is_active) VALUES
('CBC (Complete Blood Count)',900.00,30,'Complete blood count to support veterinary diagnosis, estimated starting rate.',1),
('Blood Chemistry Panel',1800.00,45,'Blood chemistry screening, estimated starting rate.',1),
('Urinalysis',500.00,30,'Urine testing to support urinary and general health assessment, estimated starting rate.',1),
('Fecal Examination',350.00,30,'Stool testing for intestinal parasites and other concerns, estimated starting rate.',1),
('Other / Request a Quote',500.00,30,'Initial consultation for an unlisted service. Describe the request in clinic notes. Additional tests or procedures will be quoted separately.',1);

INSERT IGNORE INTO VetServices(vet_id,service_id)
SELECT v.vet_id,s.service_id
FROM Veterinarians v
JOIN Services s ON s.service_name IN (
    'CBC (Complete Blood Count)',
    'Blood Chemistry Panel',
    'Urinalysis',
    'Fecal Examination',
    'Other / Request a Quote'
);
