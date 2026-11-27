<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['staff']);

function groomer_schedule_time(string $value): ?string
{
    return preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $value) ? $value . ':00' : null;
}

function groomer_schedule_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
}

$pdo = db();
$embedded = !empty($_GET['embedded']) || !empty($_POST['embedded']) || !empty($GLOBALS['groomer_schedule_embedded']);
$userId = (int) $_SESSION['user_id'];
$profileStatement = $pdo->prepare(
    "SELECT g.groomer_id, g.full_name, s.`role` AS staff_role,
            (s.can_manage_groomer_schedules = 1 OR s.`role` = 'Groomer') AS can_manage_groomer_schedules
     FROM Users u
     JOIN Staff s ON s.staff_id = u.staff_id
     JOIN Groomers g ON g.user_id = u.user_id
     WHERE u.user_id = ? AND u.`role` = 'staff' AND u.status = 'active' AND g.is_available = 1"
);
$profileStatement->execute([$userId]);
$groomer = $profileStatement->fetch();
if (!$groomer) {
    http_response_code(403);
    exit('A linked, active groomer profile is required. Ask clinic staff to link your account in Grooming.');
}
$isDedicatedGroomerRoute = basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'groomer';
if ($groomer['staff_role'] === 'Groomer' && !$isDedicatedGroomerRoute && !$embedded) {
    header('Location: ../groomer/schedules.php');
    exit;
}
$appointmentsPagePath = $isDedicatedGroomerRoute ? 'appointments.php' : 'groomer.php';
$schedulePagePath = $isDedicatedGroomerRoute ? 'schedules.php' : 'groomer_schedule.php';
$groomerId = (int) $groomer['groomer_id'];
$scheduleActiveMenu = $groomer['staff_role'] === 'Groomer' ? ['schedules'] : ['grooming'];
$hasScheduleAccess = (bool) $groomer['can_manage_groomer_schedules'];
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$error = '';
$success = (string) ($_SESSION['groomer_schedule_flash'] ?? '');
unset($_SESSION['groomer_schedule_flash']);
$openPanel = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = posted_string('action');
    $openPanel = $action === 'request_leave' ? 'leave' : 'hours';
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (!$hasScheduleAccess) {
        http_response_code(403);
        exit('Admin must grant groomer schedule access before you can request hours or leave.');
    } else {
        try {
            $pdo->beginTransaction();
            $profileLock = $pdo->prepare(
                "SELECT g.groomer_id
                 FROM Users u
                 JOIN Staff s ON s.staff_id = u.staff_id
                 JOIN Groomers g ON g.user_id = u.user_id
                 WHERE u.user_id = ?
                   AND (s.can_manage_groomer_schedules = 1 OR s.`role` = 'Groomer')
                   AND g.is_available = 1
                 FOR UPDATE"
            );
            $profileLock->execute([$userId]);
            if ((int) $profileLock->fetchColumn() !== $groomerId) {
                throw new InvalidArgumentException('Admin schedule access is required before requesting hours or leave.');
            }

            if ($action === 'submit_schedule') {
                $pendingStatement = $pdo->prepare(
                    "SELECT request_id FROM GroomerScheduleRequests
                     WHERE groomer_id = ? AND status = 'Pending'
                     LIMIT 1 FOR UPDATE"
                );
                $pendingStatement->execute([$groomerId]);
                $pendingRequestId = (int) $pendingStatement->fetchColumn();

                $rawDays = $_POST['work_days'] ?? [];
                $startTimes = is_array($_POST['start_times'] ?? null) ? $_POST['start_times'] : [];
                $endTimes = is_array($_POST['end_times'] ?? null) ? $_POST['end_times'] : [];
                $rawBreaks = is_array($_POST['breaks'] ?? null) ? $_POST['breaks'] : [];
                if (!is_array($rawDays)) {
                    throw new InvalidArgumentException('Choose at least one working day.');
                }
                $requestedDays = [];
                foreach ($rawDays as $rawDay) {
                    $day = filter_var($rawDay, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
                    if ($day === false || isset($requestedDays[(int) $day])) {
                        if ($day === false) {
                            throw new InvalidArgumentException('Choose valid working days.');
                        }
                        continue;
                    }
                    $day = (int) $day;
                    $start = groomer_schedule_time(is_string($startTimes[$day] ?? null) ? $startTimes[$day] : '');
                    $end = groomer_schedule_time(is_string($endTimes[$day] ?? null) ? $endTimes[$day] : '');
                    if ($start === null || $end === null || $start >= $end) {
                        throw new InvalidArgumentException($days[$day] . ': enter valid working hours.');
                    }

                    $breaks = [];
                    $dayBreaks = $rawBreaks[$day] ?? [];
                    if (!is_array($dayBreaks) || count($dayBreaks) > 6) {
                      throw new InvalidArgumentException($days[$day] . ': add no more than six breaks.');
                    }
                    foreach ($dayBreaks as $break) {
                      if (!is_array($break)) {
                        throw new InvalidArgumentException($days[$day] . ': enter valid break times.');
                        }
                      $breakStartValue = is_string($break['start_time'] ?? null) ? $break['start_time'] : '';
                      $breakEndValue = is_string($break['end_time'] ?? null) ? $break['end_time'] : '';
                      if ($breakStartValue === '' && $breakEndValue === '') {
                        continue;
                      }
                      $breakStart = groomer_schedule_time($breakStartValue);
                      $breakEnd = groomer_schedule_time($breakEndValue);
                      if ($breakStart === null || $breakEnd === null || $breakStart >= $breakEnd
                        || $breakStart < $start || $breakEnd > $end) {
                        throw new InvalidArgumentException($days[$day] . ': breaks must fall within working hours.');
                      }
                      $breaks[] = [$breakStart, $breakEnd];
                    }
                    usort($breaks, static function (array $first, array $second): int {
                      return strcmp($first[0], $second[0]);
                    });
                    for ($index = 1; $index < count($breaks); $index++) {
                      if ($breaks[$index][0] < $breaks[$index - 1][1]) {
                        throw new InvalidArgumentException($days[$day] . ': breaks cannot overlap.');
                      }
                    }
                    $requestedDays[$day] = ['start' => $start, 'end' => $end, 'breaks' => $breaks];
                }
                if (!$requestedDays) {
                    throw new InvalidArgumentException('Choose at least one working day.');
                }

                if ($pendingRequestId > 0) {
                  $requestId = $pendingRequestId;
                  $pdo->prepare('DELETE FROM GroomerScheduleRequestBreaks WHERE request_id = ?')->execute([$requestId]);
                  $pdo->prepare('DELETE FROM GroomerScheduleRequestDays WHERE request_id = ?')->execute([$requestId]);
                  $pdo->prepare(
                    "UPDATE GroomerScheduleRequests
                     SET submitted_at = NOW(), reviewed_by_user_id = NULL, reviewed_at = NULL, review_note = NULL
                     WHERE request_id = ? AND groomer_id = ? AND status = 'Pending'"
                  )->execute([$requestId, $groomerId]);
                } else {
                  $pdo->prepare(
                    "INSERT INTO GroomerScheduleRequests (groomer_id, submitted_by_user_id, status)
                     VALUES (?, ?, 'Pending')"
                  )->execute([$groomerId, $userId]);
                  $requestId = (int) $pdo->lastInsertId();
                }
                $insertDay = $pdo->prepare(
                    'INSERT INTO GroomerScheduleRequestDays (request_id, day_of_week, start_time, end_time)
                     VALUES (?, ?, ?, ?)'
                );
                $insertBreak = $pdo->prepare(
                    'INSERT INTO GroomerScheduleRequestBreaks (request_id, day_of_week, start_time, end_time)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($requestedDays as $day => $schedule) {
                    $insertDay->execute([$requestId, $day, $schedule['start'], $schedule['end']]);
                    foreach ($schedule['breaks'] as [$breakStart, $breakEnd]) {
                        $insertBreak->execute([$requestId, $day, $breakStart, $breakEnd]);
                    }
                }
                role_notify_role(
                    $pdo,
                    'admin',
                    'schedule',
                    $pendingRequestId > 0 ? 'Groomer schedule request updated' : 'Groomer schedule request',
                    $groomer['full_name'] . ($pendingRequestId > 0 ? ' updated' : ' submitted') . ' working hours for Admin review.',
                    'admin/groomer_schedule_requests.php',
                    'groomer_schedule_request_' . $requestId . '_' . date('YmdHis')
                );
                  role_log($pdo, $userId, $pendingRequestId > 0 ? 'groomer.schedule.updated' : 'groomer.schedule.requested', 'groomer_schedule_request', $requestId, $pendingRequestId > 0 ? 'Updated pending working hours for Admin review' : 'Submitted working hours for Admin review');
                  $success = $pendingRequestId > 0
                    ? 'Pending schedule request updated. Hours will not appear in client booking until Admin approves them.'
                    : 'Schedule request submitted. Hours will not appear in client booking until Admin approves them.';
            } elseif ($action === 'request_leave') {
                $startDateValue = posted_string('start_date');
                $endDateValue = posted_string('end_date');
                $startDate = groomer_schedule_date($startDateValue);
                $endDate = groomer_schedule_date($endDateValue);
                $reason = mb_substr(trim(posted_string('reason')), 0, 150);
                if (!$startDate || !$endDate || $startDate < new DateTimeImmutable('today') || $endDate < $startDate) {
                    throw new InvalidArgumentException('Enter a valid leave period beginning today or later.');
                }
                $overlap = $pdo->prepare(
                    "SELECT leave_id FROM GroomerLeaves
                     WHERE groomer_id = ? AND status IN ('Pending','Approved')
                       AND start_date <= ? AND end_date >= ?
                     LIMIT 1 FOR UPDATE"
                );
                $overlap->execute([$groomerId, $endDateValue, $startDateValue]);
                if ($overlap->fetchColumn()) {
                    throw new InvalidArgumentException('That period overlaps an existing pending or approved leave.');
                }
                $pdo->prepare(
                    "INSERT INTO GroomerLeaves (groomer_id, start_date, end_date, reason, status, submitted_by_user_id)
                     VALUES (?, ?, ?, ?, 'Pending', ?)"
                )->execute([$groomerId, $startDateValue, $endDateValue, $reason !== '' ? $reason : null, $userId]);
                $leaveId = (int) $pdo->lastInsertId();
                role_notify_role(
                    $pdo,
                    'admin',
                    'schedule',
                    'Groomer leave request',
                    $groomer['full_name'] . ' requested leave from ' . $startDate->format('M j, Y') . ' to ' . $endDate->format('M j, Y') . '.',
                    'admin/groomer_schedule_requests.php',
                    'groomer_leave_request_' . $leaveId
                );
                role_log($pdo, $userId, 'groomer.leave.requested', 'groomer_leave', $leaveId, 'Requested leave ' . $startDateValue . ' through ' . $endDateValue);
                $success = 'Leave request submitted for Admin review.';
            } else {
                throw new InvalidArgumentException('Unsupported schedule request.');
            }

            $pdo->commit();
            $_SESSION['groomer_schedule_flash'] = $success;
            header('Location: ' . ($embedded ? $appointmentsPagePath . '#my-grooming-schedule' : $schedulePagePath));
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Groomer schedule request: ' . $exception->getMessage());
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The request could not be saved.';
        }
    }
}

$publishedStatement = $pdo->prepare(
    'SELECT day_of_week, start_time, end_time FROM GroomerSchedules WHERE groomer_id = ? ORDER BY day_of_week'
);
$publishedStatement->execute([$groomerId]);
$published = $publishedStatement->fetchAll();
$publishedDays = [];
foreach ($published as $schedule) {
  $publishedDays[(int) $schedule['day_of_week']] = $schedule;
}
$publishedBreakStatement = $pdo->prepare(
  'SELECT day_of_week, start_time, end_time
   FROM GroomerScheduleBreaks WHERE groomer_id = ? ORDER BY day_of_week, start_time'
);
$publishedBreakStatement->execute([$groomerId]);
$publishedBreaks = [];
foreach ($publishedBreakStatement->fetchAll() as $break) {
  $publishedBreaks[(int) $break['day_of_week']][] = $break;
}
$requestStatement = $pdo->prepare(
    'SELECT request_id, status, submitted_at, review_note
     FROM GroomerScheduleRequests WHERE groomer_id = ? ORDER BY submitted_at DESC LIMIT 10'
);
$requestStatement->execute([$groomerId]);
$requests = $requestStatement->fetchAll();
$leaveStatement = $pdo->prepare(
    "SELECT leave_id, start_date, end_date, reason, status
     FROM GroomerLeaves WHERE groomer_id = ? AND (status = 'Pending' OR end_date >= CURRENT_DATE)
     ORDER BY start_date LIMIT 20"
);
$leaveStatement->execute([$groomerId]);
$leaves = $leaveStatement->fetchAll();
$calendarSchedule = [];
foreach ($publishedDays as $day => $schedule) {
  $dayBreaks = [];
  foreach ($publishedBreaks[$day] ?? [] as $break) {
    $dayBreaks[] = [substr((string) $break['start_time'], 0, 5), substr((string) $break['end_time'], 0, 5)];
  }
  $calendarSchedule[$day] = [
    'start' => substr((string) $schedule['start_time'], 0, 5),
    'end' => substr((string) $schedule['end_time'], 0, 5),
    'breaks' => $dayBreaks,
  ];
}
$calendarLeaves = array_map(static function (array $leave): array {
  return [
    'start' => (string) $leave['start_date'],
    'end' => (string) $leave['end_date'],
    'status' => (string) $leave['status'],
    'reason' => (string) ($leave['reason'] ?? ''),
  ];
}, $leaves);
$calendarData = [
  'schedule' => $calendarSchedule,
  'leaves' => $calendarLeaves,
  'today' => date('Y-m-d'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
$hasPendingSchedule = false;
$pendingScheduleDate = '';
foreach ($requests as $request) {
  if ($request['status'] === 'Pending') {
    $hasPendingSchedule = true;
    $pendingScheduleDate = date('M j, Y', strtotime($request['submitted_at']));
    break;
  }
}
$pendingLeaves = count(array_filter($leaves, static function (array $leave): bool {
  return $leave['status'] === 'Pending';
}));

$renderSchedule = function (array $context = []) use ($embedded, $groomer, $hasScheduleAccess, $days, $published, $publishedDays, $publishedBreaks, $requests, $leaves, $calendarData, $jsonFlags, $hasPendingSchedule, $pendingScheduleDate, $pendingLeaves, $error, $success, $openPanel): void {
    ?>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice success" role="status"><?= e($success) ?></div><?php endif; ?>
    <?php if (!$embedded): ?><div class="panel-actions"><a class="button secondary small" href="<?= e($appointmentsPagePath) ?>">Back to Grooming</a></div><?php endif; ?>
    <?php if (!$hasScheduleAccess): ?>
      <div class="notice info" role="status">Admin must grant this staff account grooming schedule access before working hours or leave can be requested. Requests remain unavailable until access is granted.</div>
    <?php else: ?>
      <style>
        .gcal-toolbar{margin-bottom:14px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
        .gcal-nav,.gcal-views{display:flex;align-items:center;gap:.4rem}
        .gcal-nav button{min-width:36px;min-height:36px}
        .gcal-title{min-width:10rem;margin:0 .4rem;color:#123d5c;font-size:1.15rem;font-weight:800}
        .gcal-view{padding:.4rem .9rem;color:#56738a;background:transparent;border:0;border-radius:8px;font:inherit;font-weight:700;cursor:pointer}
        .gcal-view[aria-pressed="true"]{color:#fff;background:#0284c7}
        .gcal-frame{overflow:hidden;background:#fff;border:1px solid #d5e5f1;border-radius:12px}
        .gcal-dow,.gcal-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
        .gcal-dow{background:#f7fafc;border-bottom:1px solid #d5e5f1}
        .gcal-dow span{padding:.55rem .4rem;color:#56738a;font-size:.76rem;font-weight:800;text-align:center}
        .gcal-day{min-width:0;min-height:6.5rem;padding:.4rem;display:grid;align-content:start;gap:.25rem;border-right:1px solid #e3edf5;border-bottom:1px solid #e3edf5}
        .gcal-day:nth-child(7n){border-right:0}.gcal-day.is-out{background:#fafcfd;color:#9bb0c0}.gcal-day.is-today .gcal-date{color:#fff;background:#0284c7}
        .gcal-date{width:1.6rem;height:1.6rem;display:grid;place-items:center;border-radius:50%;font-size:.78rem;font-weight:700}
        .gcal-event{padding:.2rem .4rem;overflow:hidden;color:#fff;background:#0284c7;border-radius:5px;font-size:.68rem;font-weight:700;white-space:nowrap;text-overflow:ellipsis}
        .gcal-event.leave-approved{background:#2f8f5b}.gcal-event.leave-pending{color:#684900;background:#f3c65e}
        .gcal-break{color:#7a5a00;background:#fff8df;border:1px solid #f1d98a}
        .gcal-legend{margin-top:12px;display:flex;flex-wrap:wrap;gap:14px;color:#56738a;font-size:.75rem}
        .gcal-legend i{width:11px;height:11px;margin-right:5px;display:inline-block;border-radius:3px;background:#0284c7}
        .gcal-legend .approved{background:#2f8f5b}.gcal-legend .pending{background:#e0a21b}.gcal-legend .break{background:#f3c65e}
        .groomer-schedule-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .groomer-action-row{margin-bottom:14px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .groomer-action{padding:18px;display:grid;gap:8px;background:#fff;border:1px solid #d5e5f1;border-radius:12px}
        .groomer-action h2{margin:0;color:#123d5c;font-size:1.05rem}.groomer-action p{margin:0;color:#56738a;font-size:.86rem}
        .groomer-day-chip-row{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}
        .groomer-day-chip{padding:10px 4px;display:grid;justify-items:center;gap:4px;color:#56738a;background:#f7fafc;border:1px dashed #b9d3e6;border-radius:11px;cursor:pointer}
        .groomer-day-chip input{margin:0}.groomer-day-chip:has(input:checked){color:#fff;background:#0284c7;border-color:#0284c7}
        .groomer-day-card{margin-top:10px;padding:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;background:#fff;border-left:4px solid #0284c7;border-radius:9px}
        .groomer-day-card[hidden]{display:none}.groomer-day-card h3{grid-column:1/-1;margin:0;font-size:.92rem}
        .groomer-break-list{grid-column:1/-1;display:grid;gap:8px}.groomer-break-row{display:flex;align-items:end;gap:8px}
        .groomer-break-row .field{flex:1}.groomer-request-list{padding-left:18px}.groomer-request-list li{margin:8px 0}.groomer-request-list small{display:block;color:var(--muted)}
        @media(max-width:850px){.groomer-schedule-grid,.groomer-action-row{grid-template-columns:1fr}.gcal-day{min-height:5rem}}
        @media(max-width:600px){.groomer-day-chip-row{grid-template-columns:repeat(4,minmax(0,1fr))}.gcal-day{min-height:4.5rem;padding:.25rem}.gcal-event{font-size:.58rem}.gcal-dow span{font-size:.67rem}}
      </style>
      <section class="panel">
        <div class="panel-header"><h2>My schedule</h2><span class="data-count">Admin-approved</span></div>
        <div class="panel-body">
          <?php if (!$publishedDays): ?><div class="notice cal-empty-note" role="status">No working hours have been published yet. Submit a request below; hours appear here after Admin approval.</div><?php endif; ?>
          <div class="gcal-toolbar">
            <div class="gcal-nav">
              <button class="button secondary small" type="button" id="gcal-prev" aria-label="Previous month or week">‹</button>
              <button class="button secondary small" type="button" id="gcal-next" aria-label="Next month or week">›</button>
              <h3 class="gcal-title" id="gcal-title" aria-live="polite"></h3>
              <button class="button secondary small" type="button" id="gcal-today">Today</button>
            </div>
            <div class="gcal-views" role="group" aria-label="Calendar view">
              <button class="gcal-view" type="button" data-gcal-view="week" aria-pressed="false">Week</button>
              <button class="gcal-view" type="button" data-gcal-view="month" aria-pressed="true">Month</button>
            </div>
          </div>
          <div class="gcal-frame" id="gcal-frame" role="region" aria-label="Published groomer schedule calendar"></div>
          <div class="gcal-legend"><span><i></i>Working hours</span><span><i class="break"></i>Break</span><span><i class="approved"></i>Approved leave</span><span><i class="pending"></i>Leave awaiting review</span></div>
        </div>
        <script type="application/json" id="gcal-data"><?= json_encode($calendarData, $jsonFlags) ?></script>
      </section>
      <section class="panel">
        <div class="panel-body"><p class="hint">Only approved working hours are used in client booking. Existing appointments are not moved automatically.</p></div>
      </section>

      <div class="groomer-action-row">
        <article class="groomer-action"><h2>Request working hours</h2><p>Propose a weekly schedule. It becomes bookable only after Admin approval.</p><span class="sched-action-status"><?= $hasPendingSchedule ? 'Request from ' . e($pendingScheduleDate) . ' awaiting review' : '&nbsp;' ?></span><button class="button" type="button" id="toggle-hours" aria-expanded="false" aria-controls="drop-hours"><?= $hasPendingSchedule ? 'Edit pending request' : 'Request hours' ?></button></article>
        <article class="groomer-action"><h2>Request leave</h2><p>Approved leave is removed from client booking availability.</p><span class="sched-action-status"><?= $pendingLeaves ? e((string) $pendingLeaves) . ' leave request' . ($pendingLeaves === 1 ? '' : 's') . ' awaiting review' : '&nbsp;' ?></span><button class="button" type="button" id="toggle-leave" aria-expanded="false" aria-controls="drop-leave">Request leave</button></article>
      </div>

      <section class="panel groomer-request-drop" id="drop-hours" hidden>
        <div class="panel-header"><h2>Request working hours</h2><span class="data-count">Requires Admin approval</span></div>
        <form class="panel-body" method="post" action="<?= e($schedulePagePath) ?>" id="groomer-schedule-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="submit_schedule"><input type="hidden" name="embedded" value="<?= $embedded ? '1' : '0' ?>">
          <?php if ($hasPendingSchedule): ?><div class="notice" role="status">Your schedule request from <?= e($pendingScheduleDate) ?> is waiting for Admin review. You can update its working days and breaks here; Admin approval is still required.</div><?php endif; ?>
          <p class="hint">Only approved requests update bookable hours. Existing appointments are not moved automatically.</p>
          <div class="notice error" id="groomer-schedule-error" role="alert" hidden></div>
          <div class="sched-bar"><span class="sched-summary" id="groomer-schedule-summary" aria-live="polite"></span><div class="sched-bar-actions"><button class="button secondary small" type="button" id="preset-weekdays">Mon-Fri</button><button class="button secondary small" type="button" id="preset-sixdays">Mon-Sat</button><button class="button secondary small" type="button" id="copy-first-day">Copy first day to the others</button><button class="button ghost small" type="button" id="clear-days">Clear all</button></div></div>
          <div class="groomer-day-chip-row" role="group" aria-label="Working days">
            <?php foreach ($days as $dayNumber => $dayLabel): $schedule = $publishedDays[$dayNumber] ?? null; ?>
              <label class="groomer-day-chip" data-chip="<?= $dayNumber ?>"><input type="checkbox" name="work_days[]" value="<?= $dayNumber ?>" data-day-toggle<?= $schedule ? ' checked' : '' ?>><strong><?= e(substr($dayLabel, 0, 3)) ?></strong><span data-chip-time><?= $schedule ? e(date('g:ia', strtotime($schedule['start_time'])) . '–' . date('g:ia', strtotime($schedule['end_time']))) : 'Off' ?></span></label>
            <?php endforeach; ?>
          </div>
          <p class="sched-rows-empty" id="sched-rows-empty"<?= $publishedDays ? ' hidden' : '' ?>>Choose a day above to build your weekly schedule.</p>
          <div class="groomer-day-rows">
            <?php foreach ($days as $dayNumber => $dayLabel): $schedule = $publishedDays[$dayNumber] ?? null; ?>
              <section class="groomer-day-card" data-schedule-day data-day="<?= $dayNumber ?>"<?= $schedule ? '' : ' hidden' ?>>
                <h3><?= e($dayLabel) ?></h3>
                <label class="field">Start<input type="time" name="start_times[<?= $dayNumber ?>]" value="<?= e($schedule ? substr((string) $schedule['start_time'], 0, 5) : '09:00') ?>" data-day-time="start"<?= $schedule ? '' : ' disabled' ?>></label>
                <label class="field">End<input type="time" name="end_times[<?= $dayNumber ?>]" value="<?= e($schedule ? substr((string) $schedule['end_time'], 0, 5) : '17:00') ?>" data-day-time="end"<?= $schedule ? '' : ' disabled' ?>></label>
                <div class="groomer-break-list" data-break-list data-next-index="<?= count($publishedBreaks[$dayNumber] ?? []) ?>">
                  <?php foreach ($publishedBreaks[$dayNumber] ?? [] as $breakIndex => $break): ?>
                    <div class="groomer-break-row" data-break-row><label class="field">Break from<input type="time" name="breaks[<?= $dayNumber ?>][<?= $breakIndex ?>][start_time]" value="<?= e(substr((string) $break['start_time'], 0, 5)) ?>" data-break-time="start"></label><label class="field">To<input type="time" name="breaks[<?= $dayNumber ?>][<?= $breakIndex ?>][end_time]" value="<?= e(substr((string) $break['end_time'], 0, 5)) ?>" data-break-time="end"></label><button class="button secondary small" type="button" data-remove-break aria-label="Remove break">Remove</button></div>
                  <?php endforeach; ?>
                </div>
                <div class="groomer-break-actions"><button class="button secondary small" type="button" data-add-lunch>Add lunch (1 hr)</button><button class="button secondary small" type="button" data-add-break>Add break</button><button class="button ghost small" type="button" data-remove-day>Remove day</button></div>
              </section>
            <?php endforeach; ?>
          </div>
          <p class="sched-summary" id="groomer-schedule-summary-total" aria-live="polite"></p>
          <button class="button" type="submit"><?= $hasPendingSchedule ? 'Update pending request' : 'Submit schedule request' ?></button>
        </form>
      </section>

      <section class="panel groomer-request-drop" id="drop-leave" hidden>
        <div class="panel-header"><h2>Request leave</h2><span class="data-count">Requires Admin approval</span></div>
        <form class="panel-body form-grid" method="post" action="<?= e($schedulePagePath) ?>" id="groomer-leave-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="request_leave"><input type="hidden" name="embedded" value="<?= $embedded ? '1' : '0' ?>">
          <label class="field">Start date<input type="date" name="start_date" min="<?= e(date('Y-m-d')) ?>" required></label>
          <label class="field">End date<input type="date" name="end_date" min="<?= e(date('Y-m-d')) ?>" required></label>
          <label class="field wide">Reason (optional)<input name="reason" maxlength="150" placeholder="Reason for leave"></label>
          <p class="hint wide" id="leave-length" aria-live="polite">Choose your first and last day away.</p>
          <div class="actions wide"><button class="button" type="submit">Submit leave request</button></div>
        </form>
      </section>

      <section class="panel">
        <div class="panel-header"><h2>Schedule requests</h2><span class="data-count"><?= count($requests) ?> recent</span></div>
        <div class="table-wrap data-table-card"><table class="records-table"><thead><tr><th>Submitted</th><th>Status</th><th>Admin note</th></tr></thead><tbody>
          <?php foreach ($requests as $request): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($request['submitted_at']))) ?></td><td><span class="status <?= e(role_status_class($request['status'])) ?>"><?= e($request['status']) ?></span></td><td><?= e($request['review_note'] ?: '—') ?></td></tr><?php endforeach; ?>
          <?php if (!$requests): ?><tr class="empty-row"><td colspan="3"><div class="empty-state-box"><strong>No schedule requests yet</strong><small>Pending requests do not change client booking availability.</small></div></td></tr><?php endif; ?>
        </tbody></table></div>
      </section>
      <script>
        (function () {
          var data = JSON.parse(document.getElementById('gcal-data').textContent);
          var calendar = document.getElementById('gcal-frame');
          var title = document.getElementById('gcal-title');
          var cursorParts = data.today.split('-');
          var cursor = new Date(Number(cursorParts[0]), Number(cursorParts[1]) - 1, Number(cursorParts[2]));
          var view = 'month';
          var shortDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
          var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
          function dateKey(date) {
            return date.getFullYear() + '-' + ('0' + (date.getMonth() + 1)).slice(-2) + '-' + ('0' + date.getDate()).slice(-2);
          }
          function leaveFor(key) {
            return data.leaves.find(function (leave) { return leave.start <= key && key <= leave.end; });
          }
          function dayNumber(date) { return date.getDay() || 7; }
          function appendEvent(cell, text, className, titleText) {
            var event = document.createElement('span');
            event.className = 'gcal-event' + (className ? ' ' + className : '');
            event.textContent = text;
            if (titleText) event.title = titleText;
            cell.appendChild(event);
          }
          function renderDay(cell, date, isOut) {
            var key = dateKey(date), day = dayNumber(date), leave = leaveFor(key), schedule = data.schedule[day];
            cell.className = 'gcal-day' + (isOut ? ' is-out' : '') + (key === data.today ? ' is-today' : '');
            var dateLabel = document.createElement('span');
            dateLabel.className = 'gcal-date';
            dateLabel.textContent = String(date.getDate());
            cell.appendChild(dateLabel);
            if (leave) {
              appendEvent(cell, leave.status === 'Approved' ? 'On leave' : 'Leave pending', leave.status === 'Approved' ? 'leave-approved' : 'leave-pending', leave.reason);
            } else if (schedule) {
              appendEvent(cell, schedule.start + '–' + schedule.end, '', 'Published working hours');
              schedule.breaks.forEach(function (breakTime) {
                appendEvent(cell, 'Break ' + breakTime[0] + '–' + breakTime[1], 'gcal-break');
              });
            }
          }
          function renderCalendar() {
            calendar.replaceChildren();
            var grid = document.createElement('div');
            grid.className = 'gcal-days';
            if (view === 'month') {
              var year = cursor.getFullYear(), month = cursor.getMonth();
              title.textContent = monthNames[month] + ' ' + year;
              var weekdays = document.createElement('div');
              weekdays.className = 'gcal-dow';
              shortDays.forEach(function (day) { var item = document.createElement('span'); item.textContent = day; weekdays.appendChild(item); });
              calendar.appendChild(weekdays);
              var offset = (new Date(year, month, 1).getDay() + 6) % 7;
              var count = new Date(year, month + 1, 0).getDate();
              var weeks = Math.ceil((offset + count) / 7);
              for (var index = 0; index < weeks * 7; index++) {
                var date = new Date(year, month, 1 - offset + index), cell = document.createElement('div');
                renderDay(cell, date, date.getMonth() !== month); grid.appendChild(cell);
              }
            } else {
              var monday = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() - ((cursor.getDay() + 6) % 7));
              var sunday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + 6);
              title.textContent = monthNames[monday.getMonth()].slice(0, 3) + ' ' + monday.getDate() + ' – ' + monthNames[sunday.getMonth()].slice(0, 3) + ' ' + sunday.getDate() + ', ' + sunday.getFullYear();
              var weekHeader = document.createElement('div'); weekHeader.className = 'gcal-dow';
              shortDays.forEach(function (day) { var item = document.createElement('span'); item.textContent = day; weekHeader.appendChild(item); });
              calendar.appendChild(weekHeader);
              for (var dayIndex = 0; dayIndex < 7; dayIndex++) {
                var weekDate = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + dayIndex), weekCell = document.createElement('div');
                renderDay(weekCell, weekDate, false); grid.appendChild(weekCell);
              }
            }
            calendar.appendChild(grid);
            document.querySelectorAll('[data-gcal-view]').forEach(function (button) {
              button.setAttribute('aria-pressed', button.dataset.gcalView === view ? 'true' : 'false');
            });
          }
          document.getElementById('gcal-prev').addEventListener('click', function () { cursor = new Date(cursor.getFullYear(), cursor.getMonth() + (view === 'month' ? -1 : 0), cursor.getDate() + (view === 'week' ? -7 : 0)); renderCalendar(); });
          document.getElementById('gcal-next').addEventListener('click', function () { cursor = new Date(cursor.getFullYear(), cursor.getMonth() + (view === 'month' ? 1 : 0), cursor.getDate() + (view === 'week' ? 7 : 0)); renderCalendar(); });
          document.getElementById('gcal-today').addEventListener('click', function () { cursor = new Date(Number(cursorParts[0]), Number(cursorParts[1]) - 1, Number(cursorParts[2])); renderCalendar(); });
          document.querySelectorAll('[data-gcal-view]').forEach(function (button) { button.addEventListener('click', function () { view = button.dataset.gcalView; renderCalendar(); }); });
          renderCalendar();

          var hoursPanel = document.getElementById('drop-hours'), leavePanel = document.getElementById('drop-leave');
          function togglePanel(panel, button) {
            var open = panel.hidden;
            panel.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
          document.getElementById('toggle-hours').addEventListener('click', function () { togglePanel(hoursPanel, this); });
          document.getElementById('toggle-leave').addEventListener('click', function () { togglePanel(leavePanel, this); });
          var form = document.getElementById('groomer-schedule-form');
          var dayRows = Array.from(form.querySelectorAll('[data-schedule-day]'));
          var dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
          function minutes(value) { if (!value) return null; var parts = value.split(':'); return Number(parts[0]) * 60 + Number(parts[1]); }
          function clock(value) { if (!value) return '—'; var time = minutes(value), hour = Math.floor(time / 60); return (hour % 12 || 12) + (time % 60 ? ':' + ('0' + (time % 60)).slice(-2) : '') + (hour >= 12 ? 'p' : 'a'); }
          function breaksFor(row) { return Array.from(row.querySelectorAll('[data-break-row]')); }
          function updateDay(row) {
            var day = row.dataset.day, chip = document.querySelector('[data-chip="' + day + '"]'), toggle = chip.querySelector('[data-day-toggle]');
            var start = row.querySelector('[data-day-time="start"]'), end = row.querySelector('[data-day-time="end"]');
            row.hidden = !toggle.checked; chip.classList.toggle('is-on', toggle.checked);
            start.disabled = end.disabled = !toggle.checked;
            row.querySelectorAll('[data-break-row] input').forEach(function (input) { input.disabled = !toggle.checked; });
            row.querySelectorAll('[data-add-break],[data-add-lunch]').forEach(function (button) { button.disabled = !toggle.checked; });
            chip.querySelector('[data-chip-time]').textContent = !toggle.checked ? 'Off' : clock(start.value) + '–' + clock(end.value);
            var total = (minutes(end.value) || 0) - (minutes(start.value) || 0);
            breaksFor(row).forEach(function (breakRow) { var from = minutes(breakRow.querySelector('[data-break-time="start"]').value), to = minutes(breakRow.querySelector('[data-break-time="end"]').value); if (from !== null && to !== null && to > from) total -= to - from; });
            return toggle.checked && total > 0 ? total : 0;
          }
          function updateSummary() {
            var daysCount = 0, total = 0;
            dayRows.forEach(function (row) { var dayTotal = updateDay(row); if (dayTotal) { daysCount++; total += dayTotal; } });
            document.getElementById('groomer-schedule-summary').textContent = daysCount + ' working day' + (daysCount === 1 ? '' : 's') + ' selected';
            var hours = Math.floor(total / 60), mins = total % 60;
            document.getElementById('groomer-schedule-summary-total').textContent = daysCount + ' working day' + (daysCount === 1 ? '' : 's') + ' · ' + hours + (mins ? ' h ' + mins + ' min' : ' h') + ' per week, excluding breaks';
            document.getElementById('sched-rows-empty').hidden = daysCount > 0;
          }
          function bindBreak(row) {
            row.querySelector('[data-remove-break]').addEventListener('click', function () { row.remove(); updateSummary(); });
            row.querySelectorAll('input').forEach(function (input) { input.addEventListener('input', updateSummary); });
          }
          function addBreak(dayRow, startValue, endValue) {
            var day = dayRow.dataset.day, list = dayRow.querySelector('[data-break-list]'), index = Number(list.dataset.nextIndex);
            list.dataset.nextIndex = String(index + 1);
            var row = document.createElement('div'); row.className = 'groomer-break-row'; row.dataset.breakRow = '';
            row.innerHTML = '<label class="field">Break from<input type="time" name="breaks[' + day + '][' + index + '][start_time]" data-break-time="start"></label><label class="field">To<input type="time" name="breaks[' + day + '][' + index + '][end_time]" data-break-time="end"></label><button class="button secondary small" type="button" data-remove-break aria-label="Remove break">Remove</button>';
            row.querySelector('[data-break-time="start"]').value = startValue || '';
            row.querySelector('[data-break-time="end"]').value = endValue || '';
            list.appendChild(row); bindBreak(row); updateSummary();
          }
          dayRows.forEach(function (row) {
            var day = row.dataset.day, chip = document.querySelector('[data-chip="' + day + '"]'), toggle = chip.querySelector('[data-day-toggle]');
            toggle.addEventListener('change', updateSummary);
            row.querySelectorAll('input[type="time"]').forEach(function (input) { input.addEventListener('input', updateSummary); });
            breaksFor(row).forEach(bindBreak);
            row.querySelector('[data-add-break]').addEventListener('click', function () { addBreak(row); });
            row.querySelector('[data-add-lunch]').addEventListener('click', function () {
              var start = minutes(row.querySelector('[data-day-time="start"]').value) || 12 * 60;
              addBreak(row, ('0' + Math.floor(start / 60)).slice(-2) + ':' + ('0' + (start % 60)).slice(-2), ('0' + Math.floor((start + 60) / 60)).slice(-2) + ':' + ('0' + ((start + 60) % 60)).slice(-2));
            });
            row.querySelector('[data-remove-day]').addEventListener('click', function () { toggle.checked = false; updateSummary(); });
          });
          document.getElementById('preset-weekdays').addEventListener('click', function () { dayRows.forEach(function (row) { var check = Number(row.dataset.day) <= 5; row.querySelector('[data-day-toggle]').checked = check; if (check) { row.querySelector('[data-day-time="start"]').value = '09:00'; row.querySelector('[data-day-time="end"]').value = '17:00'; } }); updateSummary(); });
          document.getElementById('preset-sixdays').addEventListener('click', function () { dayRows.forEach(function (row) { var check = Number(row.dataset.day) <= 6; row.querySelector('[data-day-toggle]').checked = check; if (check) { row.querySelector('[data-day-time="start"]').value = '09:00'; row.querySelector('[data-day-time="end"]').value = '17:00'; } }); updateSummary(); });
          document.getElementById('copy-first-day').addEventListener('click', function () {
            var first = dayRows.find(function (row) { return row.querySelector('[data-day-toggle]').checked; });
            if (!first) return;
            dayRows.forEach(function (row) { if (row === first) return; row.querySelector('[data-day-toggle]').checked = true; row.querySelector('[data-day-time="start"]').value = first.querySelector('[data-day-time="start"]').value; row.querySelector('[data-day-time="end"]').value = first.querySelector('[data-day-time="end"]').value; });
            updateSummary();
          });
          document.getElementById('clear-days').addEventListener('click', function () { dayRows.forEach(function (row) { row.querySelector('[data-day-toggle]').checked = false; }); updateSummary(); });
          document.getElementById('groomer-schedule-form').addEventListener('submit', function (event) {
            var selected = dayRows.filter(function (row) { return row.querySelector('[data-day-toggle]').checked; });
            var message = '';
            if (!selected.length) message = 'Choose at least one working day.';
            selected.forEach(function (row) {
              if (message) return;
              var start = minutes(row.querySelector('[data-day-time="start"]').value), end = minutes(row.querySelector('[data-day-time="end"]').value);
              if (start === null || end === null || end <= start) { message = dayNames[Number(row.dataset.day) - 1] + ': enter valid working hours.'; return; }
              breaksFor(row).forEach(function (breakRow) {
                var from = minutes(breakRow.querySelector('[data-break-time="start"]').value), to = minutes(breakRow.querySelector('[data-break-time="end"]').value);
                if ((from === null) !== (to === null) || (from !== null && (to <= from || from < start || to > end))) message = dayNames[Number(row.dataset.day) - 1] + ': check that breaks fall within working hours.';
              });
            });
            if (message) { event.preventDefault(); var error = document.getElementById('groomer-schedule-error'); error.textContent = message; error.hidden = false; error.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
          });
          updateSummary();
          var leaveStart = document.querySelector('[name="start_date"]'), leaveEnd = document.querySelector('[name="end_date"]'), leaveLength = document.getElementById('leave-length');
          function updateLeaveLength() {
            if (!leaveStart.value || !leaveEnd.value || leaveEnd.value < leaveStart.value) { leaveLength.textContent = 'Choose your first and last day away.'; return; }
            var count = Math.round((new Date(leaveEnd.value + 'T12:00:00') - new Date(leaveStart.value + 'T12:00:00')) / 86400000) + 1;
            leaveLength.textContent = count + ' day' + (count === 1 ? '' : 's') + ' requested.';
          }
          leaveStart.addEventListener('change', updateLeaveLength); leaveEnd.addEventListener('change', updateLeaveLength);
          document.getElementById('groomer-leave-form').addEventListener('submit', function (event) {
            if (leaveEnd.value < leaveStart.value) { event.preventDefault(); leaveEnd.setCustomValidity('End date must be on or after the start date.'); leaveEnd.reportValidity(); }
            else leaveEnd.setCustomValidity('');
          });
        })();
      </script>
    <?php endif; ?>
    <style>
      .sched-bar{margin:14px 0;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px}
      .sched-summary{color:#123d5c;font-size:.88rem;font-weight:700}
      .sched-bar-actions,.groomer-break-actions{display:flex;flex-wrap:wrap;gap:6px}
      .groomer-request-drop[hidden]{display:none}
      .groomer-day-rows{display:grid;gap:8px;margin:12px 0}
      .groomer-break-actions{grid-column:1/-1;justify-content:flex-end}
      .groomer-action .sched-action-status{min-height:1.2rem;color:#9a6a0f;font-size:.78rem;font-weight:700}
      @media(max-width:650px){.groomer-day-card{grid-template-columns:1fr 1fr}.groomer-break-row{flex-wrap:wrap}.groomer-break-row .field{min-width:120px}}
    </style>
    <?php
};

if ($embedded) {
  $renderSchedule();
  return;
}

role_render('staff', $scheduleActiveMenu, 'My Grooming Schedule', 'Request working hours and leave for Admin review', $renderSchedule);
