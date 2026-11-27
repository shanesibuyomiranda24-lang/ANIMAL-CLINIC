<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['staff']);

$pdo = db();
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired.';
    } else {
        $appointmentId = filter_var(posted_string('appointment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $action = posted_string('action');
        $transitions = [
            'checkin' => ['next' => 'Check-in', 'from' => ['Scheduled']],
            'wait' => ['next' => 'Waiting', 'from' => ['Check-in']],
            'consult' => ['next' => 'In Consultation', 'from' => ['Check-in', 'Waiting']],
            'complete' => ['next' => 'Complete', 'from' => ['In Consultation']],
        ];

        if ($appointmentId === false || !isset($transitions[$action])) {
            $error = 'Choose a valid patient and queue action.';
        } else {
            try {
                $pdo->beginTransaction();
                $before = $pdo->prepare(
                    'SELECT a.status, a.vet_id, p.owner_id, p.pet_name
                     FROM Appointments a
                     JOIN Pets p ON p.pet_id = a.pet_id
                     WHERE a.appointment_id = ? FOR UPDATE'
                );
                $before->execute([(int) $appointmentId]);
                $current = $before->fetch();
                if (!$current) {
                    throw new InvalidArgumentException('Appointment not found.');
                }

                $transition = $transitions[$action];
                if (!in_array($current['status'], $transition['from'], true)) {
                    throw new InvalidArgumentException('This status change is not allowed.');
                }
                if ($action === 'complete') {
                    $medicalRecord = $pdo->prepare('SELECT is_finalized FROM MedicalRecords WHERE appointment_id = ?');
                    $medicalRecord->execute([(int) $appointmentId]);
                    if ((int) $medicalRecord->fetchColumn() !== 1) {
                        throw new InvalidArgumentException('The veterinarian must finalize the medical record before the visit can be completed.');
                    }
                }

                $pdo->prepare('UPDATE Appointments SET status = ? WHERE appointment_id = ?')
                    ->execute([$transition['next'], (int) $appointmentId]);
                role_log(
                    $pdo,
                    (int) $_SESSION['user_id'],
                    'appointment.status',
                    'appointment',
                    (int) $appointmentId,
                    $current['status'] . ' -> ' . $transition['next']
                );
                if ($action === 'checkin' || $action === 'wait') {
                    role_notify_vet(
                        $pdo,
                        $current['vet_id'] ? (int) $current['vet_id'] : null,
                        'appointment',
                        $action === 'wait' ? 'Patient ready for consultation' : 'Patient checked in',
                        $current['pet_name'] . ($action === 'wait'
                            ? ' is ready for consultation in appointment #' . (int) $appointmentId . '.'
                            : ' checked in for appointment #' . (int) $appointmentId . '.'),
                        'vet/appointments.php',
                        'queue_' . $action . '_' . (int) $appointmentId
                    );
                    role_notify_owner(
                        $pdo,
                        (int) $current['owner_id'],
                        'appointment',
                        $action === 'wait' ? 'Pet is waiting' : 'Pet checked in',
                        $current['pet_name'] . ($action === 'wait' ? ' is waiting for the veterinarian.' : ' has checked in at the clinic.'),
                        'client/appointments.php',
                        'queue_' . $action . '_owner_' . (int) $appointmentId
                    );
                }
                if ($action === 'consult') {
                    role_notify_owner(
                        $pdo,
                        (int) $current['owner_id'],
                        'appointment',
                        'Consultation started',
                        'The consultation for ' . $current['pet_name'] . ' has started.',
                        'client/appointments.php',
                        'consult_started_owner_' . (int) $appointmentId
                    );
                    role_notify_role(
                        $pdo,
                        'admin',
                        'appointment',
                        'Consultation started',
                        'The consultation for ' . $current['pet_name'] . ' (appointment #' . (int) $appointmentId . ') has started.',
                        'admin/appointments.php',
                        'consult_started_admin_' . (int) $appointmentId
                    );
                }
                if ($action === 'complete') {
                    role_notify_owner(
                        $pdo,
                        (int) $current['owner_id'],
                        'billing',
                        'Consultation complete',
                        'Your consultation for appointment #' . (int) $appointmentId . ' is complete and ready for billing.',
                        'client/billing.php',
                        'consult_complete_owner_' . (int) $appointmentId
                    );
                    role_notify_role(
                        $pdo,
                        'staff',
                        'billing',
                        'Consultation complete',
                        'Appointment #' . (int) $appointmentId . ' is ready for billing.',
                        'staff/billing.php',
                        'consult_complete_' . (int) $appointmentId
                    );
                    role_notify_role(
                        $pdo,
                        'admin',
                        'billing',
                        'Consultation complete',
                        'Appointment #' . (int) $appointmentId . ' is complete.',
                        'admin/appointments.php',
                        'consult_complete_' . (int) $appointmentId
                    );
                }
                $pdo->commit();
                $success = 'Queue status updated.';
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $ex instanceof InvalidArgumentException
                    ? $ex->getMessage()
                    : 'The queue could not be updated.';
                error_log('Staff queue update: ' . $ex->getMessage());
            }
        }
    }
}

$statusValue = $_GET['status'] ?? '';
$statusFilter = is_string($statusValue) && in_array($statusValue, ['waiting', 'consulting', 'scheduled'], true)
    ? $statusValue
    : '';
$appointmentIdValue = filter_var($_GET['appointment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$filterAppointmentId = $appointmentIdValue === false || $appointmentIdValue === null ? 0 : (int) $appointmentIdValue;

role_render('staff', ['queue'], 'Patient Queue', 'Manage arrivals and consultation flow', function (array $context) use ($pdo, $error, $success, $statusFilter, $filterAppointmentId): void {
    $where = "a.appointment_date >= CURRENT_DATE
              AND a.appointment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
              AND a.status IN ('Scheduled','Waiting','Check-in','In Consultation')";
    $parameters = [];
    if ($statusFilter === 'waiting') {
        $where .= " AND a.status IN ('Waiting','Check-in')";
    } elseif ($statusFilter === 'consulting') {
        $where .= " AND a.status = 'In Consultation'";
    } elseif ($statusFilter === 'scheduled') {
        $where .= " AND a.status = 'Scheduled'";
    }
    if ($filterAppointmentId > 0) {
        $where .= ' AND a.appointment_id = ?';
        $parameters[] = $filterAppointmentId;
    }

    $statement = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.status, a.updated_at, p.pet_name,
                v.full_name AS vet_name,
                EXISTS (SELECT 1 FROM MedicalRecords m
                        WHERE m.appointment_id = a.appointment_id AND m.is_finalized = 1) AS record_finalized,
                COALESCE(
                    (SELECT MAX(h.changed_at)
                     FROM AppointmentStatusHistory h
                     WHERE h.appointment_id = a.appointment_id
                       AND h.new_status IN ('Waiting','Check-in')),
                    a.updated_at
                ) AS waiting_since
         FROM Appointments a
         JOIN Pets p ON p.pet_id = a.pet_id
         LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
         WHERE $where
         ORDER BY CASE a.status
                    WHEN 'Check-in' THEN 0
                    WHEN 'Waiting' THEN 1
                    WHEN 'In Consultation' THEN 2
                    ELSE 3
                  END,
                  waiting_since, a.appointment_date"
    );
    $statement->execute($parameters);
    $rows = $statement->fetchAll();
    $hasFilter = $statusFilter !== '' || $filterAppointmentId > 0;

    if ($error !== '') {
        echo '<div class="notice error" role="alert">' . e($error) . '</div>';
    }
    if ($success !== '') {
        echo '<div class="notice success" role="status">' . e($success) . '</div>';
    }
    ?>

    <section class="panel pq-panel">
      <div class="panel-header">
        <h2>Active queue</h2>
        <span class="data-count"><?= count($rows) ?> active</span>
      </div>

      <form class="pq-filters" method="get" action="queue.php">
        <?php if ($filterAppointmentId > 0): ?><input type="hidden" name="appointment_id" value="<?= $filterAppointmentId ?>"><?php endif; ?>
        <label class="pq-field">Queue status
          <select name="status" onchange="this.form.submit()">
            <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All active patients</option>
            <option value="waiting" <?= $statusFilter === 'waiting' ? 'selected' : '' ?>>Waiting / checked in</option>
            <option value="consulting" <?= $statusFilter === 'consulting' ? 'selected' : '' ?>>In consultation</option>
            <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
          </select>
        </label>
        <?php if ($hasFilter): ?><a class="button secondary pq-reset" href="queue.php">Reset</a><?php endif; ?>
      </form>

      <div class="pq-body">
      <?php if ($rows): ?>
        <div class="pq-table-wrap data-table-card">
          <table class="pq-table">
            <colgroup><col class="c-num"><col class="c-time"><col class="c-pet"><col class="c-vet"><col class="c-wait"><col class="c-status"><col class="c-action"></colgroup>
            <thead>
              <tr>
                <th class="pq-col-num">Queue #</th><th>Time</th><th>Pet</th><th>Veterinarian</th><th>Waiting time</th><th>Status</th><th class="pq-col-actions">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $index => $row): ?>
                <?php
                $waitingTime = '';
                $waitClass = '';
                if (in_array($row['status'], ['Waiting', 'Check-in', 'In Consultation'], true)) {
                    $minutes = max(0, (int) floor((time() - strtotime((string) $row['waiting_since'])) / 60));
                    $waitingTime = $minutes >= 60
                        ? (int) floor($minutes / 60) . 'h ' . ($minutes % 60) . 'm'
                        : $minutes . ' min';
                    if ($row['status'] !== 'In Consultation') {
                        $waitClass = $minutes >= 60 ? ' is-long' : ($minutes >= 30 ? ' is-warn' : '');
                    }
                }
                $initial = mb_strtoupper(mb_substr((string) $row['pet_name'], 0, 1));
                ?>
                <tr data-status="<?= e($row['status']) ?>">
                  <td data-label="Queue #" class="pq-col-num"><span class="pq-num">#<?= $index + 1 ?></span></td>
                  <td data-label="Time" class="pq-nowrap"><strong class="pq-time"><?= e(date('g:i A', strtotime($row['appointment_date']))) ?></strong></td>
                  <td data-label="Pet">
                    <div class="pq-pet"><span class="pq-avatar" aria-hidden="true"><?= e($initial) ?></span><strong class="pq-pet-name"><?= e($row['pet_name']) ?></strong></div>
                  </td>
                  <td data-label="Veterinarian"><?= $row['vet_name'] ? e($row['vet_name']) : '<span class="pq-muted">Unassigned</span>' ?></td>
                  <td data-label="Waiting time">
                    <?php if ($waitingTime !== ''): ?><span class="pq-wait<?= $waitClass ?>"><?= e($waitingTime) ?></span>
                    <?php else: ?><span class="pq-muted">Not checked in</span><?php endif; ?>
                  </td>
                  <td data-label="Status"><span class="status <?= e(role_status_class($row['status'])) ?>"><?= e($row['status']) ?></span></td>
                  <td data-label="Action" class="pq-col-actions">
                    <div class="actions pq-actions">
                      <?php if ($row['status'] === 'Scheduled'): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="appointment_id" value="<?= (int) $row['appointment_id'] ?>"><input type="hidden" name="action" value="checkin"><button class="button small" type="submit">Check in</button></form>
                      <?php elseif ($row['status'] === 'Check-in'): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="appointment_id" value="<?= (int) $row['appointment_id'] ?>"><input type="hidden" name="action" value="wait"><button class="button small secondary" type="submit">Mark waiting</button></form>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="appointment_id" value="<?= (int) $row['appointment_id'] ?>"><input type="hidden" name="action" value="consult"><button class="button small" type="submit">Start consultation</button></form>
                      <?php elseif ($row['status'] === 'Waiting'): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="appointment_id" value="<?= (int) $row['appointment_id'] ?>"><input type="hidden" name="action" value="consult"><button class="button small" type="submit">Start consultation</button></form>
                      <?php elseif ($row['status'] === 'In Consultation' && (int) $row['record_finalized'] === 1): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="appointment_id" value="<?= (int) $row['appointment_id'] ?>"><input type="hidden" name="action" value="complete"><button class="button small secondary" type="submit">Mark completed</button></form>
                      <?php elseif ($row['status'] === 'In Consultation'): ?>
                        <span class="hint">Awaiting finalized medical record</span>
                      <?php else: ?>
                        <span class="hint">No action needed</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="pq-table-wrap data-table-card">
          <table class="pq-table pq-table-empty" aria-hidden="true">
            <colgroup><col class="c-num"><col class="c-time"><col class="c-pet"><col class="c-vet"><col class="c-wait"><col class="c-status"><col class="c-action"></colgroup>
            <thead>
              <tr><th class="pq-col-num">Queue #</th><th>Time</th><th>Pet</th><th>Veterinarian</th><th>Waiting time</th><th>Status</th><th class="pq-col-actions">Action</th></tr>
            </thead>
          </table>
          <div class="pq-empty">
            <span class="pq-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/></svg></span>
            <strong>No active patients</strong>
            <span><?= $hasFilter ? 'No patients match this filter.' : 'Checked-in patients will appear here.' ?></span>
            <?php if ($hasFilter): ?><div><a class="button secondary small" href="queue.php">Clear filter</a></div><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      </div>
    </section>

    <style>

      /* ---------- Panel ---------- */
      .pq-panel{margin:0 0 24px;overflow:hidden;background:#fff;border:1px solid #d7e8f5;border-radius:20px;box-shadow:0 10px 28px rgba(0,46,74,.08)}

      /* ---------- Filter bar ---------- */
      .pq-filters{padding:16px 24px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:14px;border-bottom:1px solid #e1edf6;background:linear-gradient(180deg,#f7fbfe,#eff7fc)}
      .pq-field{display:grid;gap:6px;width:min(100%,280px);color:#42637e;font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
      .pq-field select{width:100%;min-height:44px;padding:9px 13px;color:#092947;background:#fff;border:1px solid #b8d2e5;border-radius:10px;font:inherit;font-size:.92rem;letter-spacing:0;text-transform:none;transition:border-color .15s,box-shadow .15s}
      .pq-field select:hover{border-color:#7fb4d6}
      .pq-field select:focus{outline:0;border-color:#0284c7;box-shadow:0 0 0 4px rgba(2,132,199,.14)}
      .pq-reset{min-height:44px}

      /* ---------- Body keeps a constant minimum height ---------- */
      .pq-body{padding:18px 24px 24px;display:flex;flex-direction:column;min-height:420px}
      .pq-body > .pq-table-wrap{width:100%;min-height:420px;margin:0;flex:none;border-radius:12px}

      /* ---------- Table ---------- */
      .pq-table-wrap{overflow-x:auto;scrollbar-width:thin;scrollbar-color:#9fc5df #eef6fc}
      .pq-table-wrap::-webkit-scrollbar{height:10px}
      .pq-table-wrap::-webkit-scrollbar-track{background:#eef6fc}
      .pq-table-wrap::-webkit-scrollbar-thumb{background:#9fc5df;border-radius:999px;border:2px solid #eef6fc}
      .pq-table-wrap::-webkit-scrollbar-thumb:hover{background:#6ea8cf}
      .pq-table{width:100%;min-width:1000px;table-layout:fixed;border-collapse:separate;border-spacing:0;text-align:left}
      .pq-table .c-num{width:110px}.pq-table .c-time{width:120px}.pq-table .c-pet{width:19%}.pq-table .c-vet{width:20%}.pq-table .c-wait{width:150px}.pq-table .c-status{width:150px}.pq-table .c-action{width:auto}
      .pq-table thead th{padding:14px 20px;color:#002e4a;background:linear-gradient(180deg,#e4f1fb,#cfe5f5);border-right:1px solid #b9d6ea;border-bottom:2px solid #a9cbe3;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
      .pq-table thead th:last-child{border-right:0}
      .pq-table td{padding:16px 20px;overflow:hidden;text-overflow:ellipsis;border-bottom:1px solid #e6eff6;color:#17324d;font-size:.9rem;vertical-align:middle}
      .pq-table tbody tr{transition:background-color .15s}
      .pq-table tbody tr:nth-child(even) td{background:#fafdff}
      .pq-table tbody tr:hover td{background:#eef6fc}
      .pq-table tbody tr:last-child td{border-bottom:0}
      .pq-table thead th:first-child,.pq-table tbody td:first-child{padding-left:24px}
      .pq-table tbody td:first-child{box-shadow:inset 4px 0 0 var(--row,#b8d2e5)}
      .pq-table tbody tr[data-status="Scheduled"]{--row:#0284c7}
      .pq-table tbody tr[data-status="Waiting"],.pq-table tbody tr[data-status="Check-in"]{--row:#6550a4}
      .pq-table tbody tr[data-status="In Consultation"]{--row:#0e7490}
      .pq-nowrap{white-space:nowrap}
            .pq-num{display:inline-block;min-width:36px;padding:4px 10px;text-align:center;color:#015b8c;background:#eaf3fa;border:1px solid #d3e6f4;border-radius:999px;font-weight:700}
      .pq-time{color:#002e4a}
      .pq-pet{display:flex;align-items:center;gap:12px;min-width:0}
      .pq-pet-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .pq-pet-name{color:#002e4a}
      .pq-avatar{width:38px;height:38px;flex:none;display:grid;place-items:center;color:#015b8c;background:linear-gradient(135deg,#e4f1fb,#c4e0f3);border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 1px #b8d2e5,0 3px 8px rgba(0,46,74,.12);font-weight:700}
      .pq-muted{color:#8aa3b6;font-size:.86rem}
      .pq-wait{display:inline-block;padding:4px 11px;color:#17324d;background:#eef6fc;border:1px solid #d3e6f4;border-radius:999px;font-size:.84rem;font-weight:600;white-space:nowrap}
      .pq-wait.is-warn{color:#8a5a0a;background:#fff6e0;border-color:#f0d9a0}
      .pq-wait.is-long{color:#a02a1c;background:#fdecea;border-color:#f2bdb6}
      .pq-actions{display:flex;flex-wrap:wrap;gap:8px}.pq-actions form{margin:0}
      .pq-actions .button{transition:transform .12s,box-shadow .12s}
      .pq-actions .button:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,46,74,.16)}
      .pq-actions .button:focus-visible{outline:3px solid rgba(2,132,199,.35);outline-offset:2px}

      /* ---------- Empty state ---------- */
      .pq-empty{flex:1;min-height:300px;padding:32px 20px;display:grid;align-content:center;justify-items:center;gap:8px;color:#42637e;font-size:.9rem;text-align:center}
      .pq-empty strong{color:#002e4a;font-size:1.02rem}
      .pq-empty div{margin-top:12px}
      .pq-empty-icon{width:64px;height:64px;margin-bottom:6px;display:grid;place-items:center;color:#4f7fa3;background:linear-gradient(135deg,#eaf4fb,#d4e8f6);border:1px solid #c4dcec;border-radius:50%}

      /* ---------- Responsive ---------- */
      @media(max-width:760px){
        .pq-body{min-height:340px;padding:12px}
        .pq-table-wrap{overflow:visible;padding:12px}
        .pq-body > .pq-table-wrap{min-height:340px;padding:0}
        .pq-table{min-width:0}.pq-table thead,.pq-table-empty{display:none}
        .pq-table,.pq-table tbody,.pq-table tr,.pq-table td{display:block;width:100%}
        .pq-table colgroup{display:none}
        .pq-table tr{margin:0 0 12px;overflow:hidden;background:#fff;border:1px solid #d7e8f5;border-left:4px solid var(--row,#b8d2e5);border-radius:14px;box-shadow:0 2px 8px rgba(0,46,74,.06)}
        .pq-table td{padding:11px 15px;display:flex;align-items:center;justify-content:space-between;gap:16px;text-align:right}
        .pq-table td::before{content:attr(data-label);flex:none;color:#42637e;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;text-align:left}
        .pq-table tbody td:first-child{padding-left:15px;box-shadow:none}
        .pq-table tbody tr:nth-child(even) td,.pq-table tbody tr:hover td{background:transparent}
        .pq-table td:last-child{border-bottom:0}
        .pq-table td{overflow:visible}
        .pq-pet{min-width:0}
        .pq-actions{justify-content:flex-end}
      }
      @media(prefers-reduced-motion:reduce){.pq-actions .button{transition:none}.pq-actions .button:hover{transform:none}}
    </style>
    <?php
});