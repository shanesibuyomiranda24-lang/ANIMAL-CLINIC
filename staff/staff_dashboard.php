<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_once __DIR__ . '/../includes/pet_photos.php';
require_role(['staff']);

$appointmentFlash = (string) ($_SESSION['staff_appointment_flash'] ?? '');
unset($_SESSION['staff_appointment_flash']);

role_render('staff', ['dashboard'], 'Staff Dashboard', 'Daily clinic activity', function (array $context) use ($appointmentFlash): void {
    $pdo = $context['pdo'];
    $today = date('Y-m-d');
    $todayStart = $today . ' 00:00:00';
    $tomorrowStart = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';
    $now = date('Y-m-d H:i:s');
    $currentTime = date('H:i:s');
    $weekday = (int) date('N');

    $accountStatement = $pdo->prepare(
        "SELECT s.`role` AS staff_role, g.groomer_id
         FROM Users u
         JOIN Staff s ON s.staff_id = u.staff_id
         LEFT JOIN Groomers g ON g.user_id = u.user_id AND g.is_available = 1
         WHERE u.user_id = ? AND u.status = 'active'
         LIMIT 1"
    );
    $accountStatement->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $account = $accountStatement->fetch() ?: [];
    $isGroomer = ($account['staff_role'] ?? '') === 'Groomer';
    $groomerId = (int) ($account['groomer_id'] ?? 0);
    $hasGroomerSchedule = false;
    if ($isGroomer && $groomerId > 0) {
        $scheduleStatement = $pdo->prepare(
            'SELECT EXISTS (SELECT 1 FROM GroomerSchedules WHERE groomer_id = ?)'
        );
        $scheduleStatement->execute([$groomerId]);
        $hasGroomerSchedule = (bool) $scheduleStatement->fetchColumn();
    }

    $todayScope = '';
    $todayParameters = [$todayStart, $tomorrowStart];
    if ($isGroomer) {
        if ($hasGroomerSchedule) {
            $assignedGroomingScope = " AND EXISTS (
                    SELECT 1
                    FROM AppointmentServices grooming_aps
                    JOIN Services grooming_service ON grooming_service.service_id = grooming_aps.service_id
                    WHERE grooming_aps.appointment_id = a.appointment_id
                      AND grooming_aps.groomer_id = ?
                      AND grooming_service.service_category = 'Grooming & Hygiene'
                )";
            $todayScope = $assignedGroomingScope;
            $todayParameters[] = $groomerId;
        } else {
            $todayScope = ' AND 1 = 0';
        }
    }

    $todayStatusScope = $isGroomer ? " AND a.status <> 'Pending'" : '';
    $todayLimit = $isGroomer ? '' : 'LIMIT 8';
    $appointmentsStatement = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.status,
                p.pet_id, p.pet_name, p.photo_updated_at, o.full_name AS owner_name,
                v.full_name AS vet_name
         FROM Appointments a
         JOIN Pets p ON p.pet_id = a.pet_id
         JOIN PetOwners o ON o.owner_id = p.owner_id
         LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
         WHERE a.appointment_date >= ? AND a.appointment_date < ?
           $todayScope
           AND a.status NOT IN ('Cancelled','Rejected')
           $todayStatusScope
         ORDER BY a.appointment_date
         $todayLimit"
    );
    $appointmentsStatement->execute($todayParameters);
    $appointments = $appointmentsStatement->fetchAll();
    $showTodayAppointments = !$isGroomer || ($hasGroomerSchedule && !empty($appointments));
    $groomerDashboardStats = ['waiting' => 0, 'active' => 0, 'complete' => 0];
    if ($isGroomer) {
        $groomerStatsStatement = $pdo->prepare(
            "SELECT
                COUNT(CASE WHEN a.status = 'Check-in' THEN 1 END) AS waiting,
                COUNT(CASE WHEN a.status = 'In Consultation' THEN 1 END) AS active,
                COUNT(CASE WHEN a.pickup_ready_at IS NOT NULL THEN 1 END) AS complete
             FROM Appointments a
             WHERE EXISTS (
                SELECT 1
                FROM AppointmentServices grooming_aps
                JOIN Services grooming_service ON grooming_service.service_id = grooming_aps.service_id
                WHERE grooming_aps.appointment_id = a.appointment_id
                  AND grooming_aps.groomer_id = ?
                  AND grooming_service.service_category = 'Grooming & Hygiene'
             )"
        );
        $groomerStatsStatement->execute([$groomerId]);
        $groomerDashboardStats = $groomerStatsStatement->fetch() ?: $groomerDashboardStats;
    }

    $pendingCount = 0;
    $pendingAppointments = [];
    if (!$isGroomer) {
        $pendingCountStatement = $pdo->query("SELECT COUNT(*) FROM Appointments WHERE status = 'Pending'");
        $pendingCount = (int) $pendingCountStatement->fetchColumn();

        $pendingAppointmentsStatement = $pdo->query(
            "SELECT a.appointment_id, a.appointment_date, p.pet_name, o.full_name AS owner_name,
                    v.full_name AS vet_name,
                    COALESCE(GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.service_name SEPARATOR ', '), '') AS services
             FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id
             JOIN PetOwners o ON o.owner_id = p.owner_id
             LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
             LEFT JOIN AppointmentServices aps ON aps.appointment_id = a.appointment_id
             LEFT JOIN Services s ON s.service_id = aps.service_id
             WHERE a.status = 'Pending'
             GROUP BY a.appointment_id, a.appointment_date, p.pet_name, o.full_name, v.full_name
             ORDER BY a.appointment_date
             LIMIT 4"
        );
        $pendingAppointments = $pendingAppointmentsStatement->fetchAll();
    }

    $availabilityStatement = $pdo->prepare(
        "SELECT v.vet_id, v.full_name, v.is_available, v.max_appointments_per_day,
                s.start_time, s.end_time,
                (SELECT COUNT(*) FROM Appointments a
                 WHERE a.vet_id = v.vet_id AND a.appointment_date >= ? AND a.appointment_date < ?
                   AND a.status IN ('Scheduled','Waiting','Check-in','In Consultation','Complete')) AS appointments_today,
                (SELECT COUNT(*) FROM Appointments a
                 WHERE a.vet_id = v.vet_id
                   AND a.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation')
                   AND (a.status = 'In Consultation'
                        OR (a.appointment_date <= ?
                            AND DATE_ADD(a.appointment_date, INTERVAL a.duration_minutes MINUTE) > ?))) AS active_now,
                EXISTS (SELECT 1 FROM VetLeaves l
                        WHERE l.vet_id = v.vet_id AND l.status = 'Approved'
                          AND l.start_date <= ? AND l.end_date >= ?) AS on_approved_leave,
                EXISTS (SELECT 1 FROM ClinicHolidays h WHERE h.holiday_date = ?) AS clinic_holiday,
                EXISTS (SELECT 1 FROM VetScheduleBreaks b
                        WHERE b.vet_id = v.vet_id AND b.day_of_week = ?
                          AND b.start_time <= ? AND b.end_time > ?) AS on_break
         FROM Veterinarians v
         LEFT JOIN VetSchedules s ON s.vet_id = v.vet_id AND s.day_of_week = ?
         ORDER BY v.full_name"
    );
    $availabilityStatement->execute([
        $todayStart,
        $tomorrowStart,
        $now,
        $now,
        $today,
        $today,
        $today,
        $weekday,
        $currentTime,
        $currentTime,
        $weekday,
    ]);
    $veterinarians = $availabilityStatement->fetchAll();

    ?>
    <section class="clinic-banner" aria-label="<?= $isGroomer ? 'Groomer dashboard welcome' : 'Staff dashboard welcome' ?>">
      <div class="clinic-banner-copy">
        <p class="clinic-banner-eyebrow"><?= $isGroomer ? 'Groomer dashboard' : 'Front desk · patient care coordination' ?></p>
        <h2><?= $isGroomer ? 'Welcome back, ' . e($_SESSION['full_name'] ?? 'Groomer') . '!' : 'Welcome back to the clinic!' ?></h2>
        <p><?= $isGroomer ? 'Review your grooming patients, sessions and schedule for the day.' : 'Coordinate appointments, keep the patient queue moving, and make every visit easier for pets and their families.' ?></p>
        <?php if ($isGroomer): ?>
        <div class="clinic-banner-actions">
          <a class="button light" href="groomer.php">Today's appointments</a>
          <a class="button ghost" href="groomer_schedule.php">My schedule</a>
        </div>
        <?php else: ?>
        <div class="clinic-banner-actions">
          <a class="button light" href="schedule_appointment.php">Schedule a visit</a>
          <a class="button ghost" href="queue.php">Open patient queue</a>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($isGroomer): ?>
    <section class="stat-grid" aria-label="Grooming appointment summary">
      <article class="stat-card" style="--accent:#0284c7"><span class="stat-label">Today's patients</span><strong class="stat-value"><?= count($appointments) ?></strong></article>
      <article class="stat-card" style="--accent:#6550a4"><span class="stat-label">Waiting</span><strong class="stat-value"><?= (int) $groomerDashboardStats['waiting'] ?></strong></article>
      <article class="stat-card" style="--accent:#008b78"><span class="stat-label">In consultation</span><strong class="stat-value"><?= (int) $groomerDashboardStats['active'] ?></strong></article>
      <article class="stat-card" style="--accent:#225d40"><span class="stat-label">Completed visits</span><strong class="stat-value"><?= (int) $groomerDashboardStats['complete'] ?></strong></article>
    </section>
    <?php endif; ?>

    <?php if ($appointmentFlash !== ''): ?><div class="notice success" role="status"><?= e($appointmentFlash) ?></div><?php endif; ?>

    <div class="staff-dashboard-grid<?= $showTodayAppointments ? '' : ' staff-dashboard-grid-no-today' ?><?= $isGroomer ? ' staff-dashboard-grid-groomer' : '' ?>">

      <!-- LEFT: Today's Appointments -->
      <?php if ($showTodayAppointments): ?>
      <div class="staff-dashboard-main">
        <section class="panel dashboard-panel">
          <?php if ($isGroomer): ?>
            <div class="panel-header assigned-patients-header">
              <h2>Today's assigned patients</h2>
              <span class="data-count" id="assigned-patients-header-count"><?= count($appointments) ?> shown</span>
            </div>
            <div class="panel-body assigned-patients-search">
              <label class="field">Search today's visits
                <input type="search" id="assigned-patient-search" placeholder="Pet, owner, time or status" autocomplete="off">
              </label>
              <span class="table-search-count" id="assigned-patient-search-count" aria-live="polite"><?= count($appointments) ?> of <?= count($appointments) ?> shown</span>
            </div>
            <div class="dashboard-table-wrap data-table-card assigned-patients-table-wrap">
              <table class="records-table assigned-patients-table">
                <colgroup><col style="width:9%"><col style="width:30%"><col style="width:30%"><col></colgroup>
                <thead><tr><th>Time</th><th>Pet</th><th>Owner</th><th>Status</th></tr></thead>
                <tbody>
                  <?php foreach ($appointments as $appointment): ?>
                    <?php
                      $appointmentTime = date('g:i A', strtotime((string) $appointment['appointment_date']));
                      $photoUrl = pet_photo_url($appointment['photo_updated_at'] ?? null, (int) $appointment['pet_id']);
                      $searchText = mb_strtolower(implode(' ', [
                          (string) $appointment['pet_name'],
                          (string) $appointment['owner_name'],
                          $appointmentTime,
                          (string) $appointment['status'],
                      ]));
                    ?>
                    <tr data-assigned-patient data-search="<?= e($searchText) ?>">
                      <td data-label="Time" class="appointments-time"><?= e($appointmentTime) ?></td>
                      <td data-label="Pet">
                        <div class="assigned-patient-pet">
                          <?php if ($photoUrl !== ''): ?>
                            <img src="<?= e($photoUrl) ?>" alt="" loading="lazy">
                          <?php else: ?>
                            <span class="assigned-patient-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $appointment['pet_name'], 0, 1))) ?></span>
                          <?php endif; ?>
                          <strong><?= e($appointment['pet_name']) ?></strong>
                        </div>
                      </td>
                      <td data-label="Owner"><?= e($appointment['owner_name']) ?></td>
                      <td data-label="Status"><span class="status <?= e(role_status_class($appointment['status'])) ?>"><?= e($appointment['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="appointments-empty-row" id="assigned-patients-empty" hidden>
                    <td colspan="4">
                      <div class="appointments-empty-state">
                        <strong>No visits match your search</strong>
                        <span>Try a different pet, owner, time or status.</span>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="panel-header appointments-panel-header">
              <h2>Today's Appointments</h2>
              <a class="dashboard-view-all" href="appointments.php?date=today">View All <span aria-hidden="true">→</span></a>
            </div>
            <div class="dashboard-table-wrap data-table-card">
              <table class="records-table dashboard-table appointments-dashboard-table">
                <colgroup><col style="width:9%"><col style="width:17%"><col style="width:22%"><col style="width:22%"><col style="width:12%"><col></colgroup>
                <thead><tr><th>ID</th><th>Pet Name</th><th>Owner</th><th>Veterinarian</th><th>Time</th><th>Status</th></tr></thead>
                <tbody>
                  <?php if ($appointments): ?>
                    <?php foreach ($appointments as $index => $appointment): ?>
                      <tr<?= $index % 2 ? ' class="alt"' : '' ?>>
                        <td data-label="ID"><a class="dashboard-appointment-id" href="appointments.php?appointment_id=<?= (int) $appointment['appointment_id'] ?>"><?= (int) $appointment['appointment_id'] ?></a></td>
                        <td data-label="Pet Name"><?= e($appointment['pet_name']) ?></td>
                        <td data-label="Owner"><?= e($appointment['owner_name']) ?></td>
                        <td data-label="Veterinarian"><?= e($appointment['vet_name'] ?: 'To be assigned') ?></td>
                        <td data-label="Time" class="appointments-time"><?= e(date('g:i A', strtotime($appointment['appointment_date']))) ?></td>
                        <td data-label="Status"><span class="status <?= e(role_status_class($appointment['status'])) ?>"><?= e($appointment['status']) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr class="appointments-empty-row">
                      <td colspan="6">
                        <div class="appointments-empty-state">
                          <strong>No records found</strong>
                          <span>No appointments are scheduled for today.</span>
                        </div>
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </div>
      <?php endif; ?>

      <!-- RIGHT: Pending appointment requests -->
      <?php if (!$isGroomer): ?>
      <div class="staff-dashboard-side">
        <section class="panel dashboard-panel" id="pending-requests">
          <div class="panel-header">
            <h2><?= $isGroomer ? 'Pending grooming appointment requests' : 'Pending appointment requests' ?></h2>
            <div class="panel-actions">
              <span class="data-count" id="pending-request-count" data-count="<?= $pendingCount ?>"><?= $pendingCount ?> awaiting approval</span>
              <?php if (!$isGroomer): ?><a class="dashboard-view-all" href="appointments.php?status=Pending">View all <span aria-hidden="true">→</span></a><?php endif; ?>
            </div>
          </div>
          <div class="dashboard-panel-body">
            <div class="notice success" id="dashboard-action-notice" role="status" hidden></div>
            <div class="table-wrap data-table-card pending-table-wrap">
              <table class="records-table pending-requests-table">
                <colgroup><col style="width:34%"><col style="width:40%"><col style="width:26%"></colgroup>
                <thead>
                  <tr><th scope="col">Pet / Owner</th><th scope="col">Appointment</th><th scope="col"><?= $isGroomer ? 'Next step' : 'Actions' ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pendingAppointments as $request): ?>
                    <tr data-pending-request data-appointment-id="<?= (int) $request['appointment_id'] ?>">
                      <td data-label="Pet / Owner">
                     <div class="pending-request-copy">
                          <strong><?= e($request['pet_name']) ?></strong>
                          <small><?= e($request['owner_name']) ?></small>
                          <a class="dashboard-request-details" href="<?= $isGroomer ? 'groomer.php' : 'appointments.php?appointment_id=' . (int) $request['appointment_id'] ?>">Request #<?= (int) $request['appointment_id'] ?></a>
                        </div>
                      </td>
                      <td data-label="Appointment">
                        <div class="pending-request-copy">
                          <strong><?= e(date('M j, Y · g:i A', strtotime($request['appointment_date']))) ?></strong>
                          <small><?= e($request['services'] ?: 'Service not specified') ?></small>
                          <small>Veterinarian: <?= e($request['vet_name'] ?: 'To be assigned') ?></small>
                        </div>
                      </td>
                      <td data-label="<?= $isGroomer ? 'Next step' : 'Actions' ?>">
                        <div class="pending-request-actions">
                        <?php if (!$isGroomer): ?>
                        <form method="post" action="appointments.php" data-dashboard-action>
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="appointment_id" value="<?= (int) $request['appointment_id'] ?>">
                          <input type="hidden" name="action" value="approve">
                          <input type="hidden" name="return_to" value="staff_dashboard.php">
                          <button class="button small" type="submit" aria-label="Accept appointment request for <?= e($request['pet_name']) ?>">Accept</button>
                        </form>
                        <button class="button danger small dashboard-decline" type="button"
                          data-id="<?= (int) $request['appointment_id'] ?>"
                          data-pet="<?= e($request['pet_name']) ?>"
                          data-owner="<?= e($request['owner_name']) ?>"
                          data-date="<?= e(date('M j, Y · g:i A', strtotime($request['appointment_date']))) ?>"
                          aria-label="Decline appointment request for <?= e($request['pet_name']) ?>">Decline</button>
                        <?php else: ?>
                          <span>Awaiting staff review</span>
                        <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                    <tr class="appointments-empty-row" id="pending-requests-empty"<?= $pendingAppointments ? ' hidden' : '' ?>>
                    <td colspan="3">
                      <div class="appointments-empty-state">
                          <strong id="pending-empty-title"><?= $pendingCount > 0 ? 'No more requests shown' : 'No records found' ?></strong>
                          <span id="pending-empty-note"><?= $pendingCount > 0 ? 'Other pending requests are not shown here. Use View all to see them.' : 'No appointment requests are waiting for approval.' ?></span>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </div>
      <?php endif; ?>

      <!-- BOTTOM: Veterinarian availability, full width below everything -->
      <?php if (!$isGroomer): ?>
      <div class="staff-dashboard-bottom">
        <section class="panel dashboard-panel">
          <div class="panel-header">
            <h2>Veterinarian availability</h2>
            <a class="dashboard-view-all" href="schedules.php">View All <span aria-hidden="true">→</span></a>
          </div>
          <?php if ($veterinarians): ?>
            <div class="dashboard-table-wrap availability-table-wrap data-table-card">
              <table class="records-table availability-table">
                <colgroup><col style="width:34%"><col style="width:34%"><col></colgroup>
                <thead><tr><th>Veterinarian</th><th>Hours / details</th><th>Availability</th></tr></thead>
                <tbody>
              <?php foreach ($veterinarians as $vet): ?>
                <?php
                $hasSchedule = $vet['start_time'] !== null && $vet['end_time'] !== null;
                $inHours = $hasSchedule
                    && $currentTime >= (string) $vet['start_time']
                    && $currentTime < (string) $vet['end_time'];
                if ((int) $vet['active_now'] > 0) {
                    $availability = 'Busy';
                    $availabilityDetail = 'In an appointment now';
                } elseif (!(int) $vet['is_available'] || !$hasSchedule || !$inHours
                    || (int) $vet['on_approved_leave'] || (int) $vet['clinic_holiday'] || (int) $vet['on_break']) {
                    $availability = 'Unavailable';
                    if ((int) $vet['on_approved_leave']) {
                        $availabilityDetail = 'Approved leave';
                    } elseif ((int) $vet['clinic_holiday']) {
                        $availabilityDetail = 'Clinic holiday';
                    } elseif ((int) $vet['on_break']) {
                        $availabilityDetail = 'Scheduled break';
                    } elseif (!$hasSchedule) {
                        $availabilityDetail = 'No published hours today';
                    } elseif (!$inHours) {
                        $availabilityDetail = 'Outside working hours';
                    } else {
                        $availabilityDetail = 'Not accepting appointments';
                    }
                } elseif ((int) $vet['appointments_today'] >= (int) $vet['max_appointments_per_day']) {
                    $availability = 'Fully Booked';
                    $availabilityDetail = 'Daily capacity reached';
                } else {
                    $availability = 'Available';
                    $availabilityDetail = substr((string) $vet['start_time'], 0, 5) . ' - ' . substr((string) $vet['end_time'], 0, 5);
                }
                ?>
                  <tr>
                    <td data-label="Veterinarian"><?= e($vet['full_name']) ?></td>
                    <td data-label="Hours / details"><?= e($availabilityDetail) ?></td>
                    <td data-label="Availability"><span class="status <?= e(role_status_class($availability)) ?>"><?= e($availability) ?></span></td>
                  </tr>
              <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="empty"><strong>No veterinarians are registered</strong></div>
          <?php endif; ?>
        </section>
      </div>
      <?php endif; ?>

    </div>

    <style>
      /* ---- Page layout: left stack | right panel, then availability across the bottom ---- */
      .staff-dashboard-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(320px,1fr);align-items:stretch;gap:14px}
      .staff-dashboard-grid-no-today{grid-template-columns:minmax(0,1fr)}
      .staff-dashboard-grid-groomer{grid-template-columns:minmax(0,1fr)}
      .staff-dashboard-main{min-width:0;display:grid;align-content:start;gap:14px}
      .staff-dashboard-side{min-width:0;min-height:380px;position:relative}
      .staff-dashboard-bottom{grid-column:1/-1;min-width:0}

      /* The right panel is pinned to the height of today's appointments. */
      .staff-dashboard-side>.dashboard-panel{position:absolute;inset:0;display:flex;flex-direction:column}
      .staff-dashboard-side .dashboard-panel-body{flex:1 1 auto;min-height:0;overflow-y:auto;align-content:start;overscroll-behavior:auto}

      .dashboard-panel{min-width:0;margin:0}
      .dashboard-panel .panel-header{flex-wrap:wrap}
      .dashboard-panel .panel-actions{flex-wrap:wrap;justify-content:flex-end}
      .dashboard-table-wrap{width:100%;overflow-x:auto;overflow-y:hidden;overscroll-behavior:auto}
      .dashboard-table{min-width:760px}
      .appointments-dashboard-table{min-width:640px}
      .appointments-dashboard-table th:nth-child(1){width:9%}
      .appointments-dashboard-table th:nth-child(2){width:17%}
      .appointments-dashboard-table th:nth-child(3){width:22%}
      .appointments-dashboard-table th:nth-child(4){width:22%}
      .appointments-dashboard-table th:nth-child(5){width:12%}
      .appointments-dashboard-table th:nth-child(6){width:18%}
      .appointments-panel-header{min-height:48px;padding:9px 15px}
      .assigned-patients-header{min-height:48px;padding:9px 15px}
      .assigned-patients-header .data-count{color:#fff;font-size:.7rem;font-weight:700}
      .assigned-patients-search{grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:10px}
      .assigned-patients-search .field{margin:0}
      .assigned-patients-table-wrap{min-height:320px;margin:0;border-radius:14px}
      .assigned-patients-table{width:100%;min-width:560px;table-layout:fixed}
      .assigned-patients-table th,.assigned-patients-table td{padding:10px 14px;white-space:normal}
      .assigned-patients-table th{text-align:left}
      .assigned-patients-table td:first-child{white-space:nowrap}
      .assigned-patient-pet{min-width:0;display:flex;align-items:center;gap:10px}
      .assigned-patient-pet img,.assigned-patient-avatar{width:32px;height:32px;flex:0 0 32px;border-radius:50%;object-fit:cover}
      .assigned-patient-avatar{display:grid;place-items:center;background:#e6f0f8;color:var(--blue-800);font-size:.8rem;font-weight:700}
      .assigned-patient-pet strong{min-width:0;color:var(--blue-900);overflow-wrap:anywhere}
      .dashboard-view-all{display:inline-flex;align-items:center;gap:4px;color:#fff;font-size:.78rem;font-weight:700;text-decoration:none;white-space:nowrap}
      .dashboard-view-all:hover{text-decoration:underline}
      .dashboard-appointment-id{color:inherit;text-decoration:none}
      .dashboard-appointment-id:hover{text-decoration:underline}
      .appointments-dashboard-table th:nth-child(5),.appointments-dashboard-table th:nth-child(6),
      .appointments-dashboard-table td:nth-child(5),.appointments-dashboard-table td:nth-child(6){text-align:center}
      .appointments-empty-row td{height:170px;padding:0;text-align:center;white-space:normal}
      .appointments-empty-state{min-height:170px;padding:20px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:var(--muted);white-space:normal}
      .appointments-empty-state strong{color:#4d6f8a;font-size:.95rem}
      .appointments-empty-state span{font-size:.8rem}
      .dashboard-panel-body{padding:14px 15px;display:grid;gap:14px}
      body.role-app .staff-dashboard-side .pending-table-wrap{min-height:300px;max-height:440px;margin:0;border-radius:14px}
      body.role-app .staff-dashboard-side .pending-table-wrap>.pending-requests-table{width:100%;min-width:0;table-layout:fixed}
      body.role-app .staff-dashboard-side .pending-requests-table th,
      body.role-app .staff-dashboard-side .pending-requests-table td{padding:10px 8px;white-space:normal}
      .pending-request-copy{min-width:0;display:grid;gap:4px}
      .pending-request-copy strong{color:var(--blue-900);font-size:.78rem;line-height:1.35;overflow-wrap:anywhere}
      .pending-request-copy small{color:var(--muted);font-size:.7rem;line-height:1.35;overflow-wrap:anywhere}
      .pending-request-actions{display:grid;justify-items:stretch;gap:6px}
      .pending-request-actions form{margin:0}
      .pending-request-actions .button{width:100%;min-width:0;padding:6px 8px}
      .dashboard-subsection{min-width:0}
      .dashboard-subsection+.dashboard-subsection{padding-top:12px;border-top:1px solid #e6eff6}
      .dashboard-subsection-heading{margin-bottom:7px;display:flex;align-items:baseline;justify-content:space-between;gap:8px}
      .dashboard-subsection-heading h3{margin:0;color:var(--blue-900);font-size:.82rem}
      .dashboard-subsection-heading a{display:inline-flex;align-items:center;gap:4px;color:var(--blue-700);font-size:.74rem;font-weight:700;text-decoration:none;white-space:nowrap}
      .dashboard-subsection-heading a:hover{text-decoration:underline}
      .dashboard-empty{margin:0;padding:5px 0;color:var(--muted);font-size:.75rem}
      .dashboard-request-details{color:var(--blue-700);font-size:.74rem;font-weight:700;text-decoration:none}
      .dashboard-request-details:hover{text-decoration:underline}

      /* Veterinarian availability: full width, so the table can use the whole row */
      .availability-table-wrap{border:0;border-top:1px solid var(--grid-line);border-radius:0}
      .availability-table{width:100%;min-width:560px;table-layout:fixed}
      .availability-table th:nth-child(1){width:34%}.availability-table th:nth-child(2){width:38%}.availability-table th:nth-child(3){width:28%}
      .availability-table th,.availability-table td{padding:10px 14px;white-space:normal;overflow-wrap:anywhere}
      .availability-table td:last-child{white-space:nowrap}
      .status.available{color:#225d40;background:#e3f5e9}
      .status.busy{color:#55368f;background:#eee7fb}
      .status.fully_booked{color:#7a5410;background:#fff2d6}
      .status.unavailable{color:#526a7c;background:#e8eef4}

      @media(max-width:1100px){.staff-dashboard-grid{grid-template-columns:minmax(0,1.2fr) minmax(290px,1fr)}}

      /* Single column on tablets/phones: panels simply stack (no pinned height) */
      @media(max-width:920px){
        .staff-dashboard-grid{grid-template-columns:1fr}
        .staff-dashboard-side{min-height:0}
        .staff-dashboard-side>.dashboard-panel{position:static;display:block}
        .staff-dashboard-side .dashboard-panel-body{overflow:visible}
        .staff-dashboard-bottom{grid-column:auto}
      }
      @media(max-width:600px){.dashboard-panel .panel-header{align-items:flex-start}.dashboard-panel .panel-header>.button,.dashboard-panel .panel-header>.dashboard-view-all,.dashboard-panel .panel-actions{margin-left:auto}.dashboard-panel-body{padding:12px}.assigned-patients-search{grid-template-columns:1fr;gap:6px}.assigned-patients-search .table-search-count{justify-self:end}.assigned-patients-table th,.assigned-patients-table td{padding:9px 7px;font-size:.74rem}.availability-table th,.availability-table td{padding:9px 7px;font-size:.74rem}.pending-requests-table th,.pending-requests-table td{padding:9px 6px;font-size:.68rem}.pending-request-actions .button{font-size:.68rem}}
    </style>
    <?php if ($isGroomer && $showTodayAppointments): ?>
    <script>
      (function () {
        var search = document.getElementById('assigned-patient-search');
        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-assigned-patient]'));
        var count = document.getElementById('assigned-patient-search-count');
        var headerCount = document.getElementById('assigned-patients-header-count');
        var emptyRow = document.getElementById('assigned-patients-empty');
        var total = rows.length;

        function updateAssignedPatients() {
          var query = search.value.trim().toLowerCase();
          var shown = 0;
          rows.forEach(function (row) {
            var matches = !query || row.dataset.search.indexOf(query) !== -1;
            row.hidden = !matches;
            if (matches) shown++;
          });
          count.textContent = shown + ' of ' + total + ' shown';
          headerCount.textContent = shown + ' shown';
          emptyRow.hidden = shown > 0;
        }

        search.addEventListener('input', updateAssignedPatients);
      })();
    </script>
    <?php endif; ?>
    <?php if (!$isGroomer): ?>
    <dialog id="staff-decline-dialog" aria-labelledby="staff-decline-title">
      <div class="dialog-head"><h2 id="staff-decline-title">Are you sure you want to decline this appointment?</h2></div>
      <form class="dialog-form" method="post" action="appointments.php" data-dashboard-action>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="appointment_id" id="staff-decline-id">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="return_to" value="staff_dashboard.php">
        <p class="hint" id="staff-decline-summary"></p>
        <p class="notice error" id="staff-decline-error" role="alert" hidden></p>
        <label class="field">Reason for declining
          <textarea name="reason" required maxlength="255" placeholder="Explain why this request cannot be approved."></textarea>
        </label>
        <div class="actions">
          <button class="button secondary" type="button" id="staff-decline-cancel">Keep appointment</button>
          <button class="button danger" type="submit">Confirm decline</button>
        </div>
      </form>
    </dialog>
    <script>
      (function () {
        var dialog = document.getElementById('staff-decline-dialog');
        var idInput = document.getElementById('staff-decline-id');
        var summary = document.getElementById('staff-decline-summary');
        var declineError = document.getElementById('staff-decline-error');
        document.querySelectorAll('.dashboard-decline').forEach(function (button) {
          button.addEventListener('click', function () {
            idInput.value = button.dataset.id;
            summary.textContent = button.dataset.pet + ' · ' + button.dataset.owner + ' · ' + button.dataset.date;
            declineError.hidden = true;
            dialog.showModal();
          });
        });
        document.getElementById('staff-decline-cancel').addEventListener('click', function () {
          dialog.close();
        });
        document.querySelectorAll('form[data-dashboard-action]').forEach(function (form) {
          form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var submit = form.querySelector('button[type="submit"]');
            if (submit.disabled) return;
            var originalText = submit.textContent;
            var appointmentId = form.querySelector('[name="appointment_id"]').value;
            submit.disabled = true;
            submit.textContent = form.querySelector('[name="action"]').value === 'approve' ? 'Accepting…' : 'Declining…';
            declineError.hidden = true;
            try {
              var actionUrl = new URL(form.getAttribute('action'), window.location.href);
              actionUrl.searchParams.set('dashboard_action', '1');
              var requestBody = new FormData(form);
              requestBody.append('dashboard_ajax', '1');
              var response = await fetch(actionUrl.toString(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  Accept: 'application/json',
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: requestBody
              });
              var contentType = response.headers.get('content-type') || '';
              if (contentType.indexOf('application/json') === -1) {
                console.error('Dashboard action returned a non-JSON response.', response.status, response.url, contentType);
                throw new Error(response.redirected
                  ? 'Your session may have expired. Reload the dashboard and sign in again.'
                  : 'The server returned an unexpected response (HTTP ' + response.status + '). Please try again.');
              }
              var result = await response.json();
              if (!response.ok || !result.ok) {
                throw new Error(result.message || 'The appointment request could not be updated.');
              }

              document.querySelectorAll('[data-pending-request]').forEach(function (row) {
                if (row.dataset.appointmentId === appointmentId) row.remove();
              });
              var count = document.getElementById('pending-request-count');
              var remaining = Math.max(0, Number(count.dataset.count) - 1);
              count.dataset.count = String(remaining);
              count.textContent = remaining + ' awaiting approval';

              var hasRows = document.querySelector('[data-pending-request]') !== null;
              var emptyRow = document.getElementById('pending-requests-empty');
              emptyRow.hidden = hasRows;
              if (!hasRows) {
                document.getElementById('pending-empty-title').textContent = remaining > 0 ? 'No more requests shown' : 'No records found';
                document.getElementById('pending-empty-note').textContent = remaining > 0
                  ? 'Other pending requests are not shown here. Use View all to see them.'
                  : 'No appointment requests are waiting for approval.';
              }

              var notice = document.getElementById('dashboard-action-notice');
              notice.className = 'notice success';
              notice.textContent = result.message;
              notice.hidden = false;
              if (dialog.open) {
                form.reset();
                submit.disabled = false;
                submit.textContent = originalText;
                dialog.close();
              }
            } catch (error) {
              if (dialog.open) {
                declineError.textContent = error.message || 'The appointment request could not be updated.';
                declineError.hidden = false;
              } else {
                var notice = document.getElementById('dashboard-action-notice');
                notice.className = 'notice error';
                notice.textContent = error.message || 'The appointment request could not be updated.';
                notice.hidden = false;
              }
              submit.disabled = false;
              submit.textContent = originalText;
            }
          });
        });
      })();
    </script>
    <?php endif; ?>
    <?php
});


