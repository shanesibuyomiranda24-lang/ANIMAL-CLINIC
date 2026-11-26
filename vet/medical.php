<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_once __DIR__ . '/../includes/pet_photos.php';
require_role(['vet']);

function vm_entity_id($value): int
{
    if (!is_scalar($value)) {
        return 0;
    }

    $id = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : (int) $id;
}

function vm_date(?string $value, string $format = 'M j, Y'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date($format, $timestamp);
}

function vm_age(?string $birthDate): string
{
    if (!$birthDate) {
        return 'Age not listed';
    }

    try {
        $birth = new DateTimeImmutable($birthDate);
        $age = $birth->diff(new DateTimeImmutable('today'));
    } catch (Exception $exception) {
        return 'Age not listed';
    }

    if ($age->y > 0) {
        return $age->y . ($age->y === 1 ? ' year' : ' years');
    }

    return max(0, $age->m) . ($age->m === 1 ? ' month' : ' months');
}

$pdo = db();
$userId = (int) $_SESSION['user_id'];
$vetStatement = $pdo->prepare(
    "SELECT vet_id FROM Users WHERE user_id = ? AND `role` = 'vet' AND status = 'active'"
);
$vetStatement->execute([$userId]);
$vetId = (int) $vetStatement->fetchColumn();
if ($vetId < 1) {
    http_response_code(403);
    exit('A veterinarian profile is required.');
}

$error = '';
$reopen = null;
$flash = $_SESSION['vet_medical_flash'] ?? '';
unset($_SESSION['vet_medical_flash']);
$requestedPetId = vm_entity_id($_GET['pet'] ?? null);
$requestedAppointmentId = vm_entity_id($_GET['appointment'] ?? null);
$requestedTab = is_scalar($_GET['tab'] ?? null) && $_GET['tab'] === 'vaccines' ? 'vaccines' : 'records';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = posted_string('action');
    if ($action === '' && isset($_POST['vaccination_name'])) {
        $action = 'save_vaccine';
    }

    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            if ($action === 'save_record') {
                $appointmentId = vm_entity_id(posted_string('appointment_id'));
                if ($appointmentId < 1) {
                    throw new InvalidArgumentException('Choose a valid appointment.');
                }

                $pdo->beginTransaction();
                $appointmentStatement = $pdo->prepare(
                    'SELECT a.appointment_id, a.pet_id, a.status, p.owner_id, p.pet_name
                     FROM Appointments a
                     JOIN Pets p ON p.pet_id = a.pet_id
                     WHERE a.appointment_id = ? AND a.vet_id = ?
                     FOR UPDATE'
                );
                $appointmentStatement->execute([$appointmentId, $vetId]);
                $appointment = $appointmentStatement->fetch();
                if (!$appointment) {
                    throw new InvalidArgumentException('This appointment is not assigned to you.');
                }
                if ($appointment['status'] === 'Complete') {
                    throw new InvalidArgumentException('Completed consultations cannot be edited.');
                }

                $values = [
                    'symptoms' => trim(posted_string('symptoms')),
                    'examination' => trim(posted_string('examination')),
                    'diagnosis' => trim(posted_string('diagnosis')),
                    'treatment' => trim(posted_string('treatment')),
                    'recommendations' => trim(posted_string('notes')),
                    'follow_up_date' => trim(posted_string('follow_up_date')),
                ];
                foreach (['symptoms', 'examination', 'diagnosis', 'treatment', 'recommendations'] as $field) {
                    if (mb_strlen($values[$field]) > 6000) {
                        throw new InvalidArgumentException('Medical record fields must be 6,000 characters or fewer.');
                    }
                }
                if ($values['diagnosis'] === '') {
                    throw new InvalidArgumentException('Enter a diagnosis.');
                }
                if ($values['follow_up_date'] !== '') {
                    $followUp = DateTimeImmutable::createFromFormat('!Y-m-d', $values['follow_up_date']);
                    if (!$followUp || $followUp->format('Y-m-d') !== $values['follow_up_date']) {
                        throw new InvalidArgumentException('Enter a valid follow-up date.');
                    }
                }

                $recordStatement = $pdo->prepare(
                    'SELECT record_id FROM MedicalRecords WHERE appointment_id = ? FOR UPDATE'
                );
                $recordStatement->execute([$appointmentId]);
                if ($recordStatement->fetchColumn()) {
                    $pdo->prepare(
                        'UPDATE MedicalRecords
                         SET symptoms = ?, examination = ?, diagnosis = ?, treatment = ?, recommendations = ?,
                             follow_up_date = ?, created_by_user_id = ?, is_finalized = 1, finalized_at = NOW()
                         WHERE appointment_id = ?'
                    )->execute([
                        $values['symptoms'] ?: null,
                        $values['examination'] ?: null,
                        $values['diagnosis'],
                        $values['treatment'] ?: null,
                        $values['recommendations'] ?: null,
                        $values['follow_up_date'] ?: null,
                        $userId,
                        $appointmentId,
                    ]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO MedicalRecords
                         (appointment_id, created_by_user_id, symptoms, examination, diagnosis, treatment,
                          recommendations, follow_up_date, is_finalized, finalized_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())'
                    )->execute([
                        $appointmentId,
                        $userId,
                        $values['symptoms'] ?: null,
                        $values['examination'] ?: null,
                        $values['diagnosis'],
                        $values['treatment'] ?: null,
                        $values['recommendations'] ?: null,
                        $values['follow_up_date'] ?: null,
                    ]);
                }

                role_log($pdo, $userId, 'medical_record.saved', 'appointment', $appointmentId, 'Diagnosis and treatment recorded');
                role_notify_owner(
                    $pdo,
                    (int) $appointment['owner_id'],
                    'medical',
                    'Medical record updated',
                    'The medical record for ' . $appointment['pet_name'] . ' is available in your account.',
                    'client/medical_records.php',
                    'medical_record_updated_' . $appointmentId . '_' . date('YmdHis')
                );
                $pdo->commit();
                $_SESSION['vet_medical_flash'] = 'Medical record saved.';
                header('Location: medical.php?pet=' . (int) $appointment['pet_id'] . '&tab=records');
                exit;
            }

            if ($action === 'complete') {
                $appointmentId = vm_entity_id(posted_string('appointment_id'));
                if ($appointmentId < 1) {
                    throw new InvalidArgumentException('Choose a valid appointment.');
                }

                $pdo->beginTransaction();
                $appointmentStatement = $pdo->prepare(
                    'SELECT a.appointment_id, a.pet_id, a.status, p.owner_id
                     FROM Appointments a
                     JOIN Pets p ON p.pet_id = a.pet_id
                     WHERE a.appointment_id = ? AND a.vet_id = ?
                     FOR UPDATE'
                );
                $appointmentStatement->execute([$appointmentId, $vetId]);
                $appointment = $appointmentStatement->fetch();
                if (!$appointment) {
                    throw new InvalidArgumentException('This appointment is not assigned to you.');
                }
                $recordStatement = $pdo->prepare(
                    'SELECT 1 FROM MedicalRecords WHERE appointment_id = ? AND is_finalized = 1'
                );
                $recordStatement->execute([$appointmentId]);
                if (!$recordStatement->fetchColumn()) {
                    throw new InvalidArgumentException('Save and finalize the medical record before completing the consultation.');
                }

                $pdo->prepare(
                    "UPDATE Appointments SET status = 'Complete' WHERE appointment_id = ? AND vet_id = ?"
                )->execute([$appointmentId, $vetId]);
                role_log($pdo, $userId, 'appointment.completed', 'appointment', $appointmentId, 'Consultation completed');
                role_notify_role(
                    $pdo,
                    'staff',
                    'billing',
                    'Consultation complete',
                    'Appointment #' . $appointmentId . ' is ready for billing.',
                    'staff/billing.php',
                    'consult_complete_' . $appointmentId
                );
                role_notify_owner(
                    $pdo,
                    (int) $appointment['owner_id'],
                    'billing',
                    'Consultation complete',
                    'Your consultation for appointment #' . $appointmentId . ' is complete and ready for billing.',
                    'client/billing.php',
                    'consult_complete_owner_' . $appointmentId
                );
                role_notify_role(
                    $pdo,
                    'admin',
                    'billing',
                    'Consultation complete',
                    'Appointment #' . $appointmentId . ' is complete.',
                    'admin/appointments.php',
                    'consult_complete_' . $appointmentId
                );

                $pdo->commit();
                $_SESSION['vet_medical_flash'] = 'Consultation completed; billing can proceed.';
                header('Location: medical.php?pet=' . (int) $appointment['pet_id'] . '&tab=records');
                exit;
            }

            if ($action === 'save_vaccine') {
                $petId = vm_entity_id(posted_string('pet_id'));
                $appointmentValue = posted_string('appointment_id');
                $appointmentId = $appointmentValue === ''
                    ? 0
                    : filter_var($appointmentValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $name = trim(posted_string('vaccination_name'));
                $given = posted_string('vaccination_date');
                $due = posted_string('next_due_date');
                $notes = trim(posted_string('notes'));
                $givenDate = DateTimeImmutable::createFromFormat('!Y-m-d', $given);
                $dueDate = $due === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $due);

                if ($petId < 1 || $appointmentId === false || $name === '' || mb_strlen($name) > 100) {
                    throw new InvalidArgumentException('Choose an assigned pet and enter a valid vaccine name.');
                }
                if (mb_strlen($notes) > 255) {
                    throw new InvalidArgumentException('Vaccination notes must be 255 characters or fewer.');
                }
                if (!$givenDate || $givenDate->format('Y-m-d') !== $given || $givenDate > new DateTimeImmutable('today')) {
                    throw new InvalidArgumentException('Enter a valid vaccination date that is not in the future.');
                }
                if (
                    $due !== ''
                    && (!$dueDate || $dueDate->format('Y-m-d') !== $due || $dueDate < $givenDate)
                ) {
                    throw new InvalidArgumentException('The next due date must be valid and on or after the vaccination date.');
                }

                $pdo->beginTransaction();
                $petStatement = $pdo->prepare('SELECT owner_id, pet_name FROM Pets WHERE pet_id = ? FOR UPDATE');
                $petStatement->execute([$petId]);
                $pet = $petStatement->fetch();
                if (!$pet) {
                    throw new InvalidArgumentException('That pet is not available.');
                }

                if ((int) $appointmentId > 0) {
                    $appointmentStatement = $pdo->prepare(
                        "SELECT appointment_id
                         FROM Appointments
                         WHERE appointment_id = ? AND vet_id = ? AND pet_id = ?
                           AND status IN ('Scheduled', 'Check-in', 'In Consultation', 'Complete')
                         FOR UPDATE"
                    );
                    $appointmentStatement->execute([(int) $appointmentId, $vetId, $petId]);
                    if (!$appointmentStatement->fetchColumn()) {
                        throw new InvalidArgumentException('Choose an appointment assigned to you for this pet.');
                    }
                } else {
                    $assignmentStatement = $pdo->prepare(
                        'SELECT appointment_id
                         FROM Appointments
                         WHERE vet_id = ? AND pet_id = ?
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $assignmentStatement->execute([$vetId, $petId]);
                    if (!$assignmentStatement->fetchColumn()) {
                        throw new InvalidArgumentException('You can only record vaccinations for pets assigned to you.');
                    }
                }

                $pdo->prepare(
                    'INSERT INTO Vaccinations
                     (pet_id, appointment_id, administered_by_user_id, vaccination_name, vaccination_date, next_due_date, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $petId,
                    (int) $appointmentId > 0 ? (int) $appointmentId : null,
                    $userId,
                    $name,
                    $given,
                    $due !== '' ? $due : null,
                    $notes !== '' ? $notes : null,
                ]);
                $vaccinationId = (int) $pdo->lastInsertId();
                role_log(
                    $pdo,
                    $userId,
                    'vaccination.recorded',
                    'vaccination',
                    $vaccinationId,
                    $name . ' given to pet #' . $petId
                );
                role_notify_owner(
                    $pdo,
                    (int) $pet['owner_id'],
                    'medical',
                    'Vaccination recorded',
                    $name . ' was recorded for ' . $pet['pet_name'] . '.',
                    'client/vaccinations.php',
                    'vaccination_recorded_' . $vaccinationId
                );

                $pdo->commit();
                $_SESSION['vet_medical_flash'] = 'Vaccination record saved.';
                header('Location: medical.php?pet=' . $petId . '&tab=vaccines');
                exit;
            }

            throw new InvalidArgumentException('Choose a valid medical-record action.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'The requested medical update could not be saved.';
            error_log('Vet medical action: ' . $exception->getMessage());

            if ($action === 'save_vaccine' && vm_entity_id($_POST['pet_id'] ?? null) > 0) {
                $reopen = [
                    'type' => 'vaccine',
                    'pet_id' => vm_entity_id($_POST['pet_id'] ?? null),
                    'appointment_id' => posted_string('appointment_id'),
                    'vaccination_name' => posted_string('vaccination_name'),
                    'vaccination_date' => posted_string('vaccination_date'),
                    'next_due_date' => posted_string('next_due_date'),
                    'notes' => posted_string('notes'),
                ];
            } elseif ($action === 'save_record' && vm_entity_id($_POST['appointment_id'] ?? null) > 0) {
                $reopen = [
                    'type' => 'record',
                    'appointment_id' => vm_entity_id($_POST['appointment_id'] ?? null),
                    'symptoms' => posted_string('symptoms'),
                    'examination' => posted_string('examination'),
                    'diagnosis' => posted_string('diagnosis'),
                    'treatment' => posted_string('treatment'),
                    'notes' => posted_string('notes'),
                    'follow_up_date' => posted_string('follow_up_date'),
                ];
            }
        }
    }
}

role_render(
    'vet',
    ['medical'],
    'Patients & Medical Records',
    'Consultation history and vaccinations for pets assigned to you',
    function (array $context) use ($pdo, $vetId, $error, $flash, $reopen, $requestedPetId, $requestedAppointmentId, $requestedTab): void {
        $patientStatement = $pdo->prepare(
            "SELECT p.pet_id, p.pet_name, p.species, p.breed, p.gender, p.birth_date, p.photo_updated_at,
                    o.full_name AS owner_name,
                    COUNT(CASE WHEN a.status IN ('Check-in', 'In Consultation', 'Complete') THEN 1 END) AS visit_count,
                    MAX(CASE WHEN a.status IN ('Check-in', 'In Consultation', 'Complete') THEN a.appointment_date END) AS last_visit,
                    SUM(a.status IN ('Check-in', 'In Consultation')) AS active_visits
             FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id
             JOIN PetOwners o ON o.owner_id = p.owner_id
             WHERE a.vet_id = ?
             GROUP BY p.pet_id, p.pet_name, p.species, p.breed, p.gender, p.birth_date,
                      p.photo_updated_at, o.full_name
             ORDER BY active_visits DESC, last_visit DESC, p.pet_name"
        );
        $patientStatement->execute([$vetId]);
        $patients = $patientStatement->fetchAll();

        $visitsByPet = [];
        $petByAppointment = [];
        $visitStatement = $pdo->prepare(
            "SELECT a.appointment_id, a.pet_id, a.appointment_date, a.status,
                    m.symptoms, m.examination, m.diagnosis, m.treatment, m.recommendations,
                    m.follow_up_date, m.is_finalized
             FROM Appointments a
             LEFT JOIN MedicalRecords m ON m.appointment_id = a.appointment_id
             WHERE a.vet_id = ? AND a.status IN ('Check-in', 'In Consultation', 'Complete')
             ORDER BY a.appointment_date DESC"
        );
        $visitStatement->execute([$vetId]);
        foreach ($visitStatement->fetchAll() as $visit) {
            $petId = (int) $visit['pet_id'];
            $visitsByPet[$petId][] = $visit;
            $petByAppointment[(int) $visit['appointment_id']] = $petId;
        }

        $appointmentsStatement = $pdo->prepare(
            "SELECT a.appointment_id, a.appointment_date, a.pet_id, p.pet_name
             FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id
             WHERE a.vet_id = ?
               AND a.status IN ('Scheduled', 'Check-in', 'In Consultation', 'Complete')
             ORDER BY a.appointment_date DESC"
        );
        $appointmentsStatement->execute([$vetId]);
        $appointments = $appointmentsStatement->fetchAll();

        $vaccinesByPet = [];
        $vaccineLoadError = false;
        if ($patients) {
            $petIds = array_map(static function (array $patient): int {
                return (int) $patient['pet_id'];
            }, $patients);
            $placeholders = implode(',', array_fill(0, count($petIds), '?'));
            try {
                $vaccineStatement = $pdo->prepare(
                    'SELECT vaccination_id, pet_id, vaccination_name, vaccination_date, next_due_date, notes
                     FROM Vaccinations
                     WHERE pet_id IN (' . $placeholders . ')
                     ORDER BY vaccination_date DESC'
                );
                $vaccineStatement->execute($petIds);
                foreach ($vaccineStatement->fetchAll() as $vaccine) {
                    $vaccinesByPet[(int) $vaccine['pet_id']][] = $vaccine;
                }
            } catch (Throwable $exception) {
                error_log('Vet medical vaccination list: ' . $exception->getMessage());
                $vaccineLoadError = true;
            }
        }

        $today = new DateTimeImmutable('today');
        $dueSoon = $today->modify('+30 days');
        $recordJson = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        ?>
        <style>
          .vm-toolbar{display:flex;align-items:end;justify-content:space-between;gap:14px;margin:0 0 16px}
          .vm-search{width:min(420px,100%);margin:0}
          .vm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,270px),1fr));gap:16px}
          .vm-card{min-width:0;padding:18px;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(13,54,86,.06)}
          .vm-card-head{display:flex;align-items:center;gap:13px;min-width:0}
          .vm-photo{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;overflow:hidden;border-radius:16px;background:var(--blue-50);color:var(--blue-700);font-size:1.15rem;font-weight:800}
          .vm-photo img{width:100%;height:100%;object-fit:cover}
          .vm-card h3{margin:0;color:var(--blue-900);font-size:1.03rem}
          .vm-subtitle{display:block;margin-top:3px;color:var(--muted);font-size:.82rem}
          .vm-card-facts{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:16px 0}
          .vm-card-facts div{min-width:0;padding:10px 11px;border-radius:11px;background:#f5f9fc}
          .vm-card-facts dt{color:var(--muted);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
          .vm-card-facts dd{margin:4px 0 0;color:var(--blue-900);font-size:.87rem;font-weight:700;overflow-wrap:anywhere}
          .vm-card-actions{display:flex;flex-wrap:wrap;gap:8px}
          .vm-card-actions .button{flex:1 1 auto}
          .vm-modal{width:min(900px,calc(100vw - 28px));max-width:900px;max-height:min(88vh,900px);padding:0;border:1px solid var(--line);border-radius:18px;box-shadow:0 22px 70px rgba(3,34,57,.28);color:var(--ink)}
          .vm-modal::backdrop{background:rgba(6,30,49,.55);backdrop-filter:blur(2px)}
          .vm-modal-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 22px;background:#fff;border-bottom:1px solid var(--line)}
          .vm-modal-head h2{margin:0;color:var(--blue-900);font-size:1.15rem}
          .vm-modal-head p{margin:4px 0 0;color:var(--muted);font-size:.82rem}
          .vm-modal-body{padding:18px 22px 22px}
          .vm-tabs{display:flex;gap:8px;margin:0 0 16px;padding:4px;border-radius:12px;background:#f1f6fa}
          .vm-tab{flex:1;padding:10px;border:0;border-radius:9px;background:transparent;color:var(--muted);font:inherit;font-weight:700;cursor:pointer}
          .vm-tab[aria-selected=true]{background:#fff;color:var(--blue-700);box-shadow:0 2px 8px rgba(13,54,86,.1)}
          .vm-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px}
          .vm-section-title h3{margin:0;color:var(--blue-900);font-size:1rem}
          .vm-list{display:grid;gap:10px}
          .vm-visit{padding:14px;border:1px solid var(--line);border-radius:12px;background:#fff}
          .vm-visit-top{display:flex;align-items:center;justify-content:space-between;gap:12px}
          .vm-visit-top strong{color:var(--blue-900)}
          .vm-visit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;margin:12px 0 0}
          .vm-visit-grid dt{color:var(--muted);font-size:.72rem;font-weight:700}
          .vm-visit-grid dd{margin:3px 0 0;overflow-wrap:anywhere}
          .vm-empty{padding:26px 18px;border:1px dashed #c5dbea;border-radius:12px;text-align:center;color:var(--muted)}
          .vm-empty strong{display:block;margin-bottom:4px;color:var(--blue-900)}
          .vm-table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:12px}
          .vm-vaccine-table{width:100%;border-collapse:collapse;table-layout:fixed}
          .vm-vaccine-table th,.vm-vaccine-table td{padding:11px 12px;border-bottom:1px solid #e8f0f6;text-align:left;vertical-align:top;overflow-wrap:anywhere}
          .vm-vaccine-table th{background:#f5f9fc;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}
          .vm-vaccine-table tr:last-child td{border-bottom:0}
          .vm-status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:.72rem;font-weight:800;white-space:nowrap}
          .vm-status.overdue{background:#fff0f0;color:#a93232}.vm-status.soon{background:#fff6e5;color:#8a5900}.vm-status.ok{background:#e8f6ef;color:#226343}
          .vm-dialog-form{width:min(600px,calc(100vw - 28px));}
          .vm-dialog-form .form-grid{padding:18px}
          .vm-dialog-form .actions{padding:0 18px 18px}
          .vm-error{margin:14px 22px 0}
          @media(max-width:680px){
            .vm-toolbar{align-items:stretch;flex-direction:column}
            .vm-modal-head,.vm-modal-body{padding-left:15px;padding-right:15px}
            .vm-visit-grid{grid-template-columns:1fr}
            .vm-vaccine-table th,.vm-vaccine-table td{padding:9px 8px;font-size:.78rem}
          }
        </style>

        <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($flash !== ''): ?><div class="notice success" role="status"><?= e($flash) ?></div><?php endif; ?>

        <section class="panel">
          <div class="panel-header">
            <div><h2>Your patients</h2><p class="hint">Open a patient to review their medical records and vaccination history.</p></div>
            <span class="data-count"><?= count($patients) ?> patients</span>
          </div>
          <div class="panel-body">
            <div class="vm-toolbar">
              <label class="field vm-search">Search patients
                <input type="search" id="vm-search" placeholder="Pet, owner or species" autocomplete="off">
              </label>
              <span class="table-search-count" id="vm-search-count" aria-live="polite"></span>
            </div>
            <?php if ($patients): ?>
              <div class="vm-grid" id="vm-patient-grid">
                <?php foreach ($patients as $patient): ?>
                  <?php
                    $petId = (int) $patient['pet_id'];
                    $photo = pet_photo_url($patient['photo_updated_at'] ?? null, $petId);
                    $initial = mb_strtoupper(mb_substr((string) $patient['pet_name'], 0, 1));
                    $petDescription = trim(
                        (string) $patient['species']
                        . (!empty($patient['breed']) ? ' · ' . $patient['breed'] : '')
                        . (!empty($patient['gender']) ? ' · ' . $patient['gender'] : '')
                    );
                    $searchText = mb_strtolower(
                        (string) $patient['pet_name'] . ' ' . (string) $patient['owner_name'] . ' '
                        . (string) $patient['species'] . ' ' . (string) $patient['breed']
                    );
                    $vaccineRows = $vaccinesByPet[$petId] ?? [];
                  ?>
                  <article class="vm-card" data-patient-card data-search="<?= e($searchText) ?>">
                    <div class="vm-card-head">
                      <span class="vm-photo" aria-hidden="true"><?php if ($photo !== ''): ?><img src="<?= e($photo) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><?= e($initial) ?><?php endif; ?></span>
                      <div><h3><?= e($patient['pet_name']) ?></h3><span class="vm-subtitle"><?= e($petDescription) ?></span></div>
                    </div>
                    <dl class="vm-card-facts">
                      <div><dt>Owner</dt><dd><?= e($patient['owner_name']) ?></dd></div>
                      <div><dt>Age</dt><dd><?= e(vm_age($patient['birth_date'] ?? null)) ?></dd></div>
                      <div><dt>Visits</dt><dd><?= (int) $patient['visit_count'] ?></dd></div>
                      <div><dt>Last visit</dt><dd><?= e(vm_date($patient['last_visit'] ?? null)) ?></dd></div>
                    </dl>
                    <div class="vm-card-actions">
                      <button class="button small vm-open-patient" type="button" data-pet="<?= $petId ?>" data-pet-name="<?= e($patient['pet_name']) ?>" data-owner="<?= e($patient['owner_name']) ?>" data-photo="<?= e($photo) ?>" data-description="<?= e($petDescription) ?>">View records</button>
                      <button class="button secondary small vm-open-patient" type="button" data-pet="<?= $petId ?>" data-pet-name="<?= e($patient['pet_name']) ?>" data-owner="<?= e($patient['owner_name']) ?>" data-photo="<?= e($photo) ?>" data-description="<?= e($petDescription) ?>" data-tab="vaccines">Vaccinations</button>
                    </div>
                    <template id="vm-patient-<?= $petId ?>">
                      <section class="vm-pane" data-pane="records">
                        <div class="vm-section-title"><h3>Consultation history</h3><?php if (!empty($visitsByPet[$petId])): ?><span class="data-count"><?= count($visitsByPet[$petId]) ?> visits</span><?php endif; ?></div>
                        <?php if (!empty($visitsByPet[$petId])): ?>
                          <div class="vm-list">
                            <?php foreach ($visitsByPet[$petId] as $visit): ?>
                              <article class="vm-visit">
                                <div class="vm-visit-top">
                                  <strong><?= e(vm_date($visit['appointment_date'], 'D, M j, Y · g:i A')) ?></strong>
                                  <span class="status <?= e(role_status_class($visit['status'])) ?>"><?= e($visit['status']) ?></span>
                                </div>
                                <dl class="vm-visit-grid">
                                  <div><dt>Diagnosis</dt><dd><?= e($visit['diagnosis'] ?: 'Not recorded') ?></dd></div>
                                  <div><dt>Treatment</dt><dd><?= e($visit['treatment'] ?: 'Not recorded') ?></dd></div>
                                  <div><dt>Symptoms</dt><dd><?= e($visit['symptoms'] ?: 'Not recorded') ?></dd></div>
                                  <div><dt>Examination</dt><dd><?= e($visit['examination'] ?: 'Not recorded') ?></dd></div>
                                  <div><dt>Recommendations</dt><dd><?= e($visit['recommendations'] ?: 'Not recorded') ?></dd></div>
                                  <div><dt>Follow-up</dt><dd><?= e(vm_date($visit['follow_up_date'] ?? null)) ?></dd></div>
                                </dl>
                                <?php if ($visit['status'] !== 'Complete'): ?>
                                  <div class="actions" style="margin-top:12px">
                                    <button
                                      class="button small vm-record-btn"
                                      type="button"
                                      data-id="<?= (int) $visit['appointment_id'] ?>"
                                      data-symptoms="<?= e($visit['symptoms'] ?: '') ?>"
                                      data-examination="<?= e($visit['examination'] ?: '') ?>"
                                      data-diagnosis="<?= e($visit['diagnosis'] ?: '') ?>"
                                      data-treatment="<?= e($visit['treatment'] ?: '') ?>"
                                      data-notes="<?= e($visit['recommendations'] ?: '') ?>"
                                      data-follow-up="<?= e($visit['follow_up_date'] ?: '') ?>"
                                      data-finalized="<?= (int) $visit['is_finalized'] ?>"
                                    ><?= !empty($visit['is_finalized']) ? 'Edit findings' : 'Record findings' ?></button>
                                  </div>
                                <?php endif; ?>
                              </article>
                            <?php endforeach; ?>
                          </div>
                        <?php else: ?>
                          <div class="vm-empty"><strong>No consultation records yet</strong>Medical records will appear here after a consultation is documented.</div>
                        <?php endif; ?>
                      </section>
                      <section class="vm-pane" data-pane="vaccines" hidden>
                        <div class="vm-section-title">
                          <h3>Vaccination history</h3>
                          <button class="button small vm-add-vaccine" type="button" data-pet="<?= $petId ?>">+ Record vaccination</button>
                        </div>
                        <?php if ($vaccineLoadError): ?>
                          <div class="notice error">Vaccination history could not be loaded. Please try again later.</div>
                        <?php elseif ($vaccineRows): ?>
                          <div class="vm-table-wrap">
                            <table class="vm-vaccine-table">
                              <thead><tr><th>Vaccine</th><th>Date given</th><th>Next due</th><th>Status</th><th>Notes</th></tr></thead>
                              <tbody>
                                <?php foreach ($vaccineRows as $vaccine): ?>
                                  <?php
                                    $vaccineDue = $vaccine['next_due_date'] ?: null;
                                    $vaccineStatus = 'Up to date';
                                    $vaccineClass = 'ok';
                                    if ($vaccineDue !== null && new DateTimeImmutable($vaccineDue) < $today) {
                                        $vaccineStatus = 'Overdue';
                                        $vaccineClass = 'overdue';
                                    } elseif ($vaccineDue !== null && new DateTimeImmutable($vaccineDue) <= $dueSoon) {
                                        $vaccineStatus = 'Due soon';
                                        $vaccineClass = 'soon';
                                    }
                                  ?>
                                  <tr>
                                    <td data-label="Vaccine"><strong><?= e($vaccine['vaccination_name']) ?></strong></td>
                                    <td data-label="Date given"><?= e(vm_date($vaccine['vaccination_date'])) ?></td>
                                    <td data-label="Next due"><?= e(vm_date($vaccineDue)) ?></td>
                                    <td data-label="Status"><span class="vm-status <?= e($vaccineClass) ?>"><?= e($vaccineStatus) ?></span></td>
                                    <td data-label="Notes"><?= e($vaccine['notes'] ?: '—') ?></td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        <?php else: ?>
                          <div class="vm-empty"><strong>No vaccination records yet</strong>Record a vaccine to keep this pet's care history up to date.</div>
                        <?php endif; ?>
                      </section>
                    </template>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="vm-empty"><strong>No assigned patients yet</strong>Patients will appear here when appointments are assigned to you.</div>
            <?php endif; ?>
            <div class="vm-empty" id="vm-no-results" hidden><strong>No matching patients</strong>Try a different pet, owner or species name.</div>
          </div>
        </section>

        <dialog class="vm-modal" id="vm-patient-dialog" aria-labelledby="vm-dialog-title">
          <div class="vm-modal-head">
            <div class="vm-card-head">
              <span class="vm-photo" id="vm-dialog-photo" aria-hidden="true"></span>
              <div><h2 id="vm-dialog-title">Patient records</h2><p id="vm-dialog-subtitle"></p></div>
            </div>
            <button class="button secondary small" type="button" data-close-patient>Close</button>
          </div>
          <div class="vm-modal-body">
            <div class="vm-tabs" role="tablist" aria-label="Patient information">
              <button class="vm-tab" type="button" role="tab" data-tab="records" aria-selected="true">Medical records</button>
              <button class="vm-tab" type="button" role="tab" data-tab="vaccines" aria-selected="false">Vaccinations</button>
            </div>
            <div id="vm-patient-content"></div>
          </div>
        </dialog>

        <dialog class="vm-modal vm-dialog-form" id="vm-record-dialog" aria-labelledby="vm-record-title">
          <div class="vm-modal-head"><h2 id="vm-record-title">Medical record</h2><button class="button secondary small" type="button" data-close-record>Close</button></div>
          <?php if ($error !== '' && ($reopen['type'] ?? '') === 'record'): ?><div class="notice error vm-error" role="alert"><?= e($error) ?></div><?php endif; ?>
          <form method="post" id="vm-record-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="appointment_id" id="vm-record-id">
            <input type="hidden" name="action" value="save_record">
            <div class="form-grid">
              <label class="field full">Symptoms<textarea name="symptoms" id="vm-record-symptoms" maxlength="6000"></textarea></label>
              <label class="field full">Examination findings<textarea name="examination" id="vm-record-examination" maxlength="6000"></textarea></label>
              <label class="field full">Diagnosis<textarea name="diagnosis" id="vm-record-diagnosis" required maxlength="6000"></textarea></label>
              <label class="field full">Treatment<textarea name="treatment" id="vm-record-treatment" maxlength="6000"></textarea></label>
              <label class="field full">Recommendations<textarea name="notes" id="vm-record-notes" maxlength="6000"></textarea></label>
              <label class="field full">Follow-up date<input type="date" name="follow_up_date" id="vm-record-follow-up"></label>
            </div>
            <div class="actions"><button class="button" type="submit">Save and finalize record</button></div>
          </form>
          <form method="post" id="vm-complete-form" class="dialog-form" style="margin:0 18px 18px;padding-top:14px;border-top:1px solid var(--line)">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="appointment_id" id="vm-complete-id">
            <input type="hidden" name="action" value="complete">
            <button class="button secondary" type="submit">Complete consultation</button>
          </form>
        </dialog>

        <dialog class="vm-modal vm-dialog-form" id="vm-vaccine-dialog" aria-labelledby="vm-vaccine-title">
          <div class="vm-modal-head"><div><h2 id="vm-vaccine-title">Record vaccination</h2><p id="vm-vaccine-subtitle"></p></div><button class="button secondary small" type="button" data-close-vaccine>Close</button></div>
          <?php if ($error !== '' && ($reopen['type'] ?? '') === 'vaccine'): ?><div class="notice error vm-error" role="alert"><?= e($error) ?></div><?php endif; ?>
          <form method="post" id="vm-vaccine-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_vaccine">
            <input type="hidden" name="pet_id" id="vm-vaccine-pet">
            <div class="form-grid">
              <label class="field full">Related appointment (optional)
                <select name="appointment_id" id="vm-vaccine-appointment">
                  <option value="0">No appointment</option>
                  <?php foreach ($appointments as $appointment): ?>
                    <option value="<?= (int) $appointment['appointment_id'] ?>" data-pet-id="<?= (int) $appointment['pet_id'] ?>">
                      <?= e($appointment['pet_name'] . ' · ' . vm_date($appointment['appointment_date'], 'M j, Y')) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="field full">Vaccine name<input name="vaccination_name" id="vm-vaccine-name" maxlength="100" required></label>
              <label class="field">Date given<input type="date" name="vaccination_date" id="vm-vaccine-date" max="<?= date('Y-m-d') ?>" required></label>
              <label class="field">Next due date<input type="date" name="next_due_date" id="vm-vaccine-due"></label>
              <label class="field full">Notes<textarea name="notes" id="vm-vaccine-notes" maxlength="255"></textarea></label>
            </div>
            <div class="actions"><button class="button secondary" type="button" data-close-vaccine>Cancel</button><button class="button" type="submit">Save vaccination</button></div>
          </form>
        </dialog>

        <?php
        $initial = $reopen;
        if ($initial !== null && $initial['type'] === 'record' && empty($initial['pet_id'])) {
            $initial['pet_id'] = $petByAppointment[(int) $initial['appointment_id']] ?? 0;
        }
        if ($initial === null && $requestedAppointmentId > 0 && isset($petByAppointment[$requestedAppointmentId])) {
            $initial = [
                'type' => 'record-open',
                'pet_id' => $petByAppointment[$requestedAppointmentId],
                'appointment_id' => $requestedAppointmentId,
            ];
        } elseif ($initial === null && $requestedPetId > 0) {
            $patientIds = array_map(static function (array $patient): int {
                return (int) $patient['pet_id'];
            }, $patients);
            if (in_array($requestedPetId, $patientIds, true)) {
                $initial = ['type' => 'patient', 'pet_id' => $requestedPetId, 'tab' => $requestedTab];
            }
        }
        ?>
        <script>
          (function () {
            const patientDialog = document.getElementById('vm-patient-dialog');
            const recordDialog = document.getElementById('vm-record-dialog');
            const vaccineDialog = document.getElementById('vm-vaccine-dialog');
            const content = document.getElementById('vm-patient-content');
            const search = document.getElementById('vm-search');
            const patientCards = Array.from(document.querySelectorAll('[data-patient-card]'));
            const recordForm = document.getElementById('vm-record-form');
            const recordFields = Array.from(recordForm.querySelectorAll('textarea,input:not([type="hidden"])'));
            const completeForm = document.getElementById('vm-complete-form');
            const vaccineForm = document.getElementById('vm-vaccine-form');
            const appointmentSelect = document.getElementById('vm-vaccine-appointment');
            let recordValues = [];
            let vaccineDirty = false;
            let currentPetId = 0;
            let currentPetName = '';

            function setTab(tab) {
              document.querySelectorAll('.vm-tab').forEach(function (button) {
                button.setAttribute('aria-selected', button.dataset.tab === tab ? 'true' : 'false');
              });
              content.querySelectorAll('[data-pane]').forEach(function (pane) {
                pane.hidden = pane.dataset.pane !== tab;
              });
            }

            function openPatient(button, tab) {
              currentPetId = Number(button.dataset.pet);
              currentPetName = button.dataset.petName;
              const template = document.getElementById('vm-patient-' + currentPetId);
              if (!template) return;
              document.getElementById('vm-dialog-title').textContent = button.dataset.petName;
              document.getElementById('vm-dialog-subtitle').textContent =
                [button.dataset.description, 'Owner: ' + button.dataset.owner].filter(Boolean).join(' · ');
              const photo = document.getElementById('vm-dialog-photo');
              if (button.dataset.photo) {
                photo.innerHTML = '';
                const image = document.createElement('img');
                image.src = button.dataset.photo;
                image.alt = '';
                image.loading = 'lazy';
                photo.appendChild(image);
              } else {
                photo.textContent = button.dataset.petName.trim().charAt(0).toUpperCase();
              }
              content.replaceChildren(template.content.cloneNode(true));
              setTab(tab || button.dataset.tab || 'records');
              if (!patientDialog.open) patientDialog.showModal();
            }

            function recordChanged() {
              return recordFields.some(function (field, index) {
                return field.value !== recordValues[index];
              });
            }

            function closeRecord() {
              if (recordChanged() && !window.confirm('Discard your unsaved medical record changes?')) return;
              recordDialog.close();
            }

            function openRecord(button, values) {
              const data = values || button.dataset;
              document.getElementById('vm-record-id').value = data.id || data.appointment_id || '';
              document.getElementById('vm-complete-id').value = data.id || data.appointment_id || '';
              document.getElementById('vm-record-symptoms').value = data.symptoms || '';
              document.getElementById('vm-record-examination').value = data.examination || '';
              document.getElementById('vm-record-diagnosis').value = data.diagnosis || '';
              document.getElementById('vm-record-treatment').value = data.treatment || '';
              document.getElementById('vm-record-notes').value = data.notes || '';
              document.getElementById('vm-record-follow-up').value = data.followUp || data.follow_up_date || '';
              recordValues = recordFields.map(function (field) { return field.value; });
              if (values) recordValues = recordFields.map(function () { return ''; });
              recordDialog.showModal();
            }

            function filterAppointments() {
              const petId = document.getElementById('vm-vaccine-pet').value;
              Array.from(appointmentSelect.options).forEach(function (option) {
                if (option.value === '0') return;
                option.hidden = option.dataset.petId !== petId;
              });
              const selected = appointmentSelect.selectedOptions[0];
              if (selected && selected.value !== '0' && selected.dataset.petId !== petId) {
                appointmentSelect.value = '0';
              }
            }

            function openVaccine(petId, values) {
              vaccineForm.reset();
              document.getElementById('vm-vaccine-pet').value = String(petId || currentPetId);
              document.getElementById('vm-vaccine-subtitle').textContent = currentPetName ? 'For ' + currentPetName : '';
              filterAppointments();
              if (values) {
                appointmentSelect.value = values.appointment_id || '0';
                document.getElementById('vm-vaccine-name').value = values.vaccination_name || '';
                document.getElementById('vm-vaccine-date').value = values.vaccination_date || '';
                document.getElementById('vm-vaccine-due').value = values.next_due_date || '';
                document.getElementById('vm-vaccine-notes').value = values.notes || '';
                filterAppointments();
              }
              vaccineDirty = Boolean(values && serverError);
              vaccineDialog.showModal();
            }

            function closeVaccine() {
              if (vaccineDirty && !window.confirm('Discard your unsaved vaccination details?')) return;
              vaccineDialog.close();
              vaccineForm.reset();
              vaccineDirty = false;
            }

            if (search) {
              search.addEventListener('input', function () {
                const query = search.value.trim().toLocaleLowerCase();
                let visible = 0;
                patientCards.forEach(function (card) {
                  const matches = !query || card.dataset.search.indexOf(query) !== -1;
                  card.hidden = !matches;
                  if (matches) visible++;
                });
                document.getElementById('vm-no-results').hidden = visible !== 0;
                document.getElementById('vm-search-count').textContent = query ? visible + ' matching patients' : '';
              });
            }

            document.addEventListener('click', function (event) {
              const target = event.target;
              const patientButton = target.closest('.vm-open-patient');
              if (patientButton) {
                openPatient(patientButton, patientButton.dataset.tab);
                return;
              }
              if (target.closest('[data-close-patient]')) {
                patientDialog.close();
                return;
              }
              const tabButton = target.closest('.vm-tab');
              if (tabButton) {
                setTab(tabButton.dataset.tab);
                return;
              }
              const recordButton = target.closest('.vm-record-btn');
              if (recordButton) {
                openRecord(recordButton, null);
                return;
              }
              if (target.closest('[data-close-record]')) {
                closeRecord();
                return;
              }
              const vaccineButton = target.closest('.vm-add-vaccine');
              if (vaccineButton) {
                openVaccine(vaccineButton.dataset.pet);
                return;
              }
              if (target.closest('[data-close-vaccine]')) closeVaccine();
            });

            recordDialog.addEventListener('cancel', function (event) {
              if (recordChanged() && !window.confirm('Discard your unsaved medical record changes?')) event.preventDefault();
            });
            completeForm.addEventListener('submit', function (event) {
              if (recordChanged() && !window.confirm('Complete this consultation without saving your unsaved medical record changes?')) event.preventDefault();
            });
            vaccineForm.addEventListener('input', function () { vaccineDirty = true; });
            vaccineForm.addEventListener('change', function () { vaccineDirty = true; });
            vaccineForm.addEventListener('submit', function () {
              vaccineDirty = false;
              vaccineForm.querySelector('button[type="submit"]').disabled = true;
            });
            vaccineDialog.addEventListener('cancel', function (event) {
              if (vaccineDirty && !window.confirm('Discard your unsaved vaccination details?')) event.preventDefault();
              else vaccineDirty = false;
            });
            appointmentSelect.addEventListener('change', filterAppointments);

            const initial = <?= json_encode($initial, $recordJson) ?: 'null' ?>;
            const serverError = <?= json_encode($error, $recordJson) ?: '""' ?>;
            if (initial) {
              const card = patientCards.find(function (item) {
                return Number(item.querySelector('.vm-open-patient').dataset.pet) === Number(initial.pet_id);
              });
              const button = card && card.querySelector('.vm-open-patient');
              if (button) {
                openPatient(button, initial.tab || (initial.type === 'vaccine' ? 'vaccines' : 'records'));
                if (initial.type === 'record') {
                  openRecord(null, initial);
                  if (serverError) recordValues = recordFields.map(function () { return ''; });
                } else if (initial.type === 'vaccine') {
                  openVaccine(initial.pet_id, initial);
                } else if (initial.type === 'record-open') {
                  const recordButton = content.querySelector('.vm-record-btn[data-id="' + initial.appointment_id + '"]');
                  if (recordButton) openRecord(recordButton, null);
                }
              }
            }
          }());
        </script>
        <?php
    }
);
