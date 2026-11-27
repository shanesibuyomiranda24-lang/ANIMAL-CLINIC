<?php
require_once __DIR__ . '/../includes/role_layout.php';
if (is_file(__DIR__ . '/../includes/pet_photos.php')) {
    require_once __DIR__ . '/../includes/pet_photos.php';   // pet_photo_url() for the small pet avatars
}
require_role(['staff']);
$pdo = db();
$error = $success = '';
$isDashboardAjax = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (
        (string) ($_GET['dashboard_action'] ?? '') === '1'
        || posted_string('dashboard_ajax') === '1'
        || (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
    );

const STAFF_AP_STATUSES = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation', 'Complete', 'Cancelled', 'Rejected'];

function staff_ap_date($value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
}

function staff_appointment_filters(array $query): array
{
    $statusRaw = $query['status'] ?? '';
    $status = is_string($statusRaw) && in_array($statusRaw, STAFF_AP_STATUSES, true)
        ? $statusRaw
        : (is_string($statusRaw) && $statusRaw === 'in-clinic' ? 'in-clinic' : '');
    $dateRaw = $query['date'] ?? '';
    $dateFilter = is_string($dateRaw) && in_array($dateRaw, ['today', 'upcoming', 'past'], true) ? $dateRaw : '';
    $qRaw = $query['q'] ?? '';
    $q = is_string($qRaw) ? trim(mb_substr($qRaw, 0, 80)) : '';
    $sort = ($query['sort'] ?? '') === 'oldest' ? 'oldest' : 'newest';
    $idRaw = filter_var($query['appointment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $appointmentId = $idRaw === false || $idRaw === null ? 0 : (int) $idRaw;
    $vet = '';
    $vetRaw = $query['vet'] ?? '';
    if (is_string($vetRaw) && $vetRaw === 'none') {
        $vet = 'none';
    } elseif (is_string($vetRaw) && $vetRaw !== '') {
        $vetId = filter_var($vetRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $vet = $vetId === false ? '' : (string) $vetId;
    }

    $where = 'WHERE 1=1';
    $params = [];
    if ($status !== '') {
        if ($status === 'in-clinic') {
            $where .= " AND a.status IN ('Waiting','Check-in','In Consultation')";
        } else {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
    }
    if ($vet === 'none') {
        $where .= ' AND a.vet_id IS NULL';
    } elseif ($vet !== '') {
        $where .= ' AND a.vet_id = ?';
        $params[] = (int) $vet;
    }
    if ($dateFilter === 'today') {
        $where .= ' AND a.appointment_date >= CURRENT_DATE AND a.appointment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)';
    } elseif ($dateFilter === 'upcoming') {
        $where .= ' AND a.appointment_date >= DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)';
    } elseif ($dateFilter === 'past') {
        $where .= ' AND a.appointment_date < CURRENT_DATE';
    }
    if ($appointmentId > 0) {
        $where .= ' AND a.appointment_id = ?';
        $params[] = $appointmentId;
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where .= ' AND (CAST(a.appointment_id AS CHAR) = ? OR p.pet_name LIKE ? OR o.full_name LIKE ? OR v.full_name LIKE ?
                    OR EXISTS (SELECT 1 FROM AppointmentServices xs JOIN Services sx ON sx.service_id = xs.service_id
                               WHERE xs.appointment_id = a.appointment_id AND sx.service_name LIKE ?))';
        array_push($params, ltrim($q, '#'), $like, $like, $like, $like);
    }

    return compact('status', 'dateFilter', 'q', 'sort', 'appointmentId', 'vet', 'where', 'params');
}

function staff_assert_appointment_available(PDO $pdo, array $appointment): void
{
    $vetId = (int) ($appointment['vet_id'] ?? 0);
    if ($vetId < 1) {
    $groomingServices = $pdo->prepare(
      "SELECT COUNT(DISTINCT aps.service_id) AS service_count,
          COUNT(DISTINCT CASE WHEN s.is_active = 1 AND s.service_name IN (
            'Ear Cleaning & Flea Check',
            'Full Grooming (Bath, Haircut, Nail Trim, Ear Cleaning)',
            'Nail Trimming'
          ) AND aps.groomer_id IS NOT NULL THEN aps.service_id END) AS grooming_count,
          COUNT(DISTINCT aps.groomer_id) AS groomer_count,
          MIN(aps.groomer_id) AS groomer_id,
          MAX(aps.groomer_id) AS max_groomer_id
       FROM AppointmentServices aps
       JOIN Services s ON s.service_id = aps.service_id
       WHERE aps.appointment_id = ?"
    );
    $groomingServices->execute([(int) $appointment['appointment_id']]);
    $grooming = $groomingServices->fetch();
    if ($grooming && (int) $grooming['service_count'] > 0) {
      if ((int) $grooming['service_count'] !== (int) $grooming['grooming_count']
        || (int) $grooming['groomer_count'] !== 1
        || (int) $grooming['groomer_id'] !== (int) $grooming['max_groomer_id']) {
        throw new InvalidArgumentException('Assign one available groomer to every grooming service before approving this request.');
      }
      $groomerId = (int) $grooming['groomer_id'];
      $groomerStatement = $pdo->prepare(
        "SELECT g.full_name
         FROM Groomers g
         JOIN Users u ON u.user_id = g.user_id AND u.status = 'active'
         JOIN Staff st ON st.staff_id = u.staff_id
           AND (st.can_manage_groomer_schedules = 1 OR st.`role` = 'Groomer')
         WHERE g.groomer_id = ? AND g.is_available = 1 FOR UPDATE"
      );
      $groomerStatement->execute([$groomerId]);
      if (!$groomerStatement->fetchColumn()) {
        throw new InvalidArgumentException('The assigned groomer is unavailable or does not have Admin-granted schedule access.');
      }

      $visitAt = new DateTimeImmutable((string) $appointment['appointment_date']);
      if ($visitAt <= new DateTimeImmutable('now')) {
        throw new InvalidArgumentException('This appointment time has passed and cannot be approved.');
      }
      $duration = (int) $appointment['duration_minutes'];
      if ($duration < 1) {
        throw new InvalidArgumentException('The appointment duration is invalid.');
      }
      $visitEnd = $visitAt->modify('+' . $duration . ' minutes');
      $date = $visitAt->format('Y-m-d');
      $dayOfWeek = (int) $visitAt->format('N');
      $scheduleStatement = $pdo->prepare(
        'SELECT start_time, end_time FROM GroomerSchedules WHERE groomer_id = ? AND day_of_week = ? FOR UPDATE'
      );
      $scheduleStatement->execute([$groomerId, $dayOfWeek]);
      $schedule = $scheduleStatement->fetch();
      if (!$schedule) {
        throw new InvalidArgumentException('The assigned groomer has no Admin-approved working hours on that day.');
      }
      $workStart = new DateTimeImmutable($date . ' ' . $schedule['start_time']);
      $workEnd = new DateTimeImmutable($date . ' ' . $schedule['end_time']);
      if ($visitAt < $workStart || $visitEnd > $workEnd) {
        throw new InvalidArgumentException('The grooming appointment falls outside the assigned groomer’s approved working hours.');
      }
      $holidayStatement = $pdo->prepare('SELECT holiday_name FROM ClinicHolidays WHERE holiday_date = ? LIMIT 1');
      $holidayStatement->execute([$date]);
      if ($holidayStatement->fetchColumn()) {
        throw new InvalidArgumentException('The clinic is closed on that date.');
      }
      $leaveStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM GroomerLeaves
         WHERE groomer_id = ? AND status = 'Approved' AND start_date <= ? AND end_date >= ?"
      );
      $leaveStatement->execute([$groomerId, $date, $date]);
      if ((int) $leaveStatement->fetchColumn() > 0) {
        throw new InvalidArgumentException('The assigned groomer is on approved leave on that date.');
      }
      $breakStatement = $pdo->prepare(
        'SELECT start_time, end_time FROM GroomerScheduleBreaks WHERE groomer_id = ? AND day_of_week = ?'
      );
      $breakStatement->execute([$groomerId, $dayOfWeek]);
      foreach ($breakStatement->fetchAll() as $break) {
        $breakStart = new DateTimeImmutable($date . ' ' . $break['start_time']);
        $breakEnd = new DateTimeImmutable($date . ' ' . $break['end_time']);
        if ($visitAt < $breakEnd && $visitEnd > $breakStart) {
          throw new InvalidArgumentException('The grooming appointment overlaps the assigned groomer’s break.');
        }
      }
      $activeStatuses = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
      $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
      $conflictStatement = $pdo->prepare(
        "SELECT a.appointment_id
         FROM Appointments a
         JOIN AppointmentServices aps ON aps.appointment_id = a.appointment_id
         WHERE aps.groomer_id = ? AND a.appointment_id <> ?
           AND a.appointment_date < ?
           AND DATE_ADD(a.appointment_date, INTERVAL a.duration_minutes MINUTE) > ?
           AND a.status IN ($statusPlaceholders)
         LIMIT 1 FOR UPDATE"
      );
      $conflictStatement->execute(array_merge([
        $groomerId,
        (int) $appointment['appointment_id'],
        $visitEnd->format('Y-m-d H:i:s'),
        $visitAt->format('Y-m-d H:i:s'),
      ], $activeStatuses));
      if ($conflictStatement->fetchColumn()) {
        throw new InvalidArgumentException('The assigned groomer now has an overlapping appointment.');
      }
      return;
    }
        throw new InvalidArgumentException('Assign a veterinarian before approving this request.');
    }

    $vetStatement = $pdo->prepare(
        'SELECT is_available, max_appointments_per_day
         FROM Veterinarians
         WHERE vet_id = ?
         FOR UPDATE'
    );
    $vetStatement->execute([$vetId]);
    $vet = $vetStatement->fetch();
    if (!$vet || (int) $vet['is_available'] !== 1) {
        throw new InvalidArgumentException('The assigned veterinarian is unavailable.');
    }

    $visitAt = new DateTimeImmutable((string) $appointment['appointment_date']);
    if ($visitAt <= new DateTimeImmutable('now')) {
        throw new InvalidArgumentException('This appointment time has passed and cannot be approved.');
    }
    $duration = (int) $appointment['duration_minutes'];
    if ($duration < 1) {
        throw new InvalidArgumentException('The appointment duration is invalid.');
    }
    $visitEnd = $visitAt->modify('+' . $duration . ' minutes');
    $date = $visitAt->format('Y-m-d');
    $dayOfWeek = (int) $visitAt->format('N');

    $scheduleStatement = $pdo->prepare(
        'SELECT start_time, end_time
         FROM VetSchedules
         WHERE vet_id = ? AND day_of_week = ?
         FOR UPDATE'
    );
    $scheduleStatement->execute([$vetId, $dayOfWeek]);
    $schedule = $scheduleStatement->fetch();
    if (!$schedule) {
        throw new InvalidArgumentException('The veterinarian has no approved working hours on that day.');
    }
    $workStart = new DateTimeImmutable($date . ' ' . $schedule['start_time']);
    $workEnd = new DateTimeImmutable($date . ' ' . $schedule['end_time']);
    if ($visitAt < $workStart || $visitEnd > $workEnd) {
        throw new InvalidArgumentException('The appointment no longer fits the veterinarian’s working hours.');
    }

    $holidayStatement = $pdo->prepare('SELECT holiday_name FROM ClinicHolidays WHERE holiday_date = ? LIMIT 1');
    $holidayStatement->execute([$date]);
    if ($holidayStatement->fetchColumn()) {
        throw new InvalidArgumentException('The clinic is closed on that date.');
    }

    $leaveStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM VetLeaves
         WHERE vet_id = ? AND status = 'Approved' AND start_date <= ? AND end_date >= ?"
    );
    $leaveStatement->execute([$vetId, $date, $date]);
    if ((int) $leaveStatement->fetchColumn() > 0) {
        throw new InvalidArgumentException('The veterinarian is on approved leave on that date.');
    }

    $breakStatement = $pdo->prepare(
        'SELECT start_time, end_time
         FROM VetScheduleBreaks
         WHERE vet_id = ? AND day_of_week = ?'
    );
    $breakStatement->execute([$vetId, $dayOfWeek]);
    foreach ($breakStatement->fetchAll() as $break) {
        $breakStart = new DateTimeImmutable($date . ' ' . $break['start_time']);
        $breakEnd = new DateTimeImmutable($date . ' ' . $break['end_time']);
        if ($visitAt < $breakEnd && $visitEnd > $breakStart) {
            throw new InvalidArgumentException('The appointment overlaps the veterinarian’s break.');
        }
    }

    $serviceStatement = $pdo->prepare(
        'SELECT COUNT(DISTINCT aps.service_id) AS requested_count,
                COUNT(DISTINCT CASE WHEN s.is_active = 1 AND vs.vet_id IS NOT NULL THEN aps.service_id END) AS qualified_count
         FROM AppointmentServices aps
         JOIN Services s ON s.service_id = aps.service_id
         LEFT JOIN VetServices vs ON vs.service_id = aps.service_id AND vs.vet_id = ?
         WHERE aps.appointment_id = ?'
    );
    $serviceStatement->execute([$vetId, (int) $appointment['appointment_id']]);
    $services = $serviceStatement->fetch();
    if (!$services || (int) $services['requested_count'] < 1
        || (int) $services['requested_count'] !== (int) $services['qualified_count']) {
        throw new InvalidArgumentException('The veterinarian is no longer qualified for all requested services.');
    }

    $capacityStatuses = ['Scheduled', 'Waiting', 'Check-in', 'In Consultation', 'Complete'];
    $capacityPlaceholders = implode(',', array_fill(0, count($capacityStatuses), '?'));
    $dailyCount = $pdo->prepare(
        "SELECT COUNT(*)
         FROM Appointments
         WHERE vet_id = ? AND DATE(appointment_date) = ? AND appointment_id <> ?
           AND status IN ($capacityPlaceholders)
         FOR UPDATE"
    );
    $dailyCount->execute(array_merge([
        $vetId,
        $date,
        (int) $appointment['appointment_id'],
    ], $capacityStatuses));
    if ((int) $dailyCount->fetchColumn() >= (int) $vet['max_appointments_per_day']) {
        throw new InvalidArgumentException('The assigned veterinarian has reached the daily appointment capacity.');
    }

    $activeStatuses = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
    $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    $conflictStatement = $pdo->prepare(
        "SELECT appointment_id
         FROM Appointments
         WHERE vet_id = ? AND appointment_id <> ?
           AND appointment_date < ?
           AND DATE_ADD(appointment_date, INTERVAL duration_minutes MINUTE) > ?
           AND status IN ($statusPlaceholders)
         LIMIT 1
         FOR UPDATE"
    );
    $conflictStatement->execute(array_merge([
        $vetId,
        (int) $appointment['appointment_id'],
        $visitEnd->format('Y-m-d H:i:s'),
        $visitAt->format('Y-m-d H:i:s'),
    ], $activeStatuses));
    if ($conflictStatement->fetchColumn()) {
        throw new InvalidArgumentException('The veterinarian now has an overlapping appointment.');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['calendar'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $from = staff_ap_date($_GET['from'] ?? null);
    $to = staff_ap_date($_GET['to'] ?? null);
    if (!$from || !$to || $to < $from || (int) $from->diff($to)->days > 62) {
        http_response_code(400);
        echo '{"error":"Invalid date range."}';
        exit;
    }

    $filters = staff_appointment_filters(array_diff_key($_GET, ['date' => 1, 'appointment_id' => 1, 'sort' => 1]));
    $where = $filters['where'] . ' AND a.appointment_date >= ? AND a.appointment_date < ?';
    $params = array_merge($filters['params'], [
        $from->format('Y-m-d') . ' 00:00:00',
        $to->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
    ]);
    if ($filters['status'] === '') {
        $where .= " AND a.status NOT IN ('Cancelled','Rejected')";
    }

    $feed = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.duration_minutes, a.status, a.vet_id,
                a.payment_timing_preference, a.preferred_payment_method,
                p.pet_name, o.full_name AS owner_name, v.full_name AS vet_name,
                (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
                   FROM AppointmentServices aps JOIN Services s ON s.service_id = aps.service_id
                  WHERE aps.appointment_id = a.appointment_id) AS services
         FROM Appointments a
         JOIN Pets p ON p.pet_id = a.pet_id
         JOIN PetOwners o ON o.owner_id = p.owner_id
         LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
         $where
         ORDER BY a.appointment_date, a.appointment_id
         LIMIT 1500"
    );
    $feed->execute($params);
    $appointments = [];
    foreach ($feed->fetchAll() as $row) {
        $timestamp = strtotime((string) $row['appointment_date']);
        $payment = '';
        if (!empty($row['payment_timing_preference'])) {
            $payment = ($row['payment_timing_preference'] === 'Advance request' ? 'Advance requested' : 'Pay at clinic')
                . (!empty($row['preferred_payment_method']) ? ' · ' . $row['preferred_payment_method'] : '');
        }
        $appointments[] = [
            'id' => (int) $row['appointment_id'],
            'date' => date('Y-m-d', $timestamp),
            'start' => date('H:i', $timestamp),
            'minutes' => (int) $row['duration_minutes'],
            'status' => (string) $row['status'],
            'pet' => (string) $row['pet_name'],
            'owner' => (string) $row['owner_name'],
            'vet' => (string) ($row['vet_name'] ?? ''),
            'vetId' => (int) ($row['vet_id'] ?? 0),
            'services' => (string) ($row['services'] ?? ''),
            'payment' => $payment,
            'when' => date('D, M j, Y · g:i A', $timestamp),
            'timeLabel' => date('g:i A', $timestamp),
            'canApprove' => $row['status'] === 'Pending',
            'canCancel' => in_array($row['status'], ['Pending', 'Scheduled', 'Check-in'], true),
        ];
    }

    $availability = new stdClass();
    $holiday = '';
    if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
        $day = $from->format('Y-m-d');
        $dayOfWeek = (int) $from->format('N');
        $scheduleMap = [];
        $scheduleStatement = $pdo->prepare('SELECT vet_id, start_time, end_time FROM VetSchedules WHERE day_of_week = ?');
        $scheduleStatement->execute([$dayOfWeek]);
        foreach ($scheduleStatement->fetchAll() as $schedule) {
            $scheduleMap[(int) $schedule['vet_id']] = [
                'start' => substr((string) $schedule['start_time'], 0, 5),
                'end' => substr((string) $schedule['end_time'], 0, 5),
                'breaks' => [],
                'leave' => false,
            ];
        }
        $breakStatement = $pdo->prepare('SELECT vet_id, start_time, end_time FROM VetScheduleBreaks WHERE day_of_week = ? ORDER BY start_time');
        $breakStatement->execute([$dayOfWeek]);
        foreach ($breakStatement->fetchAll() as $break) {
            $vetId = (int) $break['vet_id'];
            if (isset($scheduleMap[$vetId])) {
                $scheduleMap[$vetId]['breaks'][] = [
                    substr((string) $break['start_time'], 0, 5),
                    substr((string) $break['end_time'], 0, 5),
                ];
            }
        }
        $leaveStatement = $pdo->prepare("SELECT DISTINCT vet_id FROM VetLeaves WHERE status = 'Approved' AND start_date <= ? AND end_date >= ?");
        $leaveStatement->execute([$day, $day]);
        foreach ($leaveStatement->fetchAll() as $leave) {
            $vetId = (int) $leave['vet_id'];
            if (!isset($scheduleMap[$vetId])) {
                $scheduleMap[$vetId] = ['start' => '', 'end' => '', 'breaks' => [], 'leave' => true];
            } else {
                $scheduleMap[$vetId]['leave'] = true;
            }
        }
        if ($scheduleMap) {
            $availability = $scheduleMap;
        }
        $holidayStatement = $pdo->prepare('SELECT holiday_name FROM ClinicHolidays WHERE holiday_date = ? LIMIT 1');
        $holidayStatement->execute([$day]);
        $holiday = (string) ($holidayStatement->fetchColumn() ?: '');
    }
    echo json_encode(['appointments' => $appointments, 'availability' => $availability, 'holiday' => $holiday], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_SESSION['staff_appointment_flash'])) {
    $success = (string) $_SESSION['staff_appointment_flash'];
    unset($_SESSION['staff_appointment_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(posted_string('csrf'))) {
        if ($isDashboardAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.']);
            exit;
        }
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        $id = (int) posted_string('appointment_id');
        $action = posted_string('action');
        try {
            $pdo->beginTransaction();
            $a = $pdo->prepare('SELECT a.*,p.owner_id FROM Appointments a JOIN Pets p ON p.pet_id=a.pet_id WHERE appointment_id=? FOR UPDATE');
            $a->execute([$id]);
            $row = $a->fetch();
            if (!$row) {
                throw new InvalidArgumentException('Appointment not found.');
            }
            if ($action === 'approve' && $row['status'] === 'Pending') {
                staff_assert_appointment_available($pdo, $row);
                $pdo->prepare("UPDATE Appointments SET status='Scheduled',approved_at=NOW(),staff_id=(SELECT staff_id FROM Users WHERE user_id=? AND staff_id IS NOT NULL) WHERE appointment_id=?")->execute([(int) $_SESSION['user_id'], $id]);
                $success = 'Appointment approved.';
                role_notify_owner($pdo, (int) $row['owner_id'], 'appointment', 'Appointment approved', 'Your appointment has been approved.', 'client/appointments.php', 'appointment_approved_' . $id);
                role_notify_vet($pdo, $row['vet_id'] ? (int) $row['vet_id'] : null, 'appointment', 'Appointment scheduled', 'Appointment #' . $id . ' is scheduled.', 'vet/appointments.php', 'appointment_scheduled_' . $id);
                role_notify_role($pdo, 'admin', 'appointment', 'Appointment approved', 'Appointment #' . $id . ' has been approved.', 'admin/appointments.php', 'appointment_approved_admin_' . $id);
            } elseif ($action === 'reject' && $row['status'] === 'Pending') {
                $reason = mb_substr(trim(posted_string('reason')), 0, 255);
                if ($reason === '') {
                    throw new InvalidArgumentException('Provide a reason for rejecting the appointment request.');
                }
                $pdo->prepare("UPDATE Appointments SET status='Rejected',rejection_reason=? WHERE appointment_id=?")
                    ->execute([$reason, $id]);
                $success = 'Appointment request rejected.';
                $ownerNoticeCount = role_notify_owner(
                    $pdo,
                    (int) $row['owner_id'],
                    'appointment',
                    'Appointment request rejected',
                    'Your appointment request was rejected. Reason: ' . $reason,
                    'client/appointments.php',
                    'appointment_rejected_owner_' . $id
                );
                role_notify_role(
                    $pdo,
                    'admin',
                    'appointment',
                    'Appointment request rejected',
                    'Appointment #' . $id . ' was rejected.',
                    'admin/appointments.php',
                    'appointment_rejected_admin_' . $id
                );
            } elseif ($action === 'cancel' && in_array($row['status'], ['Pending', 'Scheduled', 'Check-in'], true)) {
                $reason = mb_substr(trim(posted_string('reason')), 0, 255);
                $pdo->prepare("UPDATE Appointments SET status='Cancelled',cancellation_reason=? WHERE appointment_id=?")->execute([$reason !== '' ? $reason : null, $id]);
                $success = 'Appointment cancelled.';
                role_notify_owner($pdo, (int) $row['owner_id'], 'appointment', 'Appointment cancelled', 'Appointment #' . $id . ' was cancelled.', 'client/appointments.php', 'appointment_cancelled_' . $id);
                if ($row['status'] !== 'Pending') {
                    role_notify_vet($pdo, $row['vet_id'] ? (int) $row['vet_id'] : null, 'appointment', 'Appointment cancelled', 'Appointment #' . $id . ' was cancelled.', 'vet/appointments.php', 'appointment_cancelled_' . $id);
                }
                role_notify_role($pdo, 'admin', 'appointment', 'Appointment cancelled', 'Appointment #' . $id . ' was cancelled.', 'admin/appointments.php', 'appointment_cancelled_admin_' . $id);
            } elseif ($action === 'pickup_ready') {
                if (!in_array($row['status'], ['Check-in', 'Waiting', 'In Consultation', 'Complete'], true)
                    || $row['appointment_date'] > date('Y-m-d H:i:s')) {
                    throw new InvalidArgumentException('Only a checked-in, in-clinic, or completed appointment can be marked ready for pickup.');
                }
                if ($row['pickup_ready_at'] !== null) {
                    throw new InvalidArgumentException('This pet is already marked ready for pickup.');
                }
                $pickupService = $pdo->prepare(
                    "SELECT EXISTS(
                        SELECT 1
                        FROM AppointmentServices aps
                        JOIN Services s ON s.service_id = aps.service_id
                        WHERE aps.appointment_id = ?
                          AND s.service_category = 'Grooming & Hygiene'
                    )"
                );
                $pickupService->execute([$id]);
                if (!(int) $pickupService->fetchColumn()) {
                    throw new InvalidArgumentException('Pickup-ready status is only available for grooming and hygiene services.');
                }
                $pdo->prepare('UPDATE Appointments SET pickup_ready_at = NOW() WHERE appointment_id = ? AND pickup_ready_at IS NULL')
                    ->execute([$id]);
                $success = $row['pet_name'] . ' is marked ready for pickup. The owner was notified.';
                role_notify_owner(
                    $pdo,
                    (int) $row['owner_id'],
                    'appointment',
                    'Pet ready for pickup',
                    $row['pet_name'] . ' is ready for pickup from the clinic (appointment #' . $id . ').',
                    'client/appointments.php',
                    'pickup_ready_owner_' . $id
                );
                $success = $ownerNoticeCount > 0
                    ? $success
                    : $row['pet_name'] . ' is marked ready for pickup. The status is visible in the owner\'s appointments, but no notification could be sent.';
            } else {
                throw new InvalidArgumentException('That action is not allowed in the current status.');
            }
            $logDetails = $action === 'pickup_ready'
                ? 'Marked ready for pickup; appointment status remains ' . $row['status']
                : $row['status'] . ' -> ' . $pdo->query('SELECT status FROM Appointments WHERE appointment_id=' . (int) $id)->fetchColumn();
            role_log($pdo, (int) $_SESSION['user_id'], 'appointment.' . $action, 'appointment', $id, $logDetails);
            $pdo->commit();
            if ($isDashboardAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $success, 'appointment_id' => $id], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // Post/Redirect/Get: refreshing the page must not repeat the action.
            $_SESSION['staff_appointment_flash'] = $success;
            $returnTo = posted_string('return_to');
            $redirect = $returnTo === 'staff_dashboard.php'
                ? 'staff_dashboard.php#pending-requests'
                : ($_SERVER['REQUEST_URI'] ?? 'appointments.php');
            header('Location: ' . $redirect);
            exit;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $success = '';
            $error = $ex instanceof InvalidArgumentException ? $ex->getMessage() : 'The appointment could not be updated.';
            error_log('Staff appointment action: ' . $ex->getMessage());
            if ($isDashboardAjax) {
                http_response_code($ex instanceof InvalidArgumentException ? 400 : 500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $error], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
}

role_render('staff', ['appointments'], 'Appointments', 'Approve, monitor and cancel visits', function (array $c) use ($pdo, $error, $success) {
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
    $filters = staff_appointment_filters($_GET);
    $allStatuses = STAFF_AP_STATUSES;
    $status = $filters['status'];
    $dateFilter = $filters['dateFilter'];
    $q = $filters['q'];
    $sort = $filters['sort'];
    $appointmentId = $filters['appointmentId'];
    $vetFilter = $filters['vet'];
    $where = $filters['where'];
    $params = $filters['params'];
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 12;

    $from = 'FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id
             JOIN PetOwners o ON o.owner_id = p.owner_id
             LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id';
    $countStatement = $pdo->prepare("SELECT COUNT(*) $from $where");
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $direction = $sort === 'oldest' ? 'ASC' : 'DESC';

    $st = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.duration_minutes, a.status, a.pickup_ready_at, a.picked_up_at, a.payment_timing_preference,
                a.preferred_payment_method, p.pet_id, p.pet_name, o.full_name AS owner_name, v.full_name AS vet_name,
                IF(p.photo_data IS NULL, NULL, COALESCE(UNIX_TIMESTAMP(p.photo_updated_at), 1)) AS photo_ver,
                EXISTS(
                    SELECT 1 FROM AppointmentServices aps
                    JOIN Services s ON s.service_id = aps.service_id
                    WHERE aps.appointment_id = a.appointment_id
                      AND s.service_category = 'Grooming & Hygiene'
                ) AS pickup_eligible,
                (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
                   FROM AppointmentServices aps JOIN Services s ON s.service_id = aps.service_id
                  WHERE aps.appointment_id = a.appointment_id) AS services
         $from $where
         ORDER BY a.appointment_date $direction, a.appointment_id $direction
         LIMIT $perPage OFFSET $offset"
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    // ---- Summary numbers (not affected by the filters) ----
    $counts = array_fill_keys($allStatuses, 0);
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM Appointments GROUP BY status')->fetchAll() as $r) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']] = (int) $r['total'];
        }
    }
    $allCount = array_sum($counts);
    $todayCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM Appointments
         WHERE appointment_date >= CURRENT_DATE AND appointment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
           AND status NOT IN ('Cancelled','Rejected')"
    )->fetchColumn();
    $upcomingCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM Appointments
         WHERE appointment_date >= DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
           AND status NOT IN ('Cancelled','Rejected')"
    )->fetchColumn();
    $vets = $pdo->query('SELECT vet_id, full_name FROM Veterinarians ORDER BY full_name')->fetchAll();
    $vetList = array_map(static fn(array $vet): array => [
        'id' => (int) $vet['vet_id'],
        'name' => (string) $vet['full_name'],
    ], $vets);

    // ---- URL builder that keeps the current filters ----
    $url = function (array $override = []) use ($q, $status, $dateFilter, $sort, $appointmentId, $vetFilter): string {
        $p = array_merge(['q' => $q, 'status' => $status, 'vet' => $vetFilter, 'date' => $dateFilter, 'sort' => $sort === 'oldest' ? 'oldest' : '',
            'appointment_id' => $appointmentId ?: ''], $override);
        $p = array_filter($p, static function ($v) { return $v !== '' && $v !== null; });
        return 'appointments.php' . ($p ? '?' . http_build_query($p) : '');
    };
    $hasFilters = $q !== '' || $status !== '' || $vetFilter !== '' || $dateFilter !== '' || $appointmentId > 0 || $sort === 'oldest';
    ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="notice success ok" role="status"><?= e($success) ?></div><?php endif; ?>

    <section class="panel ap-panel">
      <div class="panel-header">
        <h2>Assigned appointments</h2>
        <div class="panel-actions">
          <span class="ap-visit-count"><?= $allCount ?> <?= $allCount === 1 ? 'visit' : 'visits' ?></span>
          <div class="ap-views" role="group" aria-label="Appointment view">
            <button type="button" class="ap-view-btn" data-view="list" aria-pressed="true">List</button>
            <button type="button" class="ap-view-btn" data-view="month" aria-pressed="false">Month</button>
            <button type="button" class="ap-view-btn" data-view="day" aria-pressed="false">Day by vet</button>
          </div>
          <a class="button small light" href="schedule_appointment.php">+ Schedule visit</a>
        </div>
      </div>

      <div class="ap-filter-panel">
      <div class="ap-tabs" role="tablist" aria-label="Filter appointments">
        <?php
        $statusTabs = [
            ['label' => 'All', 'status' => '', 'date' => '', 'count' => $allCount],
            ['label' => 'Today', 'status' => '', 'date' => 'today', 'count' => $todayCount],
            ['label' => 'Upcoming', 'status' => '', 'date' => 'upcoming', 'count' => $upcomingCount],
            ['label' => 'Pending', 'status' => 'Pending', 'date' => '', 'count' => $counts['Pending']],
            ['label' => 'In clinic', 'status' => 'in-clinic', 'date' => '', 'count' => $counts['Waiting'] + $counts['Check-in'] + $counts['In Consultation']],
            ['label' => 'Completed', 'status' => 'Complete', 'date' => '', 'count' => $counts['Complete']],
            ['label' => 'Cancelled', 'status' => 'Cancelled', 'date' => '', 'count' => $counts['Cancelled']],
        ];
        foreach ($statusTabs as $tab):
            $activeTab = $status === $tab['status'] && $dateFilter === $tab['date'];
        ?>
          <button type="button" class="ap-tab<?= $activeTab ? ' is-active' : '' ?><?= $tab['status'] === 'Pending' && $tab['count'] > 0 ? ' needs-action' : '' ?>" data-status="<?= e($tab['status']) ?>" data-date="<?= e($tab['date']) ?>" role="tab" aria-selected="<?= $activeTab ? 'true' : 'false' ?>"><?= e($tab['label']) ?><span class="ap-tab-n"><?= (int) $tab['count'] ?></span></button>
        <?php endforeach; ?>
      </div>

      <form class="ap-filters" id="ap-filters" method="get" action="appointments.php" role="search">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php if ($appointmentId > 0): ?><input type="hidden" name="appointment_id" value="<?= $appointmentId ?>"><?php endif; ?>
        <label class="ap-field ap-search">Search
          <input type="search" name="q" value="<?= e($q) ?>" placeholder="Pet, owner, veterinarian, service or #ID" autocomplete="off" maxlength="80">
        </label>
        <label class="ap-field">Veterinarian
          <select name="vet">
            <option value="">All veterinarians</option>
            <?php foreach ($vets as $vet): ?><option value="<?= (int) $vet['vet_id'] ?>"<?= $vetFilter === (string) $vet['vet_id'] ? ' selected' : '' ?>><?= e($vet['full_name']) ?></option><?php endforeach; ?>
            <option value="none"<?= $vetFilter === 'none' ? ' selected' : '' ?>>Not yet assigned</option>
          </select>
        </label>
        <label class="ap-field ap-list-only">Date
          <select name="date">
            <option value="">All dates</option>
            <option value="today"<?= $dateFilter === 'today' ? ' selected' : '' ?>>Today</option>
            <option value="upcoming"<?= $dateFilter === 'upcoming' ? ' selected' : '' ?>>Upcoming</option>
            <option value="past"<?= $dateFilter === 'past' ? ' selected' : '' ?>>Before today</option>
          </select>
        </label>
        <label class="ap-field ap-list-only">Sort
          <select name="sort">
            <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Newest first</option>
            <option value="oldest"<?= $sort === 'oldest' ? ' selected' : '' ?>>Oldest first</option>
          </select>
        </label>
        <a class="button secondary ap-reset" href="appointments.php">Clear</a>
      </form>
      <div class="ap-resultbar" id="ap-filter-count">
        <span><strong><?= $total ?></strong> of <?= $allCount ?> <?= $allCount === 1 ? 'visit' : 'visits' ?><?= $appointmentId > 0 ? ' · appointment #' . $appointmentId : '' ?></span>
      </div>
      </div>

      <div id="ap-cal" hidden>
        <div class="cal-toolbar">
          <div class="cal-nav">
            <button class="cal-nav-btn" type="button" id="cal-prev" aria-label="Previous">&#8249;</button>
            <button class="cal-nav-btn" type="button" id="cal-next" aria-label="Next">&#8250;</button>
            <h3 class="cal-title" id="cal-title" aria-live="polite"></h3>
            <button class="cal-today-btn" type="button" id="cal-today">Today</button>
          </div>
          <div class="cal-legend" aria-hidden="true">
            <span><i style="background:#c48621"></i>Pending</span>
            <span><i style="background:#0284c7"></i>Scheduled</span>
            <span><i style="background:#6550a4"></i>Waiting / checked in</span>
            <span><i style="background:#0e7490"></i>In consultation</span>
            <span><i style="background:#23935b"></i>Complete</span>
          </div>
        </div>
        <div class="cal-frame" id="cal-body" aria-live="polite"></div>
      </div>

      <div id="ap-results" aria-live="polite">
        <div class="ap-loadbar" aria-hidden="true"></div>

        <?php /* .ap-body keeps a constant minimum height, so the box never collapses when there is no data */ ?>
        <div class="ap-body">
        <?php if ($rows): ?>
          <div class="ap-table-wrap data-table-card">
            <table class="ap-table">
              <colgroup><col style="width:60px"><col style="width:150px"><col style="width:120px"><col style="width:140px"><col style="width:140px"><col style="width:150px"><col style="width:120px"><col style="width:100px"><col></colgroup>
              <thead>
                <tr>
                  <th class="ap-col-id">ID</th>
                  <th>Date &amp; time</th>
                  <th>Pet</th>
                  <th>Owner</th>
                  <th>Services</th>
                  <th>Payment</th>
                  <th>Veterinarian</th>
                  <th>Status</th>
                  <th class="ap-col-actions">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r):
                  $ts = strtotime($r['appointment_date']);
                  $photo = ($r['photo_ver'] && function_exists('pet_photo_url')) ? pet_photo_url($r['photo_ver'], (int) $r['pet_id']) : '';
                  $serviceList = array_filter(array_map('trim', explode(',', (string) $r['services'])));
                  $canApprove = $r['status'] === 'Pending';
                  $canReject = $canApprove;
                  $canCancel = in_array($r['status'], ['Pending', 'Scheduled', 'Check-in'], true);
                  $pickupReady = !empty($r['pickup_ready_at']) && empty($r['picked_up_at']);
                  $canMarkPickupReady = !empty($r['pickup_eligible'])
                      && !$pickupReady
                      && empty($r['picked_up_at'])
                      && in_array($r['status'], ['Check-in', 'Waiting', 'In Consultation', 'Complete'], true)
                      && $r['appointment_date'] <= date('Y-m-d H:i:s');
              ?>
                <tr data-status="<?= e($r['status']) ?>">
                  <td data-label="ID" class="ap-col-id"><span class="ap-id">#<?= (int) $r['appointment_id'] ?></span></td>
                  <td data-label="Date &amp; time" class="ap-nowrap">
                    <div class="ap-when">
                      <b class="ap-when-date"><?= e(date('M j, Y', $ts)) ?></b>
                      <em class="ap-when-time"><?= e(date('g:i A', $ts)) ?> &middot; <?= (int) $r['duration_minutes'] ?> min</em>
                    </div>
                  </td>
                  <td data-label="Pet">
                    <div class="ap-pet">
                      <span class="ap-avatar" aria-hidden="true"><?php if ($photo): ?><img src="<?= e($photo) ?>" alt="" loading="lazy"><?php else: ?><?= e(mb_strtoupper(mb_substr((string) $r['pet_name'], 0, 1))) ?><?php endif; ?></span>
                      <strong class="ap-pet-name"><?= e($r['pet_name']) ?></strong>
                    </div>
                  </td>
                  <td data-label="Owner"><?= e($r['owner_name']) ?></td>
                  <td data-label="Services"><div class="ap-chips"><?php if ($serviceList): foreach ($serviceList as $svc): ?><span class="ap-chip"><?= e($svc) ?></span><?php endforeach; else: ?><span class="ap-muted">—</span><?php endif; ?></div></td>
                  <td data-label="Payment">
                    <?php if (!empty($r['payment_timing_preference'])): ?>
                      <div class="ap-pay"><b><?= e($r['payment_timing_preference'] === 'Advance request' ? 'Advance requested' : 'Pay at clinic') ?></b><em><?= e($r['preferred_payment_method'] ?? '') ?></em></div>
                    <?php else: ?><span class="ap-muted">—</span><?php endif; ?>
                  </td>
                  <td data-label="Veterinarian"><?= $r['vet_name'] ? e($r['vet_name']) : '<span class="ap-muted">To be assigned</span>' ?></td>
                  <td data-label="Status">
                    <span class="status <?= e(role_status_class($r['status'])) ?>"><?= e($r['status']) ?></span>
                    <?php if (!empty($r['picked_up_at'])): ?><small class="ap-muted">Picked up <?= e(date('M j, g:i A', strtotime((string) $r['picked_up_at']))) ?></small>
                    <?php elseif ($pickupReady): ?><span class="status pickup-ready">Ready for pickup</span><?php endif; ?>
                  </td>
                  <td data-label="Actions" class="ap-col-actions">
                    <?php if ($canApprove || $canReject || $canCancel || $canMarkPickupReady): ?>
                      <div class="ap-actions">
                        <?php if ($canApprove): ?>
                          <form method="post" class="ap-approve-form">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="appointment_id" value="<?= (int) $r['appointment_id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button class="button small" type="submit">Approve</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($canReject): ?>
                          <button class="button secondary small reject-appt" type="button"
                            data-id="<?= (int) $r['appointment_id'] ?>" data-pet="<?= e($r['pet_name']) ?>" data-owner="<?= e($r['owner_name']) ?>"
                            data-when="<?= e(date('D, M j, Y · g:i A', $ts)) ?>">Reject</button>
                        <?php endif; ?>
                        <?php if ($canCancel): ?>
                          <button class="button danger small cancel-appt" type="button"
                            data-id="<?= (int) $r['appointment_id'] ?>" data-pet="<?= e($r['pet_name']) ?>" data-owner="<?= e($r['owner_name']) ?>"
                            data-when="<?= e(date('D, M j, Y · g:i A', $ts)) ?>">Cancel</button>
                        <?php endif; ?>
                        <?php if ($canMarkPickupReady): ?>
                          <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="appointment_id" value="<?= (int) $r['appointment_id'] ?>">
                            <input type="hidden" name="action" value="pickup_ready">
                            <button class="button secondary small" type="submit">Mark ready for pickup</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    <?php else: ?><span class="ap-muted">No actions</span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($pages > 1): ?>
            <nav class="ap-pager" aria-label="Pages">
              <?php for ($i = 1; $i <= $pages; $i++):
                  if ($pages > 9 && $i !== 1 && $i !== $pages && abs($i - $page) > 2) {
                      if ($i === 2 || $i === $pages - 1) { echo '<span class="ap-gap">…</span>'; }
                      continue;
                  }
                  if ($i === $page): ?><span class="on" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= e($url(['page' => $i > 1 ? $i : ''])) ?>"><?= $i ?></a><?php endif;
              endfor; ?>
            </nav>
          <?php endif; ?>
        <?php else: ?>
          <div class="ap-table-wrap data-table-card">
            <table class="ap-table ap-table-empty" aria-hidden="true">
              <colgroup><col style="width:60px"><col style="width:150px"><col style="width:120px"><col style="width:140px"><col style="width:140px"><col style="width:150px"><col style="width:120px"><col style="width:100px"><col></colgroup>
              <thead>
                <tr>
                  <th class="ap-col-id">ID</th><th>Date &amp; time</th><th>Pet</th><th>Owner</th><th>Services</th><th>Payment</th><th>Veterinarian</th><th>Status</th><th class="ap-col-actions">Actions</th>
                </tr>
              </thead>
            </table>
            <div class="ap-empty">
              <span class="ap-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="3"/><path d="M8 2.5v4M16 2.5v4M3 10h18"/><path d="M9.5 15h5"/></svg></span>
              <strong>No records found</strong>
              <span><?= $hasFilters ? 'No appointments match your search or filters.' : 'New requests and scheduled visits will appear here.' ?></span>
              <div>
                <?php if ($hasFilters): ?><a class="button secondary small" href="appointments.php">Clear filters</a><?php endif; ?>
                <a class="button small" href="schedule_appointment.php">Schedule a visit</a>
              </div>
            </div>
          </div>
        <?php endif; ?>
        </div>
      </div>
    </section>

    <dialog id="detail-dialog" class="ap-dialog" aria-labelledby="det-title">
      <div class="ap-dialog-head"><h2 id="det-title">Appointment</h2></div>
      <div class="ap-dialog-body">
        <dl class="ap-detail">
          <div><dt>When</dt><dd id="det-when"></dd></div>
          <div><dt>Pet</dt><dd id="det-pet"></dd></div>
          <div><dt>Owner</dt><dd id="det-owner"></dd></div>
          <div><dt>Veterinarian</dt><dd id="det-vet"></dd></div>
          <div><dt>Services</dt><dd id="det-services"></dd></div>
          <div><dt>Payment</dt><dd id="det-payment"></dd></div>
          <div><dt>Status</dt><dd id="det-status"></dd></div>
        </dl>
      </div>
      <div class="ap-dialog-actions">
        <form method="post" id="det-approve" hidden>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="appointment_id" id="det-approve-id">
          <input type="hidden" name="action" value="approve">
          <button class="button" type="submit">Approve</button>
        </form>
        <button class="button danger" type="button" id="det-cancel" hidden>Cancel appointment</button>
        <button class="button secondary" type="button" id="det-close">Close</button>
      </div>
    </dialog>

    <dialog id="cancel-dialog" class="ap-dialog" aria-labelledby="cancel-title">
      <form method="post">
        <div class="ap-dialog-head">        <h2 id="cancel-title">Cancel appointment</h2></div>
        <div class="ap-dialog-body">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" id="cancel-action" value="cancel">
          <input type="hidden" name="appointment_id" id="cancel-id">
          <div class="ap-dialog-summary"><strong id="cancel-pet"></strong><span id="cancel-owner"></span><span id="cancel-when"></span></div>
          <label class="ap-field">Reason <span class="ap-optional" id="cancel-reason-hint">(optional, shared with the owner)</span>
            <textarea name="reason" id="cancel-reason" maxlength="255" rows="3" placeholder="e.g. Veterinarian unavailable, please rebook."></textarea>
          </label>
          <p class="ap-dialog-note" id="cancel-note">The time slot is released and the pet owner and veterinarian are notified.</p>
        </div>
        <div class="ap-dialog-actions">
          <button class="button secondary" type="button" id="cancel-keep">Keep appointment</button>
          <button class="button danger" type="submit" id="cancel-confirm">Confirm cancellation</button>
        </div>
      </form>
    </dialog>

    <style>
      /* ---------- Summary cards ---------- */
      .ap-stats{margin:0 0 18px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
      .ap-stat{color:inherit;text-decoration:none;transition:transform .15s,box-shadow .15s}
      .ap-stat:hover{transform:translateY(-2px)}
      .ap-stat:focus-visible{outline:3px solid rgba(2,132,199,.35);outline-offset:3px}
      .ap-stat.is-active{box-shadow:0 0 0 2px var(--accent,#0284c7)}
      .ap-stat-note{color:#5a7a92;font-size:.78rem}
      .ap-panel{margin:0 0 24px}

      /* ---------- Filters ---------- */
      .ap-filters{padding:18px 20px;display:grid;grid-template-columns:minmax(240px,2.2fr) repeat(3,minmax(150px,1fr)) auto;align-items:end;gap:14px;border-bottom:1px solid #e1edf6;background:#f7fbfe}
      .ap-field{display:grid;gap:6px;color:#42637e;font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
      .ap-field input,.ap-field select,.ap-field textarea{width:100%;min-height:44px;padding:9px 13px;color:#092947;background:#fff;border:1px solid #b8d2e5;border-radius:10px;font:inherit;font-size:.92rem;letter-spacing:0;text-transform:none}
      .ap-field textarea{min-height:90px;resize:vertical}
      .ap-field input:focus,.ap-field select:focus,.ap-field textarea:focus{outline:3px solid rgba(2,132,199,.22);border-color:#0284c7}
      .ap-optional{color:#7b97ad;font-weight:600;letter-spacing:0;text-transform:none}
      .ap-reset{min-height:44px}

      /* ---------- Results area (fixed minimum height = box never shrinks) ---------- */
      #ap-results{position:relative}
      .ap-loadbar{position:absolute;top:0;left:0;right:0;height:3px;z-index:2;background:linear-gradient(90deg,transparent,#0284c7,transparent);background-size:40% 100%;background-repeat:no-repeat;animation:ap-slide 1s linear infinite;visibility:hidden}
      #ap-results.is-loading .ap-loadbar{visibility:visible}
      #ap-results.is-loading .ap-body{opacity:.5;transition:opacity .15s}
      @keyframes ap-slide{from{background-position:-40% 0}to{background-position:140% 0}}
      .ap-resultbar{padding:12px 20px;color:#42637e;font-size:.84rem;border-bottom:1px solid #e1edf6}
      .ap-resultbar strong{color:#002e4a}
      .ap-body{padding:18px 24px 24px;display:flex;flex-direction:column;min-height:440px}
      .ap-body > .ap-table-wrap{width:100%;min-height:420px;margin:0;flex:none;border-radius:12px}
      .ap-body > .ap-pager{margin-top:auto}

      /* ---------- Table (light-blue header with column dividers) ---------- */
      .ap-table-wrap{overflow-x:auto}
      .ap-table{width:100%;min-width:1080px;border-collapse:separate;border-spacing:0;text-align:left;table-layout:auto}
      .ap-table thead th{padding:13px 16px;color:#002e4a;background:linear-gradient(180deg,#e4f1fb,#cfe5f5);border-right:1px solid #b9d6ea;border-bottom:2px solid #a9cbe3;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
      .ap-table thead th:last-child{border-right:0}
      .ap-table td{padding:15px 16px;border-bottom:1px solid #e6eff6;color:#17324d;font-size:.9rem;vertical-align:middle;white-space:normal;overflow:visible}
      .status.pickup-ready{display:block;width:max-content;max-width:100%;margin-top:5px;color:#14532d;background:#dcfce7;white-space:normal;text-align:center;line-height:1.2}
      .ap-table tbody tr{transition:background-color .15s}
      .ap-table tbody tr:hover td{background:#f3f9fe}
      .ap-table tbody tr:last-child td{border-bottom:0}
      .ap-nowrap{white-space:nowrap}
      .ap-col-id{width:76px}
      .ap-col-actions{width:190px}
      .ap-id{color:#7b97ad;font-size:.8rem;font-weight:600}

      /* Date & time: each line is its own block, so nothing runs together */
      .ap-table .ap-when{display:flex;flex-direction:column;align-items:flex-start;gap:2px;line-height:1.25}
      .ap-table .ap-when b,.ap-table .ap-when em{display:block;font-style:normal}
      .ap-table .ap-when-date{color:#002e4a;font-size:.9rem;font-weight:700}
      .ap-table .ap-when-time{color:#42637e;font-size:.84rem;font-weight:400}

      .ap-pet{display:flex;align-items:center;gap:11px;min-width:140px}
      .ap-pet-name{color:#002e4a;font-weight:700;font-size:.9rem}
      .ap-avatar{width:40px;height:40px;flex:none;display:grid;place-items:center;overflow:hidden;color:#015b8c;background:#d8ebf8;border-radius:50%;font-weight:700}
      .ap-avatar img{width:100%;height:100%;object-fit:cover}
      .ap-chips{display:flex;flex-wrap:wrap;gap:6px;min-width:150px}
      .ap-chip{padding:4px 10px;display:inline-block;color:#015b8c;background:#eaf3fa;border-radius:999px;font-size:.78rem;font-weight:600;line-height:1.3}
      .ap-table .ap-pay{display:flex;flex-direction:column;gap:2px;line-height:1.3}
      .ap-table .ap-pay b{display:block;color:#002e4a;font-size:.86rem;font-weight:700}
      .ap-table .ap-pay em{display:block;color:#5a7a92;font-size:.8rem;font-style:normal}
      .ap-muted{color:#8aa3b6;font-size:.86rem}
      .ap-actions{display:flex;flex-wrap:wrap;gap:8px}.ap-actions form{margin:0}

      /* ---------- Empty state (same height as a populated table) ---------- */
      .ap-empty{flex:1;min-height:300px;padding:32px 20px;font-size:.9rem;display:grid;align-content:center;justify-items:center;gap:8px;color:#42637e;text-align:center}
      .ap-empty strong{color:#002e4a;font-size:1.02rem}
      .ap-empty div{margin-top:12px;display:flex;flex-wrap:wrap;justify-content:center;gap:10px}

      /* ---------- Pager ---------- */
      .ap-pager{padding:16px;display:flex;flex-wrap:wrap;justify-content:center;gap:6px;border-top:1px solid #e1edf6}
      .ap-pager a,.ap-pager span{min-width:36px;padding:7px 11px;text-align:center;color:#015b8c;background:#fff;border:1px solid #b8d2e5;border-radius:8px;font-size:.84rem;text-decoration:none}
      .ap-pager a:hover{background:#eaf3fa}.ap-pager .on{color:#fff;background:#0284c7;border-color:#0284c7}.ap-pager .ap-gap{border:0;background:none}

      /* ---------- Cancel dialog ---------- */
      .ap-dialog{width:min(92vw,480px);padding:0;border:0;border-radius:16px;box-shadow:0 22px 56px rgba(0,46,74,.32);overflow:hidden}
      .ap-dialog::backdrop{background:rgba(0,30,50,.48)}
      .ap-dialog-head{padding:15px 22px;color:#fff;background:linear-gradient(105deg,#015b8c,#002e4a)}.ap-dialog-head h2{margin:0;font-size:1.05rem}
      .ap-dialog-body{padding:20px 22px 6px;display:grid;gap:16px}
      .ap-dialog-summary{padding:13px 15px;display:grid;gap:2px;background:#f3f9fe;border:1px solid #d7e8f5;border-radius:12px}
      .ap-dialog-summary strong{color:#002e4a}.ap-dialog-summary span{color:#42637e;font-size:.86rem}
      .ap-dialog-note{margin:0;color:#5a7a92;font-size:.8rem}
      .ap-dialog-actions{padding:16px 22px 22px;display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px}

      /* ---------- Responsive ---------- */
      @media(max-width:1100px){.ap-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ap-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.ap-search{grid-column:1/-1}}
      @media(max-width:760px){
        .ap-filters{grid-template-columns:1fr 1fr;padding:14px}
        .ap-body{min-height:360px;padding:12px}
        .ap-table-wrap{overflow:visible;padding:12px}
        .ap-body > .ap-table-wrap{min-height:360px;padding:0}
        .ap-table{min-width:0}.ap-table thead{display:none}
        .ap-table-empty{display:none}
        .ap-table,.ap-table tbody,.ap-table tr,.ap-table td{display:block;width:100%}
        .ap-table tr{margin:0 0 12px;overflow:hidden;background:#fff;border:1px solid #d7e8f5;border-radius:14px}
        .ap-table td{padding:11px 15px;display:flex;align-items:center;justify-content:space-between;gap:16px;text-align:right}
        .ap-table td::before{content:attr(data-label);flex:none;color:#42637e;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;text-align:left}
        .ap-table td:last-child{border-bottom:0}
        .ap-col-id,.ap-col-actions{width:auto}
        .ap-pet{min-width:0}
        .ap-table .ap-when{align-items:flex-end}
        .ap-chips{min-width:0;justify-content:flex-end}
        .ap-actions{justify-content:flex-end}
      }
      @media(max-width:520px){.ap-stats,.ap-filters{grid-template-columns:1fr}.ap-search{grid-column:auto}}
      /* =========== Enhancements (same blue concept, same font sizes) =========== */
      .ap-panel{border:1px solid #d7e8f5;border-radius:20px;overflow:hidden;background:#fff;box-shadow:0 10px 28px rgba(0,46,74,.08)}
      .ap-filters{background:linear-gradient(180deg,#f7fbfe,#eff7fc)}
      .ap-field input,.ap-field select{transition:border-color .15s,box-shadow .15s}
      .ap-field input:hover,.ap-field select:hover{border-color:#7fb4d6}
      .ap-field input:focus,.ap-field select:focus,.ap-field textarea:focus{box-shadow:0 0 0 4px rgba(2,132,199,.14)}
      .ap-resultbar{background:#fff}

      /* table: soft zebra rows + status-colored left edge */
      .ap-table tbody tr:nth-child(even) td{background:#fafdff}
      .ap-table tbody tr:hover td{background:#eef6fc}
      .ap-table tbody td:first-child{box-shadow:inset 4px 0 0 var(--row,#b8d2e5)}
      .ap-table tbody tr[data-status="Pending"]{--row:#c48621}
      .ap-table tbody tr[data-status="Scheduled"]{--row:#0284c7}
      .ap-table tbody tr[data-status="Waiting"],.ap-table tbody tr[data-status="Check-in"]{--row:#6550a4}
      .ap-table tbody tr[data-status="In Consultation"]{--row:#0e7490}
      .ap-table tbody tr[data-status="Complete"]{--row:#23935b}
      .ap-table tbody tr[data-status="Cancelled"]{--row:#94a3b8}
      .ap-table tbody tr[data-status="Rejected"]{--row:#c0392b}
      .ap-table tbody tr[data-status="Cancelled"] td:not(:last-child),.ap-table tbody tr[data-status="Rejected"] td:not(:last-child){opacity:.78}
      .ap-table thead th:first-child{padding-left:20px}
      .ap-table tbody td:first-child{padding-left:20px}

      /* pet avatar + chips */
      .ap-avatar{background:linear-gradient(135deg,#e4f1fb,#c4e0f3);border:2px solid #fff;box-shadow:0 0 0 1px #b8d2e5,0 3px 8px rgba(0,46,74,.12)}
      .ap-chip{border:1px solid #d3e6f4;background:#f0f7fc}

      /* action buttons */
      .ap-actions .button{transition:transform .12s,box-shadow .12s}
      .ap-actions .button:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,46,74,.16)}
      .ap-actions .button:focus-visible{outline:3px solid rgba(2,132,199,.35);outline-offset:2px}

      /* scrollbar in the table area */
      .ap-table-wrap{scrollbar-width:thin;scrollbar-color:#9fc5df #eef6fc}
      .ap-table-wrap::-webkit-scrollbar{height:10px}
      .ap-table-wrap::-webkit-scrollbar-track{background:#eef6fc}
      .ap-table-wrap::-webkit-scrollbar-thumb{background:#9fc5df;border-radius:999px;border:2px solid #eef6fc}
      .ap-table-wrap::-webkit-scrollbar-thumb:hover{background:#6ea8cf}

      /* empty state */
      .ap-empty-icon{width:64px;height:64px;margin-bottom:6px;display:grid;place-items:center;color:#4f7fa3;background:linear-gradient(135deg,#eaf4fb,#d4e8f6);border:1px solid #c4dcec;border-radius:50%}
      .ap-table-empty thead th{border-bottom-width:2px}

      /* pager */
      .ap-pager a,.ap-pager span{border-radius:10px;transition:background-color .15s,transform .12s}
      .ap-pager a:hover{transform:translateY(-1px)}
      .ap-pager .on{box-shadow:0 3px 8px rgba(2,132,199,.3)}

      @media(max-width:760px){
        .ap-table tbody tr{border-left:4px solid var(--row,#b8d2e5);box-shadow:0 2px 8px rgba(0,46,74,.06)}
        .ap-table tbody td:first-child{box-shadow:none;padding-left:15px}
        .ap-table tbody tr:nth-child(even) td,.ap-table tbody tr:hover td{background:transparent}
      }
      @media(prefers-reduced-motion:reduce){.ap-stat,.ap-actions .button,.ap-pager a{transition:none}.ap-stat:hover,.ap-actions .button:hover,.ap-pager a:hover{transform:none}.ap-loadbar{animation:none}}

      .panel-actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
      .ap-views{padding:3px;display:flex;gap:3px;background:rgba(255,255,255,.18);border-radius:12px}
      .ap-view-btn{padding:6px 14px;color:#fff;background:transparent;border:0;border-radius:9px;font:inherit;font-size:.84rem;font-weight:700;cursor:pointer}
      .ap-view-btn[aria-pressed="true"]{color:#015b8c;background:#fff}
      .ap-view-btn:focus-visible{outline:3px solid rgba(255,255,255,.7);outline-offset:2px}
      .ap-tabs{padding:0 20px;display:flex;gap:4px;overflow-x:auto;background:#fff;border-bottom:1px solid #e1edf6;scrollbar-width:thin}
      .ap-tab{flex:none;padding:14px 14px 12px;display:inline-flex;align-items:center;gap:8px;color:#5a7a92;background:transparent;border:0;border-bottom:3px solid transparent;font:inherit;font-size:.9rem;font-weight:700;cursor:pointer;white-space:nowrap}
      .ap-tab:hover,.ap-tab.is-active{color:#015b8c}
      .ap-tab.is-active{border-bottom-color:#0284c7}
      .ap-tab:focus-visible{outline:3px solid rgba(2,132,199,.35);outline-offset:-3px}
      .ap-tab-n{min-width:1.5rem;padding:1px 8px;color:#42637e;background:#eaf3fa;border-radius:999px;font-size:.74rem;font-weight:800;text-align:center}
      .ap-tab.is-active .ap-tab-n{color:#fff;background:#0284c7}
      .ap-tab.needs-action:not(.is-active) .ap-tab-n{color:#7a5a00;background:#fdf0c4}
      .ap-field[hidden],#ap-cal[hidden],#ap-results[hidden]{display:none}
      .ap-filters.is-calendar{grid-template-columns:minmax(240px,2.2fr) minmax(180px,1fr) auto}
      #ap-cal{padding:18px 20px 22px}
      .cal-toolbar{margin-bottom:14px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
      .cal-nav{display:flex;align-items:center;gap:.4rem}
      .cal-nav-btn,.cal-today-btn{height:2.2rem;color:#123d5c;background:#f1f8fd;border:1px solid #b9d3e6;border-radius:10px;font:inherit;font-weight:700;cursor:pointer}
      .cal-nav-btn{width:2.2rem;padding:0;font-size:1.1rem}
      .cal-today-btn{padding:0 .9rem;background:#fff;font-size:.85rem}
      .cal-nav-btn:hover,.cal-today-btn:hover{background:#e0f2fe;border-color:#0284c7}
      .cal-nav-btn:focus-visible,.cal-today-btn:focus-visible{outline:3px solid rgba(2,132,199,.4);outline-offset:2px}
      .cal-title{min-width:9rem;margin:0 .4rem;color:#123d5c;font-size:1.2rem;font-weight:800}
      .cal-holiday{margin-left:.5rem;padding:3px 10px;color:#9b2c24;background:#fbe0dd;border-radius:999px;font-size:.74rem;font-weight:800;vertical-align:middle}
      .cal-legend{display:flex;flex-wrap:wrap;gap:.9rem;color:#56738a;font-size:.76rem;font-weight:600}
      .cal-legend i{width:.8rem;height:.8rem;margin-right:.35rem;display:inline-block;border-radius:3px;vertical-align:-1px}
      .cal-frame{position:relative;min-height:320px;overflow:hidden;background:#fff;border:1px solid #d5e5f1;border-radius:14px}
      .cal-frame.is-loading{opacity:.55;transition:opacity .15s}
      .cal-msg{padding:3rem 1rem;color:#56738a;text-align:center}
      .cal-dow,.cal-cells{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
      .cal-dow{background:#f7fafc;border-bottom:1px solid #d5e5f1}
      .cal-dow span{padding:.55rem .4rem;color:#56738a;font-size:.78rem;font-weight:800;text-align:center}
      .cal-cell{min-width:0;min-height:7.2rem;padding:.35rem;display:flex;flex-direction:column;gap:3px;border-right:1px solid #e3edf5;border-bottom:1px solid #e3edf5}
      .cal-cell:nth-child(7n){border-right:0}
      .cal-cell.is-out{background:#fafcfd}
      .cal-cell.is-out .cal-num{color:#a9bccb}
      .cal-num{width:1.7rem;height:1.7rem;padding:0;display:inline-grid;place-items:center;color:#123d5c;background:transparent;border:0;border-radius:50%;font:inherit;font-size:.82rem;font-weight:700;cursor:pointer}
      .cal-num:hover{background:#e0f2fe}
      .cal-cell.is-today .cal-num{color:#fff;background:#0284c7}
      .ap-ev{min-width:0;padding:.18rem .45rem;display:block;overflow:hidden;color:#fff;background:var(--event-color,#0284c7);border:0;border-radius:6px;font:inherit;font-size:.72rem;font-weight:700;text-align:left;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
      .ap-ev:hover,.ap-blk:hover{filter:brightness(1.08)}
      .ap-ev:focus-visible,.ap-blk:focus-visible{outline:3px solid rgba(2,132,199,.45);outline-offset:1px}
      .cal-more{padding:.1rem .3rem;color:#075985;background:transparent;border:0;font:inherit;font-size:.72rem;font-weight:800;text-align:left;cursor:pointer}
      .cal-more:hover{text-decoration:underline}
      .ap-ev[data-status="Pending"],.ap-blk[data-status="Pending"]{--event-color:#c48621}
      .ap-ev[data-status="Scheduled"],.ap-blk[data-status="Scheduled"]{--event-color:#0284c7}
      .ap-ev[data-status="Waiting"],.ap-ev[data-status="Check-in"],.ap-blk[data-status="Waiting"],.ap-blk[data-status="Check-in"]{--event-color:#6550a4}
      .ap-ev[data-status="In Consultation"],.ap-blk[data-status="In Consultation"]{--event-color:#0e7490}
      .ap-ev[data-status="Complete"],.ap-blk[data-status="Complete"]{--event-color:#23935b}
      .ap-ev[data-status="Cancelled"],.ap-blk[data-status="Cancelled"]{--event-color:#94a3b8}
      .ap-ev[data-status="Rejected"],.ap-blk[data-status="Rejected"]{--event-color:#c0392b}
      .cal-day-scroll{overflow-x:auto}
      .cal-day-grid{min-width:min(100%,640px);display:grid}
      .cal-day-head{padding:.6rem .5rem;overflow:hidden;color:#123d5c;background:#f7fafc;border-bottom:1px solid #d5e5f1;border-left:1px solid #e3edf5;font-size:.84rem;font-weight:800;text-align:center;text-overflow:ellipsis;white-space:nowrap}
      .cal-day-head:first-child{border-left:0}
      .cal-times{position:relative}
      .cal-times span{position:absolute;right:.5rem;transform:translateY(-50%);color:#56738a;font-size:.68rem;font-weight:700;white-space:nowrap}
      .cal-col{position:relative;border-left:1px solid #e3edf5;background-image:repeating-linear-gradient(to bottom,transparent 0,transparent 55px,#e3edf5 55px,#e3edf5 56px)}
      .cal-off{position:absolute;z-index:1;right:0;left:0;background:repeating-linear-gradient(135deg,#f1f5f8 0 6px,#e8eef3 6px 12px);pointer-events:none}
      .cal-off.full{display:grid;place-items:center;color:#7b97ad;font-size:.78rem;font-weight:800}
      .cal-rest{position:absolute;z-index:1;right:0;left:0;background:repeating-linear-gradient(45deg,#fffbeb 0 5px,#fdf0c4 5px 10px);border-block:1px solid #f1d98a;pointer-events:none}
      .ap-blk{position:absolute;z-index:2;right:4px;left:4px;padding:.25rem .45rem;display:flex;flex-direction:column;align-items:flex-start;overflow:hidden;color:#fff;background:var(--event-color,#0284c7);border:0;border-radius:8px;font:inherit;font-size:.74rem;font-weight:700;line-height:1.3;text-align:left;cursor:pointer;box-shadow:0 2px 6px rgba(0,46,74,.2)}
      .ap-blk small{font-size:.68rem;font-weight:600;opacity:.9}
      .ap-detail{margin:0;display:grid;gap:10px}
      .ap-detail div{display:grid;grid-template-columns:7rem 1fr;gap:10px}
      .ap-detail dt{padding-top:2px;color:#5a7a92;font-size:.72rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase}
      .ap-detail dd{margin:0;color:#092947;font-size:.92rem;font-weight:600}
      .ap-visit-count{color:#eaf3fa;font-size:.82rem;font-weight:700;white-space:nowrap}
      .ap-filter-panel{margin:22px;padding:14px;background:#f3f8fc;border:1px solid #d5e7f4;border-radius:12px}
      .ap-filter-panel .ap-tabs{padding:0 0 12px;display:flex;flex-wrap:nowrap;gap:8px;overflow-x:auto;background:transparent;border:0;border-bottom:1px solid #d5e7f4}
      .ap-filter-panel .ap-tab{min-height:38px;padding:7px 12px;color:#315d7c;background:#fff;border:1px solid #d5e7f4;border-radius:999px;font-size:.82rem}
      .ap-filter-panel .ap-tab:hover:not(.is-active){color:#015b8c;background:#fff;border-color:#9fc5df}
      .ap-filter-panel .ap-tab.is-active{color:#fff;background:#0284c7;border-color:#0284c7}
      .ap-filter-panel .ap-tab-n{min-width:1.35rem;padding:1px 7px;background:#eaf3fa}
      .ap-filter-panel .ap-tab.is-active .ap-tab-n{color:#015b8c;background:#d9edf9}
      .ap-filter-panel .ap-filters{padding:14px 0 0;display:grid;grid-template-columns:minmax(240px,1fr) repeat(3,minmax(150px,160px)) 72px;align-items:end;gap:12px;border:0;background:transparent}
      .ap-filter-panel .ap-filters.is-calendar{grid-template-columns:minmax(0,2.2fr) minmax(180px,1fr) auto}
      .ap-filter-panel .ap-field{width:100%;min-width:0;gap:6px;line-height:1.25}
      .ap-filter-panel .ap-field input,.ap-filter-panel .ap-field select{box-sizing:border-box;width:100%;height:44px;min-height:44px}
      .ap-filter-panel .ap-field select{-webkit-appearance:none;appearance:none;padding-right:38px;background-image:url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 16%22%3E%3Cpath d=%22m4 6 4 4 4-4%22 fill=%22none%22 stroke=%22%23092947%22 stroke-width=%221.7%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 13px center;background-size:14px}
      .ap-filter-panel .ap-reset{min-width:72px;height:44px;min-height:44px;align-self:end;justify-content:center}
      .ap-filter-panel .ap-resultbar{padding:8px 0 0;color:#42637e;background:transparent;border:0;font-size:.78rem;text-align:right}
      @media(max-width:1100px){
        .ap-filter-panel .ap-filters{grid-template-columns:minmax(0,1fr) repeat(3,minmax(115px,140px)) 72px}
        .ap-filter-panel .ap-search{grid-column:auto}
        .ap-filter-panel .ap-filters.is-calendar{grid-template-columns:minmax(0,1.5fr) minmax(130px,1fr) auto}
        .ap-filter-panel .ap-filters.is-calendar .ap-search{grid-column:auto}
      }
      @media(max-width:760px){
        .ap-filter-panel{margin:14px;padding:12px}
        .ap-filter-panel .ap-filters{grid-template-columns:repeat(2,minmax(0,1fr));padding-top:12px}
        .ap-filter-panel .ap-search{grid-column:1/-1}
        .ap-filter-panel .ap-reset{justify-self:end}
        .ap-filter-panel .ap-filters.is-calendar{grid-template-columns:repeat(2,minmax(0,1fr));padding-top:12px}
        .ap-filter-panel .ap-filters.is-calendar .ap-search{grid-column:1/-1}
        #ap-cal{padding:14px 12px 18px}
        .cal-cell{min-height:5.4rem;padding:.25rem}
        .ap-ev{padding:.12rem .25rem;font-size:.62rem}
        .cal-title{min-width:0;font-size:1.05rem}
        .cal-legend{gap:.5rem}
      }
      @media(max-width:520px){
        .ap-filter-panel{margin:12px;padding:10px}
        .ap-filter-panel .ap-filters{grid-template-columns:1fr}
        .ap-filter-panel .ap-search{grid-column:auto}
        .ap-filter-panel .ap-reset{justify-self:stretch}
        .ap-filter-panel .ap-filters.is-calendar{grid-template-columns:1fr}
        .ap-filter-panel .ap-filters.is-calendar .ap-search{grid-column:auto}
        .ap-detail div{grid-template-columns:1fr;gap:2px}
      }
    </style>
    <script>
    (function () {
      function $(id) { return document.getElementById(id); }
      function pad(number) { return ('0' + number).slice(-2); }
      function ymd(date) { return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()); }
      function parseYmd(value) {
        var parts = value.split('-');
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
      }
      function toMin(time) {
        var parts = time.split(':');
        return Number(parts[0]) * 60 + Number(parts[1]);
      }
      function shortClock(minutes) {
        var hour = Math.floor(minutes / 60), minute = minutes % 60;
        return (hour % 12 || 12) + (minute ? ':' + pad(minute) : '') + (hour >= 12 ? 'p' : 'a');
      }
      function hourLabel(hour) { return (hour % 12 || 12) + ' ' + (hour >= 12 && hour < 24 ? 'PM' : 'AM'); }

      var TODAY = <?= json_encode(date('Y-m-d')) ?>;
      var VETS = <?= json_encode($vetList, $jsonFlags) ?>;
      var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
      var dayShort = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      var HOUR = 56;
      var results = $('ap-results'), form = $('ap-filters'), calendar = $('ap-cal'), calendarBody = $('cal-body'), calendarTitle = $('cal-title');
      var cancelDialog = $('cancel-dialog'), detailDialog = $('detail-dialog');
      var statusInput = form.elements['status'];
      var view = 'list', cursor = parseYmd(TODAY), timer = null, listController = null, calendarController = null;
      var events = [], availability = {}, holiday = '';

      function filterParams(includeListOnly) {
        var params = new URLSearchParams();
        ['q', 'status', 'vet'].forEach(function (name) {
          var value = form.elements[name].value;
          if (value) params.set(name, value);
        });
        var idField = form.elements['appointment_id'];
        if (includeListOnly) {
          if (idField && idField.value) params.set('appointment_id', idField.value);
          ['date', 'sort'].forEach(function (name) {
            var value = form.elements[name].value;
            if (value && !(name === 'sort' && value === 'newest')) params.set(name, value);
          });
        }
        return params;
      }
      function listUrl() {
        var query = filterParams(true).toString();
        return location.pathname + (query ? '?' + query : '');
      }
      function syncChrome() {
        Array.prototype.forEach.call(document.querySelectorAll('.ap-tab'), function (tab) {
          var active = tab.dataset.status === statusInput.value && tab.dataset.date === form.elements['date'].value;
          tab.classList.toggle('is-active', active);
          tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
      }
      function syncUrl() {
        if (view === 'list') return;
        var params = filterParams(false);
        params.set('view', view);
        params.set('d', ymd(cursor));
        history.replaceState(null, '', location.pathname + '?' + params.toString());
      }
      function refresh() {
        syncChrome();
        if (view === 'list') loadList(listUrl());
        else loadCalendar();
      }
      function loadList(url) {
        if (listController) listController.abort();
        listController = window.AbortController ? new AbortController() : null;
        results.classList.add('is-loading');
        fetch(url, { credentials: 'same-origin', signal: listController ? listController.signal : undefined })
          .then(function (response) { return response.text(); })
          .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var next = doc.getElementById('ap-results');
            if (!next) { location.href = url; return; }
            results.innerHTML = next.innerHTML;
            var nextCount = doc.getElementById('ap-filter-count');
            if (nextCount) $('ap-filter-count').innerHTML = nextCount.innerHTML;
            history.replaceState(null, '', url);
            results.classList.remove('is-loading');
          })
          .catch(function (error) {
            if (error && error.name === 'AbortError') return;
            location.href = url;
          });
      }

      function monthRange() {
        var year = cursor.getFullYear(), month = cursor.getMonth();
        var offset = (new Date(year, month, 1).getDay() + 6) % 7;
        var days = new Date(year, month + 1, 0).getDate();
        var weeks = Math.ceil((offset + days) / 7);
        var start = new Date(year, month, 1 - offset);
        return { start: start, weeks: weeks, end: new Date(year, month, 1 - offset + weeks * 7 - 1) };
      }
      function loadCalendar() {
        var from, to;
        if (view === 'month') {
          var range = monthRange();
          from = range.start;
          to = range.end;
        } else {
          from = to = cursor;
        }
        var params = filterParams(false);
        params.set('calendar', '1');
        params.set('from', ymd(from));
        params.set('to', ymd(to));
        if (calendarController) calendarController.abort();
        calendarController = window.AbortController ? new AbortController() : null;
        calendarBody.classList.add('is-loading');
        fetch(location.pathname + '?' + params.toString(), {
          credentials: 'same-origin',
          signal: calendarController ? calendarController.signal : undefined
        })
          .then(function (response) {
            if (!response.ok) throw new Error('Calendar request failed.');
            return response.json();
          })
          .then(function (data) {
            events = data.appointments || [];
            availability = data.availability || {};
            holiday = data.holiday || '';
            calendarBody.classList.remove('is-loading');
            renderCalendar();
          })
          .catch(function (error) {
            if (error && error.name === 'AbortError') return;
            calendarBody.classList.remove('is-loading');
            calendarBody.innerHTML = '<div class="cal-msg">The calendar could not be loaded. Reload the page and try again.</div>';
          });
        syncUrl();
      }
      function chipLabel(event) { return shortClock(toMin(event.start)) + ' ' + event.pet; }
      function renderMonth() {
        var year = cursor.getFullYear(), month = cursor.getMonth(), range = monthRange();
        calendarTitle.textContent = monthNames[month] + ' ' + year;
        var byDay = {};
        events.forEach(function (event) {
          (byDay[event.date] = byDay[event.date] || []).push(event);
        });
        var html = '<div class="cal-dow">' + dayShort.map(function (name) { return '<span>' + name + '</span>'; }).join('') + '</div><div class="cal-cells">';
        for (var index = 0; index < range.weeks * 7; index++) {
          var date = new Date(range.start.getFullYear(), range.start.getMonth(), range.start.getDate() + index);
          var key = ymd(date), dayEvents = byDay[key] || [];
          html += '<div class="cal-cell' + (date.getMonth() !== month ? ' is-out' : '') + (key === TODAY ? ' is-today' : '') + '">' +
            '<button type="button" class="cal-num" data-goto-day="' + key + '" aria-label="Open ' + key + ' by veterinarian">' + date.getDate() + '</button>';
          dayEvents.slice(0, 3).forEach(function (event) {
            html += '<button type="button" class="ap-ev" data-status="' + escAttr(event.status) + '" data-id="' + event.id + '" title="' +
              escAttr(event.timeLabel + ' · ' + event.pet + ' · ' + (event.vet || 'Unassigned')) + '">' + esc(chipLabel(event)) + '</button>';
          });
          if (dayEvents.length > 3) html += '<button type="button" class="cal-more" data-goto-day="' + key + '">+' + (dayEvents.length - 3) + ' more</button>';
          html += '</div>';
        }
        calendarBody.innerHTML = html + '</div>';
      }
      function renderDay() {
        var vetValue = form.elements['vet'].value;
        calendarTitle.textContent = dayShort[(cursor.getDay() + 6) % 7] + ', ' + monthNames[cursor.getMonth()].slice(0, 3) + ' ' +
          cursor.getDate() + ', ' + cursor.getFullYear();
        if (holiday) {
          var holidayTag = document.createElement('span');
          holidayTag.className = 'cal-holiday';
          holidayTag.textContent = 'Closed: ' + holiday;
          calendarTitle.appendChild(holidayTag);
        }
        var columns;
        if (vetValue === 'none') columns = [];
        else if (vetValue) columns = VETS.filter(function (vet) { return String(vet.id) === vetValue; });
        else columns = VETS.slice();
        if (vetValue === 'none' || events.some(function (event) { return !event.vetId; })) {
          columns.push({ id: 0, name: 'Unassigned' });
        }
        if (!columns.length) {
          calendarBody.innerHTML = '<div class="cal-msg">No veterinarians to show.</div>';
          return;
        }

        var startMinute = 8 * 60, endMinute = 18 * 60;
        events.forEach(function (event) {
          startMinute = Math.min(startMinute, toMin(event.start));
          endMinute = Math.max(endMinute, toMin(event.start) + event.minutes);
        });
        columns.forEach(function (vet) {
          var hours = availability[vet.id];
          if (hours && !hours.leave && hours.start) {
            startMinute = Math.min(startMinute, toMin(hours.start));
            endMinute = Math.max(endMinute, toMin(hours.end));
          }
        });
        var startHour = Math.max(0, Math.floor(startMinute / 60));
        var endHour = Math.min(24, Math.ceil(endMinute / 60));
        var height = (endHour - startHour) * HOUR, dayStart = startHour * 60;
        function px(minute) { return (minute - dayStart) / 60 * HOUR; }

        var html = '<div class="cal-day-scroll"><div class="cal-day-grid" style="grid-template-columns:3.6rem repeat(' + columns.length + ',minmax(11rem,1fr))">';
        html += '<div class="cal-day-head"></div>';
        columns.forEach(function (vet) {
          html += '<div class="cal-day-head" title="' + escAttr(vet.name) + '">' + esc(vet.name) + '</div>';
        });
        html += '<div class="cal-times" style="height:' + height + 'px">';
        for (var hour = startHour + 1; hour < endHour; hour++) {
          html += '<span style="top:' + ((hour - startHour) * HOUR) + 'px">' + hourLabel(hour) + '</span>';
        }
        html += '</div>';
        columns.forEach(function (vet) {
          var hours = availability[vet.id], inner = '';
          if (vet.id !== 0) {
            if (holiday) {
              inner += '<div class="cal-off full" style="top:0;height:' + height + 'px">Clinic closed</div>';
            } else if (hours && hours.leave) {
              inner += '<div class="cal-off full" style="top:0;height:' + height + 'px">On leave</div>';
            } else if (!hours || !hours.start) {
              inner += '<div class="cal-off full" style="top:0;height:' + height + 'px">Day off</div>';
            } else {
              var workStart = toMin(hours.start), workEnd = toMin(hours.end);
              if (px(workStart) > 0) inner += '<div class="cal-off" style="top:0;height:' + px(workStart) + 'px"></div>';
              if (px(workEnd) < height) inner += '<div class="cal-off" style="top:' + px(workEnd) + 'px;height:' + (height - px(workEnd)) + 'px"></div>';
              (hours.breaks || []).forEach(function (breakTime) {
                inner += '<div class="cal-rest" style="top:' + px(toMin(breakTime[0])) + 'px;height:' +
                  (px(toMin(breakTime[1])) - px(toMin(breakTime[0]))) + 'px" title="Break"></div>';
              });
            }
          }
          events.filter(function (event) { return event.vetId === vet.id; }).forEach(function (event) {
            var start = toMin(event.start), blockHeight = Math.max(event.minutes / 60 * HOUR, 30);
            inner += '<button type="button" class="ap-blk" data-status="' + escAttr(event.status) + '" data-id="' + event.id +
              '" style="top:' + px(start) + 'px;height:' + blockHeight + 'px"><span>' +
              esc(shortClock(start) + '–' + shortClock(start + event.minutes) + ' ' + event.pet) + '</span><small>' +
              esc(event.owner) + '</small></button>';
          });
          html += '<div class="cal-col" style="height:' + height + 'px">' + inner + '</div>';
        });
        calendarBody.innerHTML = html + '</div></div>';
      }
      function renderCalendar() {
        if (view === 'month') renderMonth();
        else renderDay();
      }
      function esc(text) {
        return String(text).replace(/[&<>"']/g, function (character) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
      }
      function escAttr(text) { return esc(text); }

      function setView(nextView) {
        view = nextView;
        Array.prototype.forEach.call(document.querySelectorAll('.ap-view-btn'), function (button) {
          button.setAttribute('aria-pressed', button.dataset.view === view ? 'true' : 'false');
        });
        var isList = view === 'list';
        results.hidden = !isList;
        calendar.hidden = isList;
        form.classList.toggle('is-calendar', !isList);
        Array.prototype.forEach.call(form.querySelectorAll('.ap-list-only'), function (field) { field.hidden = !isList; });
        if (isList) {
          history.replaceState(null, '', listUrl());
          syncChrome();
        } else {
          refresh();
        }
      }
      Array.prototype.forEach.call(document.querySelectorAll('.ap-view-btn'), function (button) {
        button.addEventListener('click', function () { setView(button.dataset.view); });
      });
      function step(direction) {
        if (view === 'month') cursor = new Date(cursor.getFullYear(), cursor.getMonth() + direction, 1);
        else cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + direction);
        loadCalendar();
      }
      $('cal-prev').addEventListener('click', function () { step(-1); });
      $('cal-next').addEventListener('click', function () { step(1); });
      $('cal-today').addEventListener('click', function () { cursor = parseYmd(TODAY); loadCalendar(); });
      form.addEventListener('submit', function (event) { event.preventDefault(); refresh(); });
      form.addEventListener('input', function (event) {
        if (event.target.type === 'search') {
          clearTimeout(timer);
          timer = setTimeout(refresh, 300);
        }
      });
      form.addEventListener('change', function (event) {
        if (event.target.tagName === 'SELECT') refresh();
      });
      Array.prototype.forEach.call(document.querySelectorAll('.ap-tab'), function (tab) {
        tab.addEventListener('click', function () {
          statusInput.value = tab.dataset.status;
          form.elements['date'].value = tab.dataset.date;
          refresh();
        });
      });

      function openAppointmentAction(action, id, pet, owner, when) {
        var rejecting = action === 'reject';
        $('cancel-id').value = id;
        $('cancel-action').value = action;
        $('cancel-pet').textContent = pet;
        $('cancel-owner').textContent = 'Owner: ' + owner;
        $('cancel-when').textContent = when;
        $('cancel-title').textContent = rejecting ? 'Reject appointment request' : 'Cancel appointment';
        $('cancel-reason').required = rejecting;
        $('cancel-reason').value = '';
        $('cancel-reason').placeholder = rejecting ? 'Explain why this request cannot be approved.' : 'e.g. Veterinarian unavailable, please rebook.';
        $('cancel-reason-hint').textContent = rejecting ? '(required, shared with the owner)' : '(optional, shared with the owner)';
        $('cancel-note').textContent = rejecting
          ? 'The pet owner and assigned veterinarian will be notified of the rejection.'
          : 'The time slot is released and the pet owner and veterinarian are notified.';
        $('cancel-confirm').textContent = rejecting ? 'Reject request' : 'Confirm cancellation';
        cancelDialog.showModal();
      }
      function openDetail(id) {
        var appointment = events.filter(function (event) { return event.id === id; })[0];
        if (!appointment) return;
        $('det-title').textContent = 'Appointment #' + appointment.id;
        $('det-when').textContent = appointment.when + ' · ' + appointment.minutes + ' min';
        $('det-pet').textContent = appointment.pet;
        $('det-owner').textContent = appointment.owner;
        $('det-vet').textContent = appointment.vet || 'To be assigned';
        $('det-services').textContent = appointment.services || '—';
        $('det-payment').textContent = appointment.payment || '—';
        $('det-status').textContent = appointment.status;
        $('det-approve').hidden = !appointment.canApprove;
        $('det-approve-id').value = appointment.id;
        var cancelButton = $('det-cancel');
        cancelButton.hidden = !appointment.canCancel;
        cancelButton.onclick = function () {
          detailDialog.close();
          openAppointmentAction('cancel', appointment.id, appointment.pet, appointment.owner, appointment.when);
        };
        detailDialog.showModal();
      }
      calendar.addEventListener('click', function (event) {
        var item = event.target.closest('[data-id]');
        if (item) {
          openDetail(Number(item.dataset.id));
          return;
        }
        var day = event.target.closest('[data-goto-day]');
        if (day) {
          cursor = parseYmd(day.dataset.gotoDay);
          setView('day');
        }
      });
      results.addEventListener('click', function (event) {
        var page = event.target.closest('.ap-pager a');
        if (page) {
          event.preventDefault();
          loadList(page.href);
          window.scrollTo({ top: 0 });
          return;
        }
        var button = event.target.closest('.cancel-appt, .reject-appt');
        if (button) openAppointmentAction(button.classList.contains('reject-appt') ? 'reject' : 'cancel', button.dataset.id, button.dataset.pet, button.dataset.owner, button.dataset.when);
      });
      $('det-close').addEventListener('click', function () { detailDialog.close(); });
      $('cancel-keep').addEventListener('click', function () { cancelDialog.close(); });
      function lockForm(formElement, label) {
        formElement.addEventListener('submit', function () {
          var button = formElement.querySelector('button[type="submit"]');
          if (button) setTimeout(function () { button.disabled = true; button.textContent = label; }, 0);
        });
      }
      results.addEventListener('submit', function (event) {
        if (event.target.classList.contains('ap-approve-form')) {
          var button = event.target.querySelector('button[type="submit"]');
          if (button) setTimeout(function () { button.disabled = true; button.textContent = 'Approving…'; }, 0);
        }
      });
      lockForm(cancelDialog.querySelector('form'), 'Submitting…');
      lockForm($('det-approve'), 'Approving…');

      var initial = new URLSearchParams(location.search);
      var startDate = initial.get('d');
      if (startDate && /^\d{4}-\d{2}-\d{2}$/.test(startDate)) cursor = parseYmd(startDate);
      syncChrome();
      var startView = initial.get('view');
      if (startView === 'month' || startView === 'day') setView(startView);
    })();
    </script>
    <?php
});