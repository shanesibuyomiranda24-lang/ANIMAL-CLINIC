<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function dashboard_record_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$type = $_GET['type'] ?? '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_string($type) || !in_array($type, ['appointment', 'transaction', 'inventory'], true) || $id === false) {
    dashboard_record_respond(400, ['error' => 'Choose a valid dashboard record.']);
}

$value = function ($value): string {
    if ($value === null || $value === '') {
        return 'Not recorded';
    }
    return is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
};
$dateTime = function ($value): string {
    if ($value === null || $value === '') {
        return 'Not recorded';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? (string) $value : date('M j, Y g:i A', $timestamp);
};
$date = function ($value): string {
    if ($value === null || $value === '') {
        return 'Not recorded';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? (string) $value : date('M j, Y', $timestamp);
};
$fields = function (array $items) use ($value): array {
    $result = [];
    foreach ($items as $label => $itemValue) {
        $result[] = ['label' => $label, 'value' => $value($itemValue)];
    }
    return $result;
};
$sections = [];
$title = '';

try {
    $pdo = db();

    if ($type === 'appointment') {
        $statement = $pdo->prepare(
            'SELECT a.*,
                    p.pet_id, p.pet_name, p.species, p.breed, p.birth_date, p.gender,
                    p.created_at AS pet_created_at, p.updated_at AS pet_updated_at,
                    (p.photo_data IS NOT NULL) AS has_photo,
                    o.owner_id, o.full_name AS owner_name, o.email AS owner_email,
                    o.phone AS owner_phone, o.address AS owner_address,
                    o.created_at AS owner_created_at, o.updated_at AS owner_updated_at,
                    v.vet_id, v.full_name AS vet_name, v.license_number, v.contact_number AS vet_contact,
                    v.is_available AS vet_is_available, v.max_appointments_per_day AS vet_daily_capacity,
                    s.staff_id, s.full_name AS staff_name, s.`role` AS staff_role,
                    s.contact_number AS staff_contact,
                    mr.record_id, mr.symptoms, mr.examination, mr.diagnosis, mr.treatment,
                    mr.recommendations, mr.follow_up_date, mr.is_finalized, mr.finalized_at,
                    mr.created_at AS medical_record_created_at, medical_user.full_name AS medical_record_author
             FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id
             JOIN PetOwners o ON o.owner_id = p.owner_id
             LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
             LEFT JOIN Staff s ON s.staff_id = a.staff_id
             LEFT JOIN MedicalRecords mr ON mr.appointment_id = a.appointment_id
             LEFT JOIN Users medical_user ON medical_user.user_id = mr.created_by_user_id
             WHERE a.appointment_id = ?
             LIMIT 1'
        );
        $statement->execute([(int) $id]);
        $appointment = $statement->fetch();
        if (!$appointment) {
            dashboard_record_respond(404, ['error' => 'Appointment not found.']);
        }

        $title = 'Appointment #' . (int) $appointment['appointment_id'];
        $sections[] = [
            'heading' => 'Appointment',
            'fields' => $fields([
                'Appointment ID' => '#' . (int) $appointment['appointment_id'],
                'Date and time' => $dateTime($appointment['appointment_date']),
                'Duration' => (int) $appointment['duration_minutes'] . ' minutes',
                'Status' => $appointment['status'],
                'Client notes' => $appointment['client_notes'],
                'Payment timing preference' => $appointment['payment_timing_preference'],
                'Preferred payment method' => $appointment['preferred_payment_method'],
                'Cancellation reason' => $appointment['cancellation_reason'],
                'Rejection reason' => $appointment['rejection_reason'],
                'Approved at' => $dateTime($appointment['approved_at']),
                'Pickup ready at' => $dateTime($appointment['pickup_ready_at']),
                'Picked up at' => $dateTime($appointment['picked_up_at']),
                'Walk-in' => (bool) $appointment['walk_in'],
                'Reschedule request' => (bool) $appointment['is_reschedule_request'],
                'Created at' => $dateTime($appointment['created_at']),
                'Last updated' => $dateTime($appointment['updated_at']),
            ]),
        ];
        $sections[] = [
            'heading' => 'Pet',
            'fields' => $fields([
                'Pet ID' => '#' . (int) $appointment['pet_id'],
                'Name' => $appointment['pet_name'],
                'Species' => $appointment['species'],
                'Breed' => $appointment['breed'],
                'Date of birth' => $date($appointment['birth_date']),
                'Gender' => $appointment['gender'],
                'Photo on file' => (bool) $appointment['has_photo'],
                'Created at' => $dateTime($appointment['pet_created_at']),
                'Last updated' => $dateTime($appointment['pet_updated_at']),
            ]),
        ];
        $sections[] = [
            'heading' => 'Pet owner',
            'fields' => $fields([
                'Owner ID' => '#' . (int) $appointment['owner_id'],
                'Name' => $appointment['owner_name'],
                'Email' => $appointment['owner_email'],
                'Phone' => $appointment['owner_phone'],
                'Address' => $appointment['owner_address'],
                'Created at' => $dateTime($appointment['owner_created_at']),
                'Last updated' => $dateTime($appointment['owner_updated_at']),
            ]),
        ];
        $sections[] = [
            'heading' => 'Assigned staff',
            'fields' => $fields([
                'Veterinarian' => $appointment['vet_name'],
                'Veterinarian ID' => $appointment['vet_id'] === null ? null : '#' . (int) $appointment['vet_id'],
                'License number' => $appointment['license_number'],
                'Veterinarian contact' => $appointment['vet_contact'],
                'Veterinarian available' => $appointment['vet_is_available'] === null
                    ? null
                    : (bool) $appointment['vet_is_available'],
                'Daily appointment capacity' => $appointment['vet_daily_capacity'],
                'Staff member' => $appointment['staff_name'],
                'Staff ID' => $appointment['staff_id'] === null ? null : '#' . (int) $appointment['staff_id'],
                'Staff role' => $appointment['staff_role'],
                'Staff contact' => $appointment['staff_contact'],
            ]),
        ];

        $serviceStatement = $pdo->prepare(
            'SELECT aps.service_id, aps.groomer_id, s.service_name, s.service_category,
                    s.description, s.duration_minutes, aps.charged_price,
                    g.full_name AS groomer_name
             FROM AppointmentServices aps
             JOIN Services s ON s.service_id = aps.service_id
             LEFT JOIN Groomers g ON g.groomer_id = aps.groomer_id
             WHERE aps.appointment_id = ?
             ORDER BY s.service_name'
        );
        $serviceStatement->execute([(int) $id]);
        $serviceFields = [];
        foreach ($serviceStatement->fetchAll() as $index => $service) {
            $serviceFields['Service ' . ($index + 1)] = implode("\n", [
                'Service ID: #' . (int) $service['service_id'],
                (string) $service['service_name'] . ' (' . (string) $service['service_category'] . ')',
                'Duration: ' . (int) $service['duration_minutes'] . ' minutes',
                'Charged price: ₱' . number_format((float) $service['charged_price'], 2),
                'Groomer ID: ' . ($service['groomer_id'] === null ? 'Not assigned' : '#' . (int) $service['groomer_id']),
                'Groomer: ' . $value($service['groomer_name']),
                'Description: ' . $value($service['description']),
            ]);
        }
        $sections[] = [
            'heading' => 'Services',
            'fields' => $fields($serviceFields ?: ['Services' => 'No services recorded.']),
        ];

        if ($appointment['record_id'] !== null) {
            $sections[] = [
                'heading' => 'Medical record',
                'fields' => $fields([
                    'Record ID' => '#' . (int) $appointment['record_id'],
                    'Symptoms' => $appointment['symptoms'],
                    'Examination' => $appointment['examination'],
                    'Diagnosis' => $appointment['diagnosis'],
                    'Treatment' => $appointment['treatment'],
                    'Recommendations' => $appointment['recommendations'],
                    'Follow-up date' => $date($appointment['follow_up_date']),
                    'Finalized' => (bool) $appointment['is_finalized'],
                    'Finalized at' => $dateTime($appointment['finalized_at']),
                    'Recorded by' => $appointment['medical_record_author'],
                    'Created at' => $dateTime($appointment['medical_record_created_at']),
                ]),
            ];
        } else {
            $sections[] = [
                'heading' => 'Medical record',
                'fields' => $fields(['Record' => 'No medical record is linked to this appointment.']),
            ];
        }

        $prescriptionStatement = $pdo->prepare(
            'SELECT pr.prescription_id, pr.inventory_id,
                    COALESCE(pr.medication_name, inventory.item_name) AS medication_name,
                    pr.quantity, pr.unit_price, pr.instructions, pr.status,
                    pr.dispensed_at, u.full_name AS dispensed_by
             FROM Prescriptions pr
             LEFT JOIN Inventory inventory ON inventory.inventory_id = pr.inventory_id
             LEFT JOIN Users u ON u.user_id = pr.dispensed_by_user_id
             WHERE pr.appointment_id = ?
             ORDER BY pr.created_at, pr.prescription_id'
        );
        $prescriptionStatement->execute([(int) $id]);
        $prescriptionFields = [];
        foreach ($prescriptionStatement->fetchAll() as $index => $prescription) {
            $prescriptionFields['Prescription ' . ($index + 1) . ' (#' . (int) $prescription['prescription_id'] . ')'] = implode("\n", [
                'Medication: ' . $value($prescription['medication_name']),
                'Inventory ID: ' . ($prescription['inventory_id'] === null ? 'Not linked' : '#' . (int) $prescription['inventory_id']),
                'Quantity: ' . (int) $prescription['quantity'],
                'Unit price: ₱' . number_format((float) $prescription['unit_price'], 2),
                'Instructions: ' . $value($prescription['instructions']),
                'Status: ' . (string) $prescription['status'],
                'Dispensed by: ' . $value($prescription['dispensed_by']),
                'Dispensed at: ' . $dateTime($prescription['dispensed_at']),
            ]);
        }
        $sections[] = [
            'heading' => 'Prescriptions',
            'fields' => $fields($prescriptionFields ?: ['Prescriptions' => 'No prescriptions recorded.']),
        ];

        $paymentStatement = $pdo->prepare(
            'SELECT DISTINCT pay.payment_id, pay.amount_paid, pay.payment_method,
                    pay.verification_status, pay.payment_status, pay.transaction_reference,
                    pay.payment_date, pay.void_reason, pay.received_by_user_id,
                    received_user.full_name AS received_by
             FROM Payments pay
             LEFT JOIN Invoices i ON i.invoice_id = pay.invoice_id
                OR (pay.invoice_id IS NULL AND i.appointment_id = pay.appointment_id)
             LEFT JOIN Users received_user ON received_user.user_id = pay.received_by_user_id
             WHERE pay.appointment_id = ? OR i.appointment_id = ?
             ORDER BY pay.payment_date DESC, pay.payment_id DESC'
        );
        $paymentStatement->execute([(int) $id, (int) $id]);
        $paymentFields = [];
        foreach ($paymentStatement->fetchAll() as $index => $payment) {
            $paymentFields['Payment ' . ($index + 1) . ' (#' . (int) $payment['payment_id'] . ')'] = implode("\n", [
                'Amount: ₱' . number_format((float) $payment['amount_paid'], 2),
                'Method: ' . (string) $payment['payment_method'],
                'Verification: ' . (string) $payment['verification_status'],
                'Status: ' . (string) $payment['payment_status'],
                'Reference: ' . $value($payment['transaction_reference']),
                'Paid at: ' . $dateTime($payment['payment_date']),
                'Received by: ' . $value($payment['received_by']),
                'Void reason: ' . $value($payment['void_reason']),
            ]);
        }
        $sections[] = [
            'heading' => 'Payments',
            'fields' => $fields($paymentFields ?: ['Payments' => 'No payments recorded.']),
        ];

        $historyStatement = $pdo->prepare(
            'SELECT h.previous_status, h.new_status, h.reason, h.changed_at,
                    u.full_name AS changed_by
             FROM AppointmentStatusHistory h
             LEFT JOIN Users u ON u.user_id = h.changed_by_user_id
             WHERE h.appointment_id = ?
             ORDER BY h.changed_at DESC, h.history_id DESC'
        );
        $historyStatement->execute([(int) $id]);
        $historyFields = [];
        foreach ($historyStatement->fetchAll() as $index => $history) {
            $historyFields['Update ' . ($index + 1)] = implode("\n", [
                'Status: ' . $value($history['previous_status']) . ' → ' . (string) $history['new_status'],
                'Reason: ' . $value($history['reason']),
                'Changed by: ' . $value($history['changed_by']),
                'Changed at: ' . $dateTime($history['changed_at']),
            ]);
        }
        $sections[] = [
            'heading' => 'Status history',
            'fields' => $fields($historyFields ?: ['History' => 'No status changes recorded.']),
        ];
    } elseif ($type === 'transaction') {
        $statement = $pdo->prepare(
            'SELECT pay.*,
                    i.invoice_id AS related_invoice_id, i.invoice_no, i.invoice_status, i.generated_at,
                    i.finalized_at, i.voided_at AS invoice_voided_at, i.void_reason AS invoice_void_reason,
                    invoice_user.full_name AS invoice_generated_by,
                    a.appointment_id AS related_appointment_id, a.appointment_date, a.status AS appointment_status,
                    p.pet_id, p.pet_name, p.species, p.breed,
                    o.owner_id, o.full_name AS client_name, o.email AS client_email,
                    o.phone AS client_phone, o.address AS client_address,
                    v.full_name AS veterinarian_name,
                    received_user.full_name AS received_by
             FROM Payments pay
             LEFT JOIN Invoices i ON i.invoice_id = pay.invoice_id
                OR (pay.invoice_id IS NULL AND i.appointment_id = pay.appointment_id)
             LEFT JOIN Users invoice_user ON invoice_user.user_id = i.generated_by_user_id
             LEFT JOIN Appointments a ON a.appointment_id = COALESCE(pay.appointment_id, i.appointment_id)
             LEFT JOIN Pets p ON p.pet_id = a.pet_id
             LEFT JOIN PetOwners o ON o.owner_id = p.owner_id
             LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
             LEFT JOIN Users received_user ON received_user.user_id = pay.received_by_user_id
             WHERE pay.payment_id = ?
             LIMIT 1'
        );
        $statement->execute([(int) $id]);
        $payment = $statement->fetch();
        if (!$payment) {
            dashboard_record_respond(404, ['error' => 'Transaction not found.']);
        }

        $title = 'Transaction #TRX-' . (int) $payment['payment_id'];
        $sections[] = [
            'heading' => 'Payment',
            'fields' => $fields([
                'Payment ID' => '#TRX-' . (int) $payment['payment_id'],
                'Amount' => '₱' . number_format((float) $payment['amount_paid'], 2),
                'Payment method' => $payment['payment_method'],
                'Verification status' => $payment['verification_status'],
                'Payment status' => $payment['payment_status'],
                'Transaction reference' => $payment['transaction_reference'],
                'Payment date' => $dateTime($payment['payment_date']),
                'Received by' => $payment['received_by'],
                'Void reason' => $payment['void_reason'],
                'Corrected at' => $dateTime($payment['corrected_at']),
            ]),
        ];
        $sections[] = [
            'heading' => 'Client and appointment',
            'fields' => $fields([
                'Client ID' => $payment['owner_id'] === null ? null : '#' . (int) $payment['owner_id'],
                'Client name' => $payment['client_name'],
                'Client email' => $payment['client_email'],
                'Client phone' => $payment['client_phone'],
                'Client address' => $payment['client_address'],
                'Pet' => $payment['pet_name'],
                'Pet ID' => $payment['pet_id'] === null ? null : '#' . (int) $payment['pet_id'],
                'Species' => $payment['species'],
                'Breed' => $payment['breed'],
                'Appointment ID' => $payment['related_appointment_id'] === null ? null : '#' . (int) $payment['related_appointment_id'],
                'Appointment date' => $dateTime($payment['appointment_date']),
                'Appointment status' => $payment['appointment_status'],
                'Veterinarian' => $payment['veterinarian_name'],
            ]),
        ];
        $sections[] = [
            'heading' => 'Invoice',
            'fields' => $fields([
                'Invoice ID' => $payment['related_invoice_id'] === null ? null : '#' . (int) $payment['related_invoice_id'],
                'Invoice number' => $payment['invoice_no'],
                'Status' => $payment['invoice_status'],
                'Generated by' => $payment['invoice_generated_by'],
                'Generated at' => $dateTime($payment['generated_at']),
                'Finalized at' => $dateTime($payment['finalized_at']),
                'Voided at' => $dateTime($payment['invoice_voided_at']),
                'Void reason' => $payment['invoice_void_reason'],
            ]),
        ];

        $lineStatement = $pdo->prepare(
            'SELECT line_type, description, quantity, unit_price, line_total
             FROM InvoiceLines
             WHERE invoice_id = ?
             ORDER BY line_id'
        );
        $lineStatement->execute([$payment['related_invoice_id']]);
        $lineFields = [];
        foreach ($lineStatement->fetchAll() as $index => $line) {
            $lineFields['Line ' . ($index + 1)] = implode("\n", [
                (string) $line['line_type'] . ': ' . (string) $line['description'],
                'Quantity: ' . (int) $line['quantity'],
                'Unit price: ₱' . number_format((float) $line['unit_price'], 2),
                'Line total: ₱' . number_format((float) $line['line_total'], 2),
            ]);
        }
        $sections[] = [
            'heading' => 'Invoice items',
            'fields' => $fields($lineFields ?: ['Items' => 'No invoice lines recorded.']),
        ];

        $receiptStatement = $pdo->prepare(
            'SELECT r.receipt_no, r.issued_at, u.full_name AS issued_by
             FROM Receipts r
             LEFT JOIN Users u ON u.user_id = r.issued_by_user_id
             WHERE r.payment_id = ?
             LIMIT 1'
        );
        $receiptStatement->execute([(int) $id]);
        $receipt = $receiptStatement->fetch();
        $sections[] = [
            'heading' => 'Receipt',
            'fields' => $fields($receipt ? [
                'Receipt number' => $receipt['receipt_no'],
                'Issued by' => $receipt['issued_by'],
                'Issued at' => $dateTime($receipt['issued_at']),
            ] : ['Receipt' => 'No receipt is linked to this payment.']),
        ];

        $correctionStatement = $pdo->prepare(
            'SELECT c.previous_amount, c.new_amount, c.previous_status, c.new_status,
                    c.reason, c.corrected_at, u.full_name AS corrected_by
             FROM PaymentCorrections c
             LEFT JOIN Users u ON u.user_id = c.corrected_by_user_id
             WHERE c.payment_id = ?
             ORDER BY c.corrected_at DESC, c.correction_id DESC'
        );
        $correctionStatement->execute([(int) $id]);
        $correctionFields = [];
        foreach ($correctionStatement->fetchAll() as $index => $correction) {
            $correctionFields['Correction ' . ($index + 1)] = implode("\n", [
                'Amount: ₱' . number_format((float) $correction['previous_amount'], 2)
                    . ' → ₱' . number_format((float) $correction['new_amount'], 2),
                'Status: ' . (string) $correction['previous_status'] . ' → ' . (string) $correction['new_status'],
                'Reason: ' . (string) $correction['reason'],
                'Corrected by: ' . $value($correction['corrected_by']),
                'Corrected at: ' . $dateTime($correction['corrected_at']),
            ]);
        }
        $sections[] = [
            'heading' => 'Corrections',
            'fields' => $fields($correctionFields ?: ['Corrections' => 'No payment corrections recorded.']),
        ];
    } else {
        $statement = $pdo->prepare(
            'SELECT inventory_id, item_name, category, quantity, unit_price,
                    minimum_stock, expiration_date, created_at, updated_at
             FROM Inventory
             WHERE inventory_id = ?
             LIMIT 1'
        );
        $statement->execute([(int) $id]);
        $item = $statement->fetch();
        if (!$item) {
            dashboard_record_respond(404, ['error' => 'Inventory item not found.']);
        }

        $title = 'Inventory item: ' . (string) $item['item_name'];
        $sections[] = [
            'heading' => 'Inventory item',
            'fields' => $fields([
                'Inventory ID' => '#' . (int) $item['inventory_id'],
                'Item name' => $item['item_name'],
                'Category' => $item['category'],
                'Quantity' => (int) $item['quantity'],
                'Unit price' => '₱' . number_format((float) $item['unit_price'], 2),
                'Minimum stock' => (int) $item['minimum_stock'],
                'Expiration date' => $date($item['expiration_date']),
                'Created at' => $dateTime($item['created_at']),
                'Last updated' => $dateTime($item['updated_at']),
            ]),
        ];

        $movementStatement = $pdo->prepare(
            'SELECT t.transaction_id, t.change_qty, t.reason, t.appointment_id, t.prescription_id,
                    t.created_at, u.full_name AS created_by
             FROM InventoryTransactions t
             LEFT JOIN Users u ON u.user_id = t.created_by_user_id
             WHERE t.inventory_id = ?
             ORDER BY t.created_at DESC, t.transaction_id DESC'
        );
        $movementStatement->execute([(int) $id]);
        $movementFields = [];
        foreach ($movementStatement->fetchAll() as $index => $movement) {
            $movementFields['Movement ' . ($index + 1) . ' (#' . (int) $movement['transaction_id'] . ')'] = implode("\n", [
                'Quantity change: ' . ((int) $movement['change_qty'] > 0 ? '+' : '') . (int) $movement['change_qty'],
                'Reason: ' . (string) $movement['reason'],
                'Appointment ID: ' . ($movement['appointment_id'] === null ? 'Not recorded' : '#' . (int) $movement['appointment_id']),
                'Prescription ID: ' . ($movement['prescription_id'] === null ? 'Not recorded' : '#' . (int) $movement['prescription_id']),
                'Recorded by: ' . $value($movement['created_by']),
                'Recorded at: ' . $dateTime($movement['created_at']),
            ]);
        }
        $sections[] = [
            'heading' => 'Stock movement history',
            'fields' => $fields($movementFields ?: ['Movements' => 'No stock movements recorded.']),
        ];
    }

    dashboard_record_respond(200, [
        'record_type' => $type,
        'record_id' => (int) $id,
        'title' => $title,
        'sections' => $sections,
    ]);
} catch (Throwable $exception) {
    error_log('Dashboard record details failed: ' . $exception->getMessage());
    dashboard_record_respond(500, ['error' => 'Record details are unavailable. Please try again.']);
}
