INSERT IGNORE INTO VetServices (vet_id, service_id)
SELECT v.vet_id, s.service_id
FROM Veterinarians v
CROSS JOIN Services s;
