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
        if ($requestId === false || !in_array($action, ['approve_schedule', 'reject_schedule', 'approve_leave', 'reject_leave'], true)) {
            $error = 'Choose a valid groomer schedule or leave request.';
        } else {
            try {
                $pdo->beginTransaction();
                if (in_array($action, ['approve_schedule', 'reject_schedule'], true)) {
                    $lookup = $pdo->prepare(
                        'SELECT r.groomer_id, g.full_name, g.user_id
                         FROM GroomerScheduleRequests r JOIN Groomers g ON g.groomer_id = r.groomer_id
                         WHERE r.request_id = ?'
                    );
                    $lookup->execute([(int) $requestId]);
                    $profile = $lookup->fetch();
                    if (!$profile) {
                        throw new InvalidArgumentException('That groomer schedule request no longer exists.');
                    }
                    $groomerId = (int) $profile['groomer_id'];
                    $groomerLock = $pdo->prepare('SELECT groomer_id FROM Groomers WHERE groomer_id = ? FOR UPDATE');
                    $groomerLock->execute([$groomerId]);
                    $requestStatement = $pdo->prepare(
                        "SELECT status FROM GroomerScheduleRequests WHERE request_id = ? AND groomer_id = ? FOR UPDATE"
                    );
                    $requestStatement->execute([(int) $requestId, $groomerId]);
                    if ($requestStatement->fetchColumn() !== 'Pending') {
                        throw new InvalidArgumentException('This schedule request has already been reviewed.');
                    }

                    if ($action === 'approve_schedule') {
                        $dayStatement = $pdo->prepare(
                            'SELECT day_of_week, start_time, end_time
                             FROM GroomerScheduleRequestDays WHERE request_id = ? ORDER BY day_of_week'
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
                             FROM GroomerScheduleRequestBreaks WHERE request_id = ? ORDER BY day_of_week, start_time'
                        );
                        $breakStatement->execute([(int) $requestId]);
                        $breaks = [];
                        foreach ($breakStatement->fetchAll() as $break) {
                            $breaks[(int) $break['day_of_week']][] = $break;
                        }

                        $activeStatuses = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
                        $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
                        $appointmentStatement = $pdo->prepare(
                            "SELECT DISTINCT a.appointment_id, a.appointment_date, a.duration_minutes
                             FROM Appointments a
                             JOIN AppointmentServices aps ON aps.appointment_id = a.appointment_id
                             JOIN Services s ON s.service_id = aps.service_id
                             WHERE aps.groomer_id = ? AND s.service_category = 'Grooming & Hygiene'
                               AND a.status IN ($statusPlaceholders) AND a.appointment_date >= NOW()
                             ORDER BY a.appointment_date FOR UPDATE"
                        );
                        $appointmentStatement->execute(array_merge([$groomerId], $activeStatuses));
                        foreach ($appointmentStatement->fetchAll() as $appointment) {
                            $visitAt = new DateTimeImmutable((string) $appointment['appointment_date']);
                            $dayNumber = (int) $visitAt->format('N');
                            if (!isset($days[$dayNumber])) {
                                throw new InvalidArgumentException('Cannot approve: assigned appointment #' . (int) $appointment['appointment_id'] . ' falls on a proposed non-working day.');
                            }
                            $visitEnd = $visitAt->modify('+' . (int) $appointment['duration_minutes'] . ' minutes');
                            $workStart = new DateTimeImmutable($visitAt->format('Y-m-d') . ' ' . $days[$dayNumber]['start_time']);
                            $workEnd = new DateTimeImmutable($visitAt->format('Y-m-d') . ' ' . $days[$dayNumber]['end_time']);
                            if ($visitAt < $workStart || $visitEnd > $workEnd) {
                                throw new InvalidArgumentException('Cannot approve: assigned appointment #' . (int) $appointment['appointment_id'] . ' falls outside the proposed working hours.');
                            }
                            foreach ($breaks[$dayNumber] ?? [] as $break) {
                                $breakStart = new DateTimeImmutable($visitAt->format('Y-m-d') . ' ' . $break['start_time']);
                                $breakEnd = new DateTimeImmutable($visitAt->format('Y-m-d') . ' ' . $break['end_time']);
                                if ($visitAt < $breakEnd && $visitEnd > $breakStart) {
                                    throw new InvalidArgumentException('Cannot approve: assigned appointment #' . (int) $appointment['appointment_id'] . ' overlaps a proposed break.');
                                }
                            }
                        }

                        $pdo->prepare('DELETE FROM GroomerScheduleBreaks WHERE groomer_id = ?')->execute([$groomerId]);
                        $pdo->prepare('DELETE FROM GroomerSchedules WHERE groomer_id = ?')->execute([$groomerId]);
                        $insertDay = $pdo->prepare(
                            'INSERT INTO GroomerSchedules (groomer_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)'
                        );
                        $insertBreak = $pdo->prepare(
                            'INSERT INTO GroomerScheduleBreaks (groomer_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)'
                        );
                        foreach ($days as $dayNumber => $day) {
                            $insertDay->execute([$groomerId, $dayNumber, $day['start_time'], $day['end_time']]);
                            foreach ($breaks[$dayNumber] ?? [] as $break) {
                                $insertBreak->execute([$groomerId, $dayNumber, $break['start_time'], $break['end_time']]);
                            }
                        }
                        $newStatus = 'Approved';
                    } else {
                        $newStatus = 'Rejected';
                    }
                    $pdo->prepare(
                        'UPDATE GroomerScheduleRequests
                         SET status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), review_note = ?
                         WHERE request_id = ?'
                    )->execute([$newStatus, (int) $_SESSION['user_id'], $reviewNote !== '' ? $reviewNote : null, (int) $requestId]);
                    notification_send_users(
                        $pdo,
                        !empty($profile['user_id']) ? [(int) $profile['user_id']] : [],
                        'schedule',
                        'Grooming schedule request ' . strtolower($newStatus),
                        'Your working hours request was ' . strtolower($newStatus) . '.' . ($reviewNote !== '' ? ' Admin note: ' . $reviewNote : ''),
                        'groomer/schedules.php',
                        'groomer_schedule_reviewed_' . (int) $requestId
                    );
                    role_log($pdo, (int) $_SESSION['user_id'], 'groomer.schedule.' . strtolower($newStatus), 'groomer_schedule_request', (int) $requestId, $reviewNote !== '' ? $reviewNote : $profile['full_name']);
                    $success = 'Groomer schedule request ' . strtolower($newStatus) . '.';
                } else {
                    $lookup = $pdo->prepare(
                        'SELECT l.groomer_id, l.start_date, l.end_date, l.status, g.full_name, g.user_id
                         FROM GroomerLeaves l JOIN Groomers g ON g.groomer_id = l.groomer_id
                         WHERE l.leave_id = ?'
                    );
                    $lookup->execute([(int) $requestId]);
                    $leave = $lookup->fetch();
                    if (!$leave) {
                        throw new InvalidArgumentException('That groomer leave request no longer exists.');
                    }
                    $groomerId = (int) $leave['groomer_id'];
                    $groomerLock = $pdo->prepare('SELECT groomer_id FROM Groomers WHERE groomer_id = ? FOR UPDATE');
                    $groomerLock->execute([$groomerId]);
                    $leaveLock = $pdo->prepare('SELECT status FROM GroomerLeaves WHERE leave_id = ? AND groomer_id = ? FOR UPDATE');
                    $leaveLock->execute([(int) $requestId, $groomerId]);
                    if ($leaveLock->fetchColumn() !== 'Pending') {
                        throw new InvalidArgumentException('This leave request has already been reviewed.');
                    }
                    if ($action === 'approve_leave') {
                        $activeStatuses = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
                        $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
                        $conflictStatement = $pdo->prepare(
                            "SELECT a.appointment_id
                             FROM Appointments a
                             JOIN AppointmentServices aps ON aps.appointment_id = a.appointment_id
                             JOIN Services s ON s.service_id = aps.service_id
                             WHERE aps.groomer_id = ? AND s.service_category = 'Grooming & Hygiene'
                               AND DATE(a.appointment_date) BETWEEN ? AND ?
                               AND a.status IN ($statusPlaceholders)
                             ORDER BY a.appointment_date LIMIT 1 FOR UPDATE"
                        );
                        $conflictStatement->execute(array_merge([$groomerId, $leave['start_date'], $leave['end_date']], $activeStatuses));
                        $conflictId = $conflictStatement->fetchColumn();
                        if ($conflictId) {
                            throw new InvalidArgumentException('Cannot approve leave while grooming appointment #' . (int) $conflictId . ' is assigned in that period.');
                        }
                        $newStatus = 'Approved';
                    } else {
                        $newStatus = 'Rejected';
                    }
                    $pdo->prepare(
                        'UPDATE GroomerLeaves SET status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), review_note = ? WHERE leave_id = ?'
                    )->execute([$newStatus, (int) $_SESSION['user_id'], $reviewNote !== '' ? $reviewNote : null, (int) $requestId]);
                    notification_send_users(
                        $pdo,
                        !empty($leave['user_id']) ? [(int) $leave['user_id']] : [],
                        'schedule',
                        'Groomer leave request ' . strtolower($newStatus),
                        'Your leave request from ' . date('M j, Y', strtotime((string) $leave['start_date']))
                            . ' to ' . date('M j, Y', strtotime((string) $leave['end_date']))
                            . ' was ' . strtolower($newStatus) . '.' . ($reviewNote !== '' ? ' Admin note: ' . $reviewNote : ''),
                        'groomer/schedules.php',
                        'groomer_leave_reviewed_' . (int) $requestId
                    );
                    role_log($pdo, (int) $_SESSION['user_id'], 'groomer.leave.' . strtolower($newStatus), 'groomer_leave', (int) $requestId, $reviewNote !== '' ? $reviewNote : $leave['full_name']);
                    $success = 'Groomer leave request ' . strtolower($newStatus) . '.';
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Admin groomer schedule review: ' . $exception->getMessage());
                $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The request could not be reviewed.';
            }
        }
    }
}

$scheduleRequests = $pdo->query(
    "SELECT r.request_id, r.submitted_at, g.full_name
     FROM GroomerScheduleRequests r JOIN Groomers g ON g.groomer_id = r.groomer_id
     WHERE r.status = 'Pending' ORDER BY r.submitted_at"
)->fetchAll();
$ids = array_map(static function (array $row): int { return (int) $row['request_id']; }, $scheduleRequests);
$requestedDays = [];
$requestedBreaks = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $dayStatement = $pdo->prepare("SELECT request_id, day_of_week, start_time, end_time FROM GroomerScheduleRequestDays WHERE request_id IN ($placeholders) ORDER BY request_id, day_of_week");
    $dayStatement->execute($ids);
    foreach ($dayStatement->fetchAll() as $day) {
        $requestedDays[(int) $day['request_id']][] = $day;
    }
    $breakStatement = $pdo->prepare("SELECT request_id, day_of_week, start_time, end_time FROM GroomerScheduleRequestBreaks WHERE request_id IN ($placeholders) ORDER BY request_id, day_of_week, start_time");
    $breakStatement->execute($ids);
    foreach ($breakStatement->fetchAll() as $break) {
        $requestedBreaks[(int) $break['request_id']][(int) $break['day_of_week']][] = $break;
    }
}
$leaveRequests = $pdo->query(
    "SELECT l.leave_id, l.start_date, l.end_date, l.reason, g.full_name
     FROM GroomerLeaves l JOIN Groomers g ON g.groomer_id = l.groomer_id
     WHERE l.status = 'Pending' ORDER BY l.start_date, l.leave_id"
)->fetchAll();
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

role_render('admin', ['groomer_schedules'], 'Groomer Schedule Requests', 'Approve working hours and leave submitted by groomers', function (array $context) use ($scheduleRequests, $requestedDays, $requestedBreaks, $leaveRequests, $dayNames, $error, $success): void {
    ?>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice success" role="status"><?= e($success) ?></div><?php endif; ?>
    <section class="panel">
      <div class="panel-header"><h2>Working hours awaiting approval</h2><span class="data-count"><?= count($scheduleRequests) ?> pending</span></div>
      <div class="table-wrap data-table-card">
        <table class="records-table">
          <colgroup><col style="width:22%"><col style="width:12rem"><col style="width:34%"><col style="width:18rem"></colgroup>
          <thead><tr><th>Groomer</th><th>Submitted</th><th>Requested weekly hours</th><th>Review</th></tr></thead>
          <tbody>
          <?php if ($scheduleRequests): ?>
          <?php foreach ($scheduleRequests as $request): $requestId = (int) $request['request_id']; ?>
            <tr>
              <td data-label="Groomer"><?= e($request['full_name']) ?></td>
              <td data-label="Submitted"><?= e(date('M j, Y g:i A', strtotime($request['submitted_at']))) ?></td>
              <td data-label="Requested weekly hours">
                <?php foreach ($requestedDays[$requestId] ?? [] as $day): ?>
                  <div><strong><?= e($dayNames[(int) $day['day_of_week']]) ?>:</strong> <?= e(date('g:i A', strtotime($day['start_time'])) . '–' . date('g:i A', strtotime($day['end_time']))) ?>
                    <?php $labels = []; foreach ($requestedBreaks[$requestId][(int) $day['day_of_week']] ?? [] as $break) { $labels[] = date('g:i A', strtotime($break['start_time'])) . '–' . date('g:i A', strtotime($break['end_time'])); } ?>
                    <?php if ($labels): ?><small>Break: <?= e(implode(', ', $labels)) ?></small><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </td>
              <td data-label="Review"><form method="post" class="actions">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= $requestId ?>">
                <label class="field">Review note<input name="review_note" maxlength="255"></label>
                <button class="button small" name="action" value="approve_schedule">Approve &amp; publish</button>
                <button class="button danger small" name="action" value="reject_schedule">Reject</button>
              </form></td>
            </tr>
          <?php endforeach; ?>
          <?php else: ?>
            <tr class="empty-row"><td colspan="4"><div class="empty-state-box" role="status"><strong>No working-hour requests</strong><span>Groomer schedules will appear here for review.</span></div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <section class="panel">
      <div class="panel-header"><h2>Leave awaiting approval</h2><span class="data-count"><?= count($leaveRequests) ?> pending</span></div>
      <div class="table-wrap data-table-card">
        <table class="records-table">
          <colgroup><col style="width:22%"><col style="width:14rem"><col style="width:30%"><col style="width:18rem"></colgroup>
          <thead><tr><th>Groomer</th><th>Requested dates</th><th>Reason</th><th>Review</th></tr></thead>
          <tbody>
          <?php if ($leaveRequests): ?>
          <?php foreach ($leaveRequests as $leave): ?>
            <tr>
              <td data-label="Groomer"><?= e($leave['full_name']) ?></td>
              <td data-label="Requested dates"><?= e(date('M j, Y', strtotime($leave['start_date'])) . ' – ' . date('M j, Y', strtotime($leave['end_date']))) ?></td>
              <td data-label="Reason"><?= e($leave['reason'] ?: '—') ?></td>
              <td data-label="Review"><form method="post" class="actions">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int) $leave['leave_id'] ?>">
                <label class="field">Review note<input name="review_note" maxlength="255"></label>
                <button class="button small" name="action" value="approve_leave">Approve</button>
                <button class="button danger small" name="action" value="reject_leave">Reject</button>
              </form></td>
            </tr>
          <?php endforeach; ?>
          <?php else: ?>
            <tr class="empty-row"><td colspan="4"><div class="empty-state-box" role="status"><strong>No leave requests</strong><span>Pending leave will not affect client booking until Admin approves it.</span></div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php
});
