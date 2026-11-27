<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['admin']);

$pdo = db();
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        $action = posted_string('action');
        $requestId = filter_var(posted_string('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reviewNote = mb_substr(trim(posted_string('review_note')), 0, 255);
        if ($requestId === false) {
            $error = 'Choose a valid schedule or leave request.';
        } else {
            try {
                $pdo->beginTransaction();
                if (in_array($action, ['approve_schedule', 'reject_schedule'], true)) {
                    $lookup = $pdo->prepare('SELECT vet_id FROM VetScheduleRequests WHERE request_id = ?');
                    $lookup->execute([(int) $requestId]);
                    $vetId = (int) $lookup->fetchColumn();
                    if ($vetId < 1) {
                        throw new InvalidArgumentException('That schedule request no longer exists.');
                    }
                    $vetLock = $pdo->prepare('SELECT full_name FROM Veterinarians WHERE vet_id = ? FOR UPDATE');
                    $vetLock->execute([$vetId]);
                    $vetName = $vetLock->fetchColumn();
                    $requestStatement = $pdo->prepare(
                        'SELECT request_id, status FROM VetScheduleRequests WHERE request_id = ? AND vet_id = ? FOR UPDATE'
                    );
                    $requestStatement->execute([(int) $requestId, $vetId]);
                    $request = $requestStatement->fetch();
                    if (!$vetName || !$request || $request['status'] !== 'Pending') {
                        throw new InvalidArgumentException('This schedule request has already been reviewed.');
                    }

                    if ($action === 'approve_schedule') {
                        $dayStatement = $pdo->prepare(
                            'SELECT day_of_week, start_time, end_time
                             FROM VetScheduleRequestDays
                             WHERE request_id = ?
                             ORDER BY day_of_week'
                        );
                        $dayStatement->execute([(int) $requestId]);
                        $days = [];
                        foreach ($dayStatement->fetchAll() as $day) {
                            $days[(int) $day['day_of_week']] = $day;
                        }
                        if (!$days) {
                            throw new InvalidArgumentException('This request has no working days and cannot be approved.');
                        }

                        $breakStatement = $pdo->prepare(
                            'SELECT day_of_week, start_time, end_time
                             FROM VetScheduleRequestBreaks
                             WHERE request_id = ?
                             ORDER BY day_of_week, start_time'
                        );
                        $breakStatement->execute([(int) $requestId]);
                        $breaks = [];
                        foreach ($breakStatement->fetchAll() as $break) {
                            $breaks[(int) $break['day_of_week']][] = $break;
                        }

                        $activeStatuses = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
                        $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
                        $appointmentStatement = $pdo->prepare(
                            "SELECT appointment_id, appointment_date, duration_minutes
                             FROM Appointments
                             WHERE vet_id = ? AND status IN ($statusPlaceholders)
                               AND appointment_date >= NOW()
                             ORDER BY appointment_date
                             FOR UPDATE"
                        );
                        $appointmentStatement->execute(array_merge([$vetId], $activeStatuses));
                        foreach ($appointmentStatement->fetchAll() as $appointment) {
                            $visitAt = new DateTimeImmutable((string) $appointment['appointment_date']);
                            $dayNumber = (int) $visitAt->format('N');
                            if (!isset($days[$dayNumber])) {
                                throw new InvalidArgumentException(
                                    'Cannot approve: appointment #' . (int) $appointment['appointment_id'] . ' falls on a proposed non-working day.'
                                );
                            }
                            $visitEnd = $visitAt->modify('+' . (int) $appointment['duration_minutes'] . ' minutes');
                            $workStart = new DateTimeImmutable($visitAt->format('Y-m-d') . ' ' . $days[$dayNumber]['start_time']);
                            $workEnd = new DateTimeImmutable($visitAt->format('Y-m-d') . ' ' . $days[$dayNumber]['end_time']);
                            if ($visitAt < $workStart || $visitEnd > $workEnd) {
                                throw new InvalidArgumentException(
                                    'Cannot approve: appointment #' . (int) $appointment['appointment_id'] . ' falls outside the proposed working hours.'
                                );
                            }
                            foreach ($breaks[$dayNumber] ?? [] as $break) {
                                $breakStart = new DateTimeImmutable($visitAt->format('Y-m-d') . ' ' . $break['start_time']);
                                $breakEnd = new DateTimeImmutable($visitAt->format('Y-m-d') . ' ' . $break['end_time']);
                                if ($visitAt < $breakEnd && $visitEnd > $breakStart) {
                                    throw new InvalidArgumentException(
                                        'Cannot approve: appointment #' . (int) $appointment['appointment_id'] . ' overlaps a proposed break.'
                                    );
                                }
                            }
                        }

                        $pdo->prepare('DELETE FROM VetScheduleBreaks WHERE vet_id = ?')->execute([$vetId]);
                        $pdo->prepare('DELETE FROM VetSchedules WHERE vet_id = ?')->execute([$vetId]);
                        $insertDay = $pdo->prepare(
                            'INSERT INTO VetSchedules (vet_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)'
                        );
                        $insertBreak = $pdo->prepare(
                            'INSERT INTO VetScheduleBreaks (vet_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)'
                        );
                        foreach ($days as $dayNumber => $day) {
                            $insertDay->execute([$vetId, $dayNumber, $day['start_time'], $day['end_time']]);
                            foreach ($breaks[$dayNumber] ?? [] as $break) {
                                $insertBreak->execute([$vetId, $dayNumber, $break['start_time'], $break['end_time']]);
                            }
                        }
                        $newStatus = 'Approved';
                    } else {
                        $newStatus = 'Rejected';
                    }

                    $pdo->prepare(
                        'UPDATE VetScheduleRequests
                         SET status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), review_note = ?
                         WHERE request_id = ?'
                    )->execute([$newStatus, (int) $_SESSION['user_id'], $reviewNote !== '' ? $reviewNote : null, (int) $requestId]);
                    $title = 'Schedule request ' . strtolower($newStatus);
                    $vetNotified = role_notify_vet(
                        $pdo,
                        $vetId,
                        'schedule',
                        $title,
                        'Your working schedule request was ' . strtolower($newStatus) . '.'
                            . ($reviewNote !== '' ? ' Admin note: ' . $reviewNote : ''),
                        'vet/schedules.php',
                        'vet_schedule_reviewed_' . (int) $requestId
                    );
                    if ($vetNotified === 0) {
                        error_log('Schedule request reviewed without an active veterinarian notification recipient.');
                    }
                    role_log(
                        $pdo,
                        (int) $_SESSION['user_id'],
                        'vet.schedule.' . strtolower($newStatus),
                        'vet_schedule_request',
                        (int) $requestId,
                        $reviewNote !== '' ? $reviewNote : $vetName
                    );
                    $success = 'Schedule request ' . strtolower($newStatus) . '.';
                } elseif (in_array($action, ['approve_leave', 'reject_leave'], true)) {
                    $lookup = $pdo->prepare('SELECT vet_id FROM VetLeaves WHERE leave_id = ?');
                    $lookup->execute([(int) $requestId]);
                    $vetId = (int) $lookup->fetchColumn();
                    if ($vetId < 1) {
                        throw new InvalidArgumentException('That leave request no longer exists.');
                    }
                    $vetLock = $pdo->prepare('SELECT full_name FROM Veterinarians WHERE vet_id = ? FOR UPDATE');
                    $vetLock->execute([$vetId]);
                    $vetName = $vetLock->fetchColumn();
                    $leaveStatement = $pdo->prepare(
                        "SELECT start_date, end_date, status
                         FROM VetLeaves WHERE leave_id = ? AND vet_id = ? FOR UPDATE"
                    );
                    $leaveStatement->execute([(int) $requestId, $vetId]);
                    $leave = $leaveStatement->fetch();
                    if (!$vetName || !$leave || $leave['status'] !== 'Pending') {
                        throw new InvalidArgumentException('This leave request has already been reviewed.');
                    }

                    if ($action === 'approve_leave') {
                        $activeStatuses = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
                        $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
                        $conflictStatement = $pdo->prepare(
                            "SELECT appointment_id
                             FROM Appointments
                             WHERE vet_id = ? AND DATE(appointment_date) BETWEEN ? AND ?
                               AND status IN ($statusPlaceholders)
                             ORDER BY appointment_date
                             LIMIT 1
                             FOR UPDATE"
                        );
                        $conflictStatement->execute(array_merge([
                            $vetId,
                            $leave['start_date'],
                            $leave['end_date'],
                        ], $activeStatuses));
                        $conflictingAppointment = $conflictStatement->fetchColumn();
                        if ($conflictingAppointment) {
                            throw new InvalidArgumentException(
                                'Cannot approve leave while appointment #' . (int) $conflictingAppointment . ' is scheduled in that period.'
                            );
                        }
                        $newStatus = 'Approved';
                    } else {
                        $newStatus = 'Rejected';
                    }

                    $pdo->prepare('UPDATE VetLeaves SET status = ? WHERE leave_id = ?')->execute([$newStatus, (int) $requestId]);
                    $vetNotified = role_notify_vet(
                        $pdo,
                        $vetId,
                        'schedule',
                        'Leave request ' . strtolower($newStatus),
                        'Your leave request from ' . date('M j, Y', strtotime((string) $leave['start_date']))
                            . ' to ' . date('M j, Y', strtotime((string) $leave['end_date']))
                            . ' was ' . strtolower($newStatus) . '.',
                        'vet/schedules.php',
                        'vet_leave_reviewed_' . (int) $requestId
                    );
                    if ($vetNotified === 0) {
                        error_log('Leave request reviewed without an active veterinarian notification recipient.');
                    }
                    role_log(
                        $pdo,
                        (int) $_SESSION['user_id'],
                        'vet.leave.' . strtolower($newStatus),
                        'vet_leave',
                        (int) $requestId,
                        $reviewNote !== '' ? $reviewNote : $vetName
                    );
                    $success = 'Leave request ' . strtolower($newStatus) . '.';
                } else {
                    throw new InvalidArgumentException('Unsupported schedule review action.');
                }

                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Admin schedule review: ' . $ex->getMessage());
                $error = $ex instanceof InvalidArgumentException ? $ex->getMessage() : 'The request could not be reviewed.';
            }
        }
    }
}

$scheduleStatement = $pdo->query(
    "SELECT r.request_id, r.vet_id, r.submitted_at, v.full_name AS vet_name
     FROM VetScheduleRequests r
     JOIN Veterinarians v ON v.vet_id = r.vet_id
     WHERE r.status = 'Pending'
     ORDER BY r.submitted_at"
);
$scheduleRequests = $scheduleStatement->fetchAll();
$scheduleRequestIds = array_map(static function (array $request): int {
    return (int) $request['request_id'];
}, $scheduleRequests);
$requestedDays = [];
$requestedBreaks = [];
if ($scheduleRequestIds) {
    $placeholders = implode(',', array_fill(0, count($scheduleRequestIds), '?'));
    $dayStatement = $pdo->prepare(
        "SELECT request_id, day_of_week, start_time, end_time
         FROM VetScheduleRequestDays WHERE request_id IN ($placeholders)
         ORDER BY request_id, day_of_week"
    );
    $dayStatement->execute($scheduleRequestIds);
    foreach ($dayStatement->fetchAll() as $day) {
        $requestedDays[(int) $day['request_id']][] = $day;
    }
    $breakStatement = $pdo->prepare(
        "SELECT request_id, day_of_week, start_time, end_time
         FROM VetScheduleRequestBreaks WHERE request_id IN ($placeholders)
         ORDER BY request_id, day_of_week, start_time"
    );
    $breakStatement->execute($scheduleRequestIds);
    foreach ($breakStatement->fetchAll() as $break) {
        $requestedBreaks[(int) $break['request_id']][(int) $break['day_of_week']][] = $break;
    }
}
$leaveStatement = $pdo->query(
    "SELECT l.leave_id, l.vet_id, l.start_date, l.end_date, l.reason, v.full_name AS vet_name
     FROM VetLeaves l
     JOIN Veterinarians v ON v.vet_id = l.vet_id
     WHERE l.status = 'Pending'
     ORDER BY l.start_date, l.leave_id"
);
$leaveRequests = $leaveStatement->fetchAll();
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

role_render('admin', ['schedules'], 'Veterinarian Schedule Requests', 'Review and publish working hours and leave', function (array $context) use ($scheduleRequests, $requestedDays, $requestedBreaks, $leaveRequests, $dayNames, $error, $success): void {
    ?>
    <style>
      body.role-app.admin .schedule-request-panel{margin-bottom:18px;border-color:#cbdfee;border-radius:16px;box-shadow:0 6px 18px rgba(0,46,74,.08)}
      body.role-app.admin .schedule-request-panel>.panel-header{min-height:50px;padding:11px 18px}
      body.role-app.admin .schedule-request-panel .data-count{padding:5px 10px;color:#eaf3fa;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.2);border-radius:999px;white-space:nowrap}
      body.role-app.admin .schedule-request-panel .table-wrap{border-color:#cbdfee;border-radius:13px;box-shadow:0 2px 8px rgba(0,46,74,.04)}
      body.role-app.admin .schedule-request-panel .schedule-hours{white-space:normal;line-height:1.55}
      body.role-app.admin .schedule-request-panel .schedule-hours>div+div{margin-top:7px;padding-top:7px;border-top:1px dashed #d7e8f5}
      body.role-app.admin .schedule-request-panel .schedule-hours .hint{display:block;margin-top:3px}
      body.role-app.admin .schedule-review-form{align-items:flex-end;gap:8px}
      body.role-app.admin .schedule-review-form .field{flex:1 1 190px}
      body.role-app.admin .schedule-review-form input{min-height:40px;border-color:#bed4e5;border-radius:9px}
      body.role-app.admin .schedule-review-panel .empty{min-height:190px;padding:26px 20px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:7px}
      body.role-app.admin .schedule-review-panel .empty:before{width:54px;height:54px;display:block;background:#eaf3fa url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23015b8c' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 3v3m8-3v3M4 9h16M5 5h14v16H5zM8 13h3m-3 4h3'/%3E%3C/svg%3E") center/26px 26px no-repeat;border:1px solid #c4dcec;border-radius:50%;content:""}
      @media(max-width:760px){body.role-app.admin .schedule-request-panel>.panel-header{padding:10px 13px}body.role-app.admin .schedule-review-form{align-items:stretch;flex-direction:column}body.role-app.admin .schedule-review-form .field,body.role-app.admin .schedule-review-form .button{width:100%}}
    </style>
    <?php
    if ($error !== '') {
        echo '<div class="notice error" role="alert">' . e($error) . '</div>';
    }
    if ($success !== '') {
        echo '<div class="notice success" role="status">' . e($success) . '</div>';
    }
    ?>
    <section class="panel schedule-request-panel schedule-review-panel">
      <div class="panel-header"><h2>Working schedule requests</h2><span class="data-count"><?= count($scheduleRequests) ?> pending</span></div>
      <div class="table-wrap data-table-card">
        <table class="records-table">
          <colgroup><col style="width:25%"><col style="width:10rem"><col style="width:34%"><col style="width:18rem"></colgroup>
          <thead><tr><th>Veterinarian</th><th>Submitted</th><th>Requested weekly hours</th><th>Review</th></tr></thead>
          <tbody>
            <?php if ($scheduleRequests): ?>
              <?php foreach ($scheduleRequests as $index => $request): ?>
                <?php $requestId = (int) $request['request_id']; ?>
                <tr<?= $index % 2 ? ' class="alt"' : '' ?>>
                  <td data-label="Veterinarian"><?= e($request['vet_name']) ?></td>
                  <td data-label="Submitted"><?= e(date('M j, Y g:i A', strtotime($request['submitted_at']))) ?></td>
                  <td class="schedule-hours" data-label="Requested weekly hours">
                    <?php foreach ($requestedDays[$requestId] ?? [] as $day): ?>
                      <div><strong><?= e($dayNames[(int) $day['day_of_week']]) ?>:</strong>
                        <?= e(date('g:i A', strtotime($day['start_time'])) . '–' . date('g:i A', strtotime($day['end_time']))) ?>
                        <?php $dayBreakLabels = []; foreach ($requestedBreaks[$requestId][(int) $day['day_of_week']] ?? [] as $break) { $dayBreakLabels[] = date('g:i A', strtotime($break['start_time'])) . '–' . date('g:i A', strtotime($break['end_time'])); } ?>
                        <?php if ($dayBreakLabels): ?><span class="hint">Break: <?= e(implode(', ', $dayBreakLabels)) ?></span><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </td>
                  <td data-label="Review">
                    <form method="post" class="actions schedule-review-form">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="request_id" value="<?= $requestId ?>">
                      <label class="field">Review note<input name="review_note" maxlength="255"></label>
                      <button class="button small" type="submit" name="action" value="approve_schedule">Approve &amp; publish</button>
                      <button class="button danger small" type="submit" name="action" value="reject_schedule">Reject</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr class="empty-row"><td colspan="4"><div class="empty-state-box" role="status"><strong>No working schedule requests</strong><span>Veterinarian proposals will appear here for review.</span></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel schedule-request-panel schedule-review-panel">
      <div class="panel-header"><h2>Leave requests</h2><span class="data-count"><?= count($leaveRequests) ?> pending</span></div>
      <div class="table-wrap data-table-card">
        <table class="records-table">
          <colgroup><col style="width:27%"><col style="width:14rem"><col style="width:30%"><col style="width:18rem"></colgroup>
          <thead><tr><th>Veterinarian</th><th>Requested dates</th><th>Reason</th><th>Review</th></tr></thead>
          <tbody>
            <?php if ($leaveRequests): ?>
              <?php foreach ($leaveRequests as $index => $request): ?>
                <tr<?= $index % 2 ? ' class="alt"' : '' ?>>
                  <td data-label="Veterinarian"><?= e($request['vet_name']) ?></td>
                  <td data-label="Requested dates"><?= e(date('M j, Y', strtotime($request['start_date'])) . ' – ' . date('M j, Y', strtotime($request['end_date']))) ?></td>
                  <td data-label="Reason"><?= e($request['reason'] ?: '—') ?></td>
                  <td data-label="Review">
                    <form method="post" class="actions schedule-review-form">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="request_id" value="<?= (int) $request['leave_id'] ?>">
                      <button class="button small" type="submit" name="action" value="approve_leave">Approve</button>
                      <button class="button danger small" type="submit" name="action" value="reject_leave">Reject</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr class="empty-row"><td colspan="4"><div class="empty-state-box" role="status"><strong>No leave requests</strong><span>Pending leave dates do not affect availability until approved.</span></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php
});
