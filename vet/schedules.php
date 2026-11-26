<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['vet']);

function vet_schedule_time(string $value): ?string
{
    return preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $value) ? $value . ':00' : null;
}

function vet_schedule_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
}

$pdo = db();
$profileStatement = $pdo->prepare(
    "SELECT v.vet_id, v.full_name
     FROM Users u
     JOIN Veterinarians v ON v.vet_id = u.vet_id
     WHERE u.user_id = ? AND u.`role` = 'vet'"
);
$profileStatement->execute([(int) $_SESSION['user_id']]);
$vet = $profileStatement->fetch();
if (!$vet) {
    http_response_code(403);
    exit('The veterinarian profile is unavailable.');
}
$vetId = (int) $vet['vet_id'];
$error = '';
$action = '';
$openPanel = '';
$success = (string) ($_SESSION['vet_schedule_flash'] ?? '');
unset($_SESSION['vet_schedule_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = posted_string('action');
    $openPanel = $action === 'request_leave' ? 'leave' : ($action === 'submit_schedule' ? 'hours' : '');
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        try {
            $pdo->beginTransaction();
            $vetLock = $pdo->prepare('SELECT vet_id FROM Veterinarians WHERE vet_id = ? FOR UPDATE');
            $vetLock->execute([$vetId]);
            if (!$vetLock->fetchColumn()) {
                throw new InvalidArgumentException('Your veterinarian profile is unavailable.');
            }

            if ($action === 'submit_schedule') {
                $existingRequest = $pdo->prepare(
                    "SELECT request_id FROM VetScheduleRequests
                     WHERE vet_id = ? AND status = 'Pending'
                     LIMIT 1 FOR UPDATE"
                );
                $existingRequest->execute([$vetId]);
                if ($existingRequest->fetchColumn()) {
                    throw new InvalidArgumentException('You already have a schedule request awaiting Admin review.');
                }

                $rawDays = $_POST['work_days'] ?? [];
                if (!is_array($rawDays)) {
                    throw new InvalidArgumentException('Choose at least one working day.');
                }
                $startTimes = is_array($_POST['start_times'] ?? null) ? $_POST['start_times'] : [];
                $endTimes = is_array($_POST['end_times'] ?? null) ? $_POST['end_times'] : [];
                $rawBreaks = is_array($_POST['breaks'] ?? null) ? $_POST['breaks'] : [];
                $requestedDays = [];
                foreach ($rawDays as $rawDay) {
                    $day = filter_var($rawDay, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
                    if ($day === false) {
                        throw new InvalidArgumentException('Choose valid working days.');
                    }
                    $day = (int) $day;
                    if (isset($requestedDays[$day])) {
                        continue;
                    }
                    $start = vet_schedule_time(is_string($startTimes[$day] ?? null) ? $startTimes[$day] : '');
                    $end = vet_schedule_time(is_string($endTimes[$day] ?? null) ? $endTimes[$day] : '');
                    if ($start === null || $end === null || $start >= $end) {
                        throw new InvalidArgumentException('Enter valid working hours for each selected day.');
                    }

                    $breaks = [];
                    $dayBreaks = $rawBreaks[$day] ?? [];
                    if (!is_array($dayBreaks)) {
                        throw new InvalidArgumentException('Enter valid break times.');
                    }
                    foreach ($dayBreaks as $break) {
                        if (!is_array($break)) {
                            throw new InvalidArgumentException('Enter valid break times.');
                        }
                        $breakStartValue = is_string($break['start_time'] ?? null) ? $break['start_time'] : '';
                        $breakEndValue = is_string($break['end_time'] ?? null) ? $break['end_time'] : '';
                        if ($breakStartValue === '' && $breakEndValue === '') {
                            continue;
                        }
                        $breakStart = vet_schedule_time($breakStartValue);
                        $breakEnd = vet_schedule_time($breakEndValue);
                        if ($breakStart === null || $breakEnd === null || $breakStart >= $breakEnd
                            || $breakStart < $start || $breakEnd > $end) {
                            throw new InvalidArgumentException('Breaks must be valid and fall within working hours.');
                        }
                        $breaks[] = [$breakStart, $breakEnd];
                    }
                    usort($breaks, static function (array $first, array $second): int {
                        return strcmp($first[0], $second[0]);
                    });
                    for ($index = 1; $index < count($breaks); $index++) {
                        if ($breaks[$index][0] < $breaks[$index - 1][1]) {
                            throw new InvalidArgumentException('Break times cannot overlap.');
                        }
                    }
                    $requestedDays[$day] = ['start' => $start, 'end' => $end, 'breaks' => $breaks];
                }
                if (!$requestedDays) {
                    throw new InvalidArgumentException('Choose at least one working day.');
                }

                $pdo->prepare(
                    "INSERT INTO VetScheduleRequests (vet_id, submitted_by_user_id, status)
                     VALUES (?, ?, 'Pending')"
                )->execute([$vetId, (int) $_SESSION['user_id']]);
                $requestId = (int) $pdo->lastInsertId();
                $insertDay = $pdo->prepare(
                    'INSERT INTO VetScheduleRequestDays (request_id, day_of_week, start_time, end_time)
                     VALUES (?, ?, ?, ?)'
                );
                $insertBreak = $pdo->prepare(
                    'INSERT INTO VetScheduleRequestBreaks (request_id, day_of_week, start_time, end_time)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($requestedDays as $day => $schedule) {
                    $insertDay->execute([$requestId, $day, $schedule['start'], $schedule['end']]);
                    foreach ($schedule['breaks'] as [$breakStart, $breakEnd]) {
                        $insertBreak->execute([$requestId, $day, $breakStart, $breakEnd]);
                    }
                }
                $adminNotified = role_notify_role(
                    $pdo,
                    'admin',
                    'schedule',
                    'Veterinarian schedule request',
                    $vet['full_name'] . ' submitted a working schedule for review.',
                    'admin/schedule_requests.php',
                    'vet_schedule_request_' . $requestId
                );
                if ($adminNotified === 0) {
                    error_log('Vet schedule request saved without an active Admin notification recipient.');
                }
                role_log(
                    $pdo,
                    (int) $_SESSION['user_id'],
                    'vet.schedule.requested',
                    'vet_schedule_request',
                    $requestId,
                    'Submitted a working schedule for Admin review'
                );
                $success = 'Schedule request submitted. It will not affect availability until the Admin approves it.';
            } elseif ($action === 'request_leave') {
                $startDateValue = posted_string('start_date');
                $endDateValue = posted_string('end_date');
                $startDate = vet_schedule_date($startDateValue);
                $endDate = vet_schedule_date($endDateValue);
                $reason = mb_substr(trim(posted_string('reason')), 0, 150);
                if (!$startDate || !$endDate || $startDate < new DateTimeImmutable('today') || $endDate < $startDate) {
                    throw new InvalidArgumentException('Enter a valid leave period beginning today or later.');
                }

                $overlap = $pdo->prepare(
                    "SELECT leave_id FROM VetLeaves
                     WHERE vet_id = ? AND status IN ('Pending','Approved')
                       AND start_date <= ? AND end_date >= ?
                     LIMIT 1 FOR UPDATE"
                );
                $overlap->execute([$vetId, $endDateValue, $startDateValue]);
                if ($overlap->fetchColumn()) {
                    throw new InvalidArgumentException('That period overlaps an existing pending or approved leave.');
                }
                $pdo->prepare(
                    "INSERT INTO VetLeaves (vet_id, start_date, end_date, reason, status)
                     VALUES (?, ?, ?, ?, 'Pending')"
                )->execute([$vetId, $startDateValue, $endDateValue, $reason !== '' ? $reason : null]);
                $leaveId = (int) $pdo->lastInsertId();
                $adminNotified = role_notify_role(
                    $pdo,
                    'admin',
                    'schedule',
                    'Veterinarian leave request',
                    $vet['full_name'] . ' requested leave from ' . $startDate->format('M j, Y') . ' to ' . $endDate->format('M j, Y') . '.',
                    'admin/schedule_requests.php',
                    'vet_leave_request_' . $leaveId
                );
                if ($adminNotified === 0) {
                    error_log('Vet leave request saved without an active Admin notification recipient.');
                }
                role_log(
                    $pdo,
                    (int) $_SESSION['user_id'],
                    'vet.leave.requested',
                    'vet_leave',
                    $leaveId,
                    'Requested leave ' . $startDateValue . ' through ' . $endDateValue
                );
                $success = 'Leave request submitted for Admin review.';
            } else {
                throw new InvalidArgumentException('Unsupported schedule request.');
            }

            $pdo->commit();
            $_SESSION['vet_schedule_flash'] = $success;
            header('Location: schedules.php');
            exit;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Vet schedule request: ' . $ex->getMessage());
            $error = $ex instanceof InvalidArgumentException ? $ex->getMessage() : 'The request could not be saved.';
        }
    }
}

role_render('vet', ['schedules'], 'My Schedule', 'View published hours and request schedule changes', function (array $context) use ($pdo, $vetId, $error, $success, $openPanel): void {
    $scheduleStatement = $pdo->prepare(
        'SELECT schedule_id, day_of_week, start_time, end_time
         FROM VetSchedules WHERE vet_id = ? ORDER BY day_of_week'
    );
    $scheduleStatement->execute([$vetId]);
    $publishedDays = [];
    foreach ($scheduleStatement->fetchAll() as $row) {
        $publishedDays[(int) $row['day_of_week']] = $row;
    }
    $breakStatement = $pdo->prepare(
        'SELECT day_of_week, start_time, end_time
         FROM VetScheduleBreaks WHERE vet_id = ? ORDER BY day_of_week, start_time'
    );
    $breakStatement->execute([$vetId]);
    $publishedBreaks = [];
    foreach ($breakStatement->fetchAll() as $row) {
        $publishedBreaks[(int) $row['day_of_week']][] = $row;
    }
    $requestStatement = $pdo->prepare(
        'SELECT request_id, status, submitted_at, review_note
         FROM VetScheduleRequests WHERE vet_id = ?
         ORDER BY submitted_at DESC LIMIT 10'
    );
    $requestStatement->execute([$vetId]);
    $scheduleRequests = $requestStatement->fetchAll();
    $leaveStatement = $pdo->prepare(
        'SELECT leave_id, start_date, end_date, reason, status
         FROM VetLeaves
         WHERE vet_id = ? AND (status = \'Pending\' OR end_date >= CURRENT_DATE)
         ORDER BY start_date LIMIT 20'
    );
    $leaveStatement->execute([$vetId]);
    $leaveRequests = $leaveStatement->fetchAll();

    $calendarLeaveStatement = $pdo->prepare(
        "SELECT start_date, end_date, reason, status
         FROM VetLeaves
         WHERE vet_id = ? AND status IN ('Pending','Approved')
           AND end_date >= DATE_SUB(CURRENT_DATE, INTERVAL 120 DAY)
         ORDER BY start_date"
    );
    $calendarLeaveStatement->execute([$vetId]);
    $calendarLeaves = [];
    foreach ($calendarLeaveStatement->fetchAll() as $row) {
        $calendarLeaves[] = [
            'start' => (string) $row['start_date'],
            'end' => (string) $row['end_date'],
            'status' => (string) $row['status'],
            'reason' => (string) ($row['reason'] ?? ''),
        ];
    }

    $calendarSchedule = [];
    foreach ($publishedDays as $day => $schedule) {
        $dayBreaks = [];
        foreach ($publishedBreaks[$day] ?? [] as $break) {
            $dayBreaks[] = [
                substr((string) $break['start_time'], 0, 5),
                substr((string) $break['end_time'], 0, 5),
            ];
        }
        $calendarSchedule[$day] = [
            'start' => substr((string) $schedule['start_time'], 0, 5),
            'end' => substr((string) $schedule['end_time'], 0, 5),
            'breaks' => $dayBreaks,
        ];
    }
    $calendarData = [
        'schedule' => $calendarSchedule,
        'leaves' => $calendarLeaves,
        'today' => date('Y-m-d'),
    ];
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $compactTime = static function (string $time): string {
        $stamp = strtotime($time);
        $minute = (int) date('i', $stamp);
        return date('g', $stamp) . ($minute ? ':' . date('i', $stamp) : '') . substr(date('a', $stamp), 0, 1);
    };

    $hasPendingSchedule = false;
    $pendingScheduleDate = '';
    foreach ($scheduleRequests as $request) {
        if ($request['status'] === 'Pending') {
            $hasPendingSchedule = true;
            $pendingScheduleDate = date('M j, Y', strtotime($request['submitted_at']));
            break;
        }
    }
    $pendingLeaves = 0;
    foreach ($leaveRequests as $leave) {
        if ($leave['status'] === 'Pending') {
            $pendingLeaves++;
        }
    }

    if ($error !== '') {
        echo '<div class="notice error" role="alert">' . e($error) . '</div>';
    }
    if ($success !== '') {
        echo '<div class="notice success" role="status">' . e($success) . '</div>';
    }
    ?>
    <style>
      .cal-toolbar{margin-bottom:14px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
      .cal-nav{display:flex;align-items:center;gap:.4rem}
      .cal-nav-btn{width:2.2rem;height:2.2rem;display:inline-grid;place-items:center;padding:0;color:#123d5c;background:#f1f8fd;border:1px solid #b9d3e6;border-radius:10px;font:inherit;font-size:1.1rem;font-weight:800;cursor:pointer}
      .cal-nav-btn:hover{background:#e0f2fe;border-color:#0284c7}
      .cal-today-btn{height:2.2rem;padding:0 .9rem;color:#075985;background:#fff;border:1px solid #b9d3e6;border-radius:10px;font:inherit;font-size:.85rem;font-weight:700;cursor:pointer}
      .cal-today-btn:hover{background:#e0f2fe;border-color:#0284c7}
      .cal-nav-btn:focus-visible,.cal-today-btn:focus-visible,.cal-view-btn:focus-visible{outline:3px solid rgba(2,132,199,.4);outline-offset:2px}
      .cal-title{min-width:9rem;margin:0 .4rem;color:#123d5c;font-size:1.2rem;font-weight:800}
      .cal-views{display:flex;gap:.25rem;padding:3px;background:#eef4f9;border-radius:12px}
      .cal-view-btn{padding:.4rem 1rem;color:#56738a;background:transparent;border:0;border-radius:9px;font:inherit;font-size:.85rem;font-weight:800;cursor:pointer}
      .cal-view-btn[aria-pressed="true"]{color:#fff;background:#0284c7;box-shadow:0 3px 8px rgba(3,105,161,.25)}
      .cal-empty-note{margin-bottom:14px}
      .cal-legend{margin-top:14px;display:flex;flex-wrap:wrap;gap:1rem;color:#56738a;font-size:.76rem;font-weight:600}
      .cal-legend i{display:inline-block;width:.8rem;height:.8rem;margin-right:.35rem;vertical-align:-1px;border-radius:3px}
      .cal-legend .work{background:#0284c7}
      .cal-legend .rest{background:repeating-linear-gradient(45deg,#fff 0 3px,#fbbf24 3px 6px);border:1px solid #d99a0b}
      .cal-legend .approved{background:#2f8f5b}
      .cal-legend .pending{background:#e0a21b}
      .cal-frame{overflow:hidden;background:#fff;border:1px solid #d5e5f1;border-radius:14px}
      .cal-dow{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));background:#f7fafc;border-bottom:1px solid #d5e5f1}
      .cal-dow span{padding:.55rem .4rem;color:#56738a;font-size:.78rem;font-weight:800;text-align:center}
      .cal-cells{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
      .cal-cell{min-width:0;min-height:6.8rem;padding:.4rem .4rem .5rem;display:flex;flex-direction:column;gap:.25rem;border-right:1px solid #e3edf5;border-bottom:1px solid #e3edf5}
      .cal-cell:nth-child(7n){border-right:0}
      .cal-cell.is-out{background:#fafcfd}
      .cal-cell.is-out .cal-num{color:#a9bccb}
      .cal-cell.is-out .cal-ev,.cal-cell.is-out .cal-break{opacity:.45}
      .cal-num{width:1.7rem;height:1.7rem;display:inline-grid;place-items:center;color:#123d5c;font-size:.82rem;font-weight:700;border-radius:50%}
      .cal-cell.is-today .cal-num{color:#fff;background:#0284c7}
      .cal-ev{min-width:0;padding:.2rem .45rem;overflow:hidden;color:#fff;background:#0284c7;border-radius:6px;font-size:.74rem;font-weight:700;white-space:nowrap;text-overflow:ellipsis}
      .cal-ev.leave-approved{background:#2f8f5b}
      .cal-ev.leave-pending{background:#e0a21b}
      .cal-break{padding:.12rem .45rem;color:#7a5a00;background:repeating-linear-gradient(45deg,#fffbeb 0 4px,#fdf0c4 4px 8px);border:1px solid #f1d98a;border-radius:5px;font-size:.66rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .cal-week{overflow-x:auto}
      .cal-week-inner{min-width:700px}
      .cal-wk-row{display:grid;grid-template-columns:3.6rem repeat(7,minmax(0,1fr))}
      .cal-wk-head{background:#f7fafc;border-bottom:1px solid #d5e5f1}
      .cal-wk-head>div{padding:.5rem .3rem;color:#56738a;font-size:.78rem;font-weight:800;text-align:center}
      .cal-wk-head .cal-num{margin-left:.25rem}
      .cal-wk-head .is-today{color:#075985}
      .cal-wk-head .is-today .cal-num{color:#fff;background:#0284c7}
      .cal-wk-allday{min-height:2rem;border-bottom:1px solid #d5e5f1}
      .cal-wk-allday>div{padding:.25rem;border-left:1px solid #e3edf5}
      .cal-wk-allday>div:first-child{border-left:0;color:#56738a;font-size:.66rem;font-weight:700}
      .cal-wk-times{position:relative}
      .cal-wk-times span{position:absolute;right:.5rem;transform:translateY(-50%);color:#56738a;font-size:.68rem;font-weight:700;white-space:nowrap}
      .cal-wk-col{position:relative;border-left:1px solid #e3edf5;background-image:repeating-linear-gradient(to bottom,transparent 0,transparent 43px,#e3edf5 43px,#e3edf5 44px)}
      .cal-wk-col.is-today{background-color:#f4faff}
      .cal-wk-col.is-leave{background-color:#f3faf6}
      .cal-wk-block{position:absolute;left:4px;right:4px;padding:.3rem .45rem;overflow:hidden;color:#fff;background:linear-gradient(180deg,#38bdf8,#0284c7);border-radius:8px;font-size:.72rem;font-weight:700;line-height:1.3}
      .cal-wk-block.rest{left:8px;right:8px;color:#7a5a00;background:repeating-linear-gradient(45deg,#fffbeb 0 5px,#fbd776 5px 10px);border:1px solid #d99a0b;z-index:2}

      .sched-actions-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;margin-bottom:1rem}
      .sched-action-card{min-width:0;padding:1.2rem 1.3rem;display:flex;flex-direction:column;gap:.5rem;background:#fff;border:1px solid #d5e5f1;border-radius:16px;box-shadow:0 4px 14px rgba(0,46,74,.06)}
      .sched-action-card h2{margin:0;color:#123d5c;font-size:1.1rem}
      .sched-action-card p{margin:0;color:#56738a;font-size:.88rem;line-height:1.45}
      .sched-action-status{min-height:1.4rem;color:#9a6a0f;font-size:.8rem;font-weight:800}
      .sched-action-card .button{margin-top:auto;align-self:flex-start}
      .sched-action-card .button[aria-expanded="true"]{color:#075985;background:#fff;border:1px solid #0284c7}
      .sched-drop{margin-bottom:1rem}
      .sched-drop[hidden]{display:none}
      .sched-builder{display:grid;gap:1.1rem}
      .sched-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
      .sched-summary{color:#123d5c;font-size:.92rem;font-weight:700}
      .sched-bar-actions{display:flex;flex-wrap:wrap;gap:.5rem}
      .sched-form-error{margin-bottom:1rem}
      .sched-chips{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.6rem}
      .sched-chip{position:relative;min-width:0;padding:.8rem .25rem;display:flex;flex-direction:column;align-items:center;gap:.2rem;color:#56738a;background:#f7fafc;border:1.5px dashed #b9d3e6;border-radius:16px;cursor:pointer;user-select:none;transition:transform .15s ease,background .15s ease,border-color .15s ease}
      .sched-chip:hover{border-color:#0284c7;transform:translateY(-2px)}
      .sched-chip input{position:absolute;inset:0;margin:0;opacity:0;cursor:pointer}
      .sched-chip:focus-within{outline:3px solid rgba(2,132,199,.4);outline-offset:2px}
      .sched-chip-name{font-size:1rem;font-weight:800}
      .sched-chip-time{max-width:100%;font-size:.72rem;font-weight:700;text-align:center;white-space:nowrap}
      .sched-chip.is-on{color:#fff;background:linear-gradient(160deg,#0ea5e9,#0369a1);border:1.5px solid #0369a1;box-shadow:0 8px 18px rgba(3,105,161,.28)}
      .sched-rows{display:grid;gap:.8rem}
      .sched-rows-empty{padding:1.2rem 1rem;text-align:center;color:#56738a;background:#f7fafc;border:1.5px dashed #b9d3e6;border-radius:16px}
      .sched-row{min-width:0;padding:1rem 1.1rem;display:grid;gap:.8rem;background:#fff;border:1px solid #d5e5f1;border-left:5px solid #0284c7;border-radius:14px}
      .sched-row[hidden]{display:none}
      .sched-row.is-invalid{border-color:#c4453d;box-shadow:0 0 0 3px rgba(196,69,61,.18)}
      .sched-row-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.6rem}
      .sched-row-title{margin:0;color:#123d5c;font-size:1.02rem}
      .sched-row-meta{display:flex;align-items:center;gap:.6rem}
      .sched-row-total{padding:3px 10px;color:#075985;background:#e0f2fe;border-radius:999px;font-size:.76rem;font-weight:800}
      .sched-time-fields{max-width:26rem;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
      .sched-breaks-head{margin-bottom:.5rem;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem}
      .sched-breaks-head h5{margin:0;color:#123d5c;font-size:.88rem}
      .sched-breaks-actions{display:flex;flex-wrap:wrap;gap:.4rem}
      .sched-row .break-list{display:grid;gap:.5rem}
      .sched-row .break-row{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.6rem;padding:.55rem .7rem;background:#fffbeb;border:1px solid #f3dd9a;border-radius:12px}
      .sched-row .break-row .field{flex:1 1 7rem;min-width:0}
      .sched-submit-row{display:flex;flex-wrap:wrap;align-items:center;gap:.9rem}
      .sched-note-text{display:block;max-width:34ch}
      @media(max-width:760px){
        .sched-actions-row{grid-template-columns:1fr}
        .cal-cell{min-height:5.2rem;padding:.25rem}
        .cal-ev{padding:.15rem .25rem;font-size:.62rem}
        .cal-break{display:none}
        .cal-title{min-width:0;font-size:1.05rem}
      }
      @media(max-width:560px){
        .sched-chips{gap:.35rem}
        .sched-chip{padding:.6rem .1rem;border-radius:12px}
        .sched-chip-name{font-size:.85rem}
        .sched-chip-time{font-size:.6rem;white-space:normal;overflow-wrap:anywhere}
        .sched-row{padding:.9rem .8rem}
      }
      @media(prefers-reduced-motion:reduce){.sched-chip{transition:none}.sched-chip:hover{transform:none}}
    </style>

    <section class="panel">
      <div class="panel-header"><h2>My schedule</h2><span class="data-count">Admin-approved</span></div>
      <div class="panel-body">
        <?php if (!$publishedDays): ?>
          <div class="notice cal-empty-note" role="status">No working hours have been published yet. Request your hours below and they will appear here once the Admin approves them.</div>
        <?php endif; ?>
        <div class="cal-toolbar">
          <div class="cal-nav">
            <button class="cal-nav-btn" type="button" id="cal-prev" aria-label="Previous month or week">&#8249;</button>
            <button class="cal-nav-btn" type="button" id="cal-next" aria-label="Next month or week">&#8250;</button>
            <h3 class="cal-title" id="cal-title" aria-live="polite"></h3>
            <button class="cal-today-btn" type="button" id="cal-today">Today</button>
          </div>
          <div class="cal-views" role="group" aria-label="Calendar view">
            <button class="cal-view-btn" type="button" data-view="week" aria-pressed="false">Week</button>
            <button class="cal-view-btn" type="button" data-view="month" aria-pressed="true">Month</button>
          </div>
        </div>
        <div class="cal-frame" id="cal-body" role="region" aria-label="Published schedule calendar"></div>
        <div class="cal-legend">
          <span><i class="work"></i>Working hours</span>
          <span><i class="rest"></i>Break</span>
          <span><i class="approved"></i>Approved leave</span>
          <span><i class="pending"></i>Leave awaiting review</span>
        </div>
      </div>
      <script type="application/json" id="cal-data"><?= json_encode($calendarData, $jsonFlags) ?></script>
    </section>
    <noscript>
      <div class="notice" role="status">Calendar navigation requires JavaScript. Schedule request forms are shown below.</div>
      <style>.sched-drop[hidden]{display:block!important}</style>
    </noscript>

    <div class="sched-actions-row">
      <article class="sched-action-card">
        <h2>Request working hours</h2>
        <p>Suggest a new weekly schedule. Availability only changes after the Admin approves it.</p>
        <span class="sched-action-status"><?= $hasPendingSchedule ? 'Request from ' . e($pendingScheduleDate) . ' awaiting review' : '&nbsp;' ?></span>
        <button class="button" type="button" id="toggle-hours" aria-expanded="false" aria-controls="drop-hours" data-open-label="Request hours" data-close-label="Close">Request hours</button>
      </article>
      <article class="sched-action-card">
        <h2>Request leave</h2>
        <p>Choose the days you will be away. Approved leave is blocked from booking.</p>
        <span class="sched-action-status"><?= $pendingLeaves > 0 ? e((string) $pendingLeaves) . ' leave request' . ($pendingLeaves === 1 ? '' : 's') . ' awaiting review' : '&nbsp;' ?></span>
        <button class="button" type="button" id="toggle-leave" aria-expanded="false" aria-controls="drop-leave" data-open-label="Request leave" data-close-label="Close">Request leave</button>
      </article>
    </div>

    <section class="panel sched-drop" id="drop-hours" hidden>
      <div class="panel-header"><h2>Request working hours</h2><span class="data-count">Requires Admin approval</span></div>
      <form class="panel-body" method="post" id="schedule-request-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="submit_schedule">
        <?php if ($hasPendingSchedule): ?>
          <div class="notice" role="status">Your schedule request from <?= e($pendingScheduleDate) ?> is waiting for Admin review. You can submit a new one after it is reviewed.</div>
        <?php endif; ?>
        <p class="hint">Only approved requests update bookable availability. Existing appointments are not moved automatically.</p>
        <div class="notice error sched-form-error" id="schedule-form-error" role="alert" hidden></div>
        <div class="sched-builder">
          <div class="sched-bar">
            <span class="sched-summary" id="schedule-summary" aria-live="polite"></span>
            <div class="sched-bar-actions">
              <button class="button secondary small" type="button" id="preset-weekdays">Mon-Fri</button>
              <button class="button secondary small" type="button" id="preset-sixdays">Mon-Sat</button>
              <button class="button secondary small" type="button" id="copy-first-day">Copy first day to the others</button>
              <button class="button ghost small" type="button" id="clear-days">Clear all</button>
            </div>
          </div>

          <div class="sched-chips" role="group" aria-label="Working days">
            <?php foreach ($days as $day => $label): ?>
              <?php $published = $publishedDays[$day] ?? null; ?>
              <label class="sched-chip<?= $published ? ' is-on' : '' ?>" data-chip="<?= $day ?>">
                <input type="checkbox" name="work_days[]" value="<?= $day ?>" data-day-toggle aria-label="<?= e($label) ?>"<?= $published ? ' checked' : '' ?>>
                <span class="sched-chip-name"><?= e(substr($label, 0, 3)) ?></span>
                <span class="sched-chip-time" data-chip-time><?= $published ? e($compactTime($published['start_time']) . '–' . $compactTime($published['end_time'])) : 'Off' ?></span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="sched-rows">
            <p class="sched-rows-empty" id="sched-rows-empty" aria-live="polite"<?= $publishedDays ? ' hidden' : '' ?>>Choose a day above to start building your week.</p>
            <?php foreach ($days as $day => $label): ?>
              <?php $published = $publishedDays[$day] ?? null; ?>
              <div class="sched-row" role="group" aria-label="<?= e($label) ?> hours" data-schedule-day data-day="<?= $day ?>"<?= $published ? '' : ' hidden' ?>>
                <div class="sched-row-head">
                  <h4 class="sched-row-title"><?= e($label) ?></h4>
                  <div class="sched-row-meta">
                    <span class="sched-row-total" data-day-total></span>
                    <button class="button ghost small" type="button" data-remove-day>Remove day</button>
                  </div>
                </div>
                <div class="sched-time-fields">
                  <label class="field">Start<input type="time" name="start_times[<?= $day ?>]" value="<?= e($published ? substr((string) $published['start_time'], 0, 5) : '09:00') ?>" data-day-time="start"<?= $published ? '' : ' disabled' ?>></label>
                  <label class="field">End<input type="time" name="end_times[<?= $day ?>]" value="<?= e($published ? substr((string) $published['end_time'], 0, 5) : '17:00') ?>" data-day-time="end"<?= $published ? '' : ' disabled' ?>></label>
                </div>
                <div class="sched-breaks">
                  <div class="sched-breaks-head">
                    <h5>Breaks</h5>
                    <div class="sched-breaks-actions">
                      <button class="button secondary small" type="button" data-add-lunch<?= $published ? '' : ' disabled' ?>>Add lunch (1 hr)</button>
                      <button class="button secondary small" type="button" data-add-break<?= $published ? '' : ' disabled' ?>>Add break</button>
                    </div>
                  </div>
                  <div class="break-list" data-break-list data-next-index="<?= count($publishedBreaks[$day] ?? []) ?>">
                    <?php foreach ($publishedBreaks[$day] ?? [] as $breakIndex => $break): ?>
                      <div class="break-row" data-break-row>
                        <label class="field">From<input type="time" name="breaks[<?= $day ?>][<?= $breakIndex ?>][start_time]" value="<?= e(substr((string) $break['start_time'], 0, 5)) ?>" data-break-time="start"<?= $published ? '' : ' disabled' ?>></label>
                        <label class="field">To<input type="time" name="breaks[<?= $day ?>][<?= $breakIndex ?>][end_time]" value="<?= e(substr((string) $break['end_time'], 0, 5)) ?>" data-break-time="end"<?= $published ? '' : ' disabled' ?>></label>
                        <button class="button secondary small" type="button" data-remove-break aria-label="Remove break">Remove</button>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="sched-submit-row">
            <button class="button" type="submit" <?= $hasPendingSchedule ? 'disabled title="A schedule request is already awaiting review."' : '' ?>>Submit schedule request</button>
          </div>
        </div>
      </form>
    </section>

    <section class="panel sched-drop" id="drop-leave" hidden>
      <div class="panel-header"><h2>Request leave</h2><span class="data-count">Requires Admin approval</span></div>
      <form class="panel-body form-grid" method="post" id="leave-request-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="request_leave">
        <label class="field">Start date<input type="date" name="start_date" id="leave-start" min="<?= date('Y-m-d') ?>" required></label>
        <label class="field">End date<input type="date" name="end_date" id="leave-end" min="<?= date('Y-m-d') ?>" required></label>
        <label class="field wide">Reason (optional)<input name="reason" maxlength="150" placeholder="e.g. Family event, conference, medical leave"></label>
        <p class="hint wide" id="leave-length" aria-live="polite">Choose your first and last day away.</p>
        <div class="actions wide"><button class="button" type="submit">Submit leave request</button></div>
      </form>
    </section>

    <section class="panel">
      <div class="panel-header"><h2>Recent requests</h2><span class="data-count"><?= count($scheduleRequests) + count($leaveRequests) ?> records</span></div>
      <div class="panel-body table-search-toolbar">
        <label class="field">Search recent requests
          <input type="search" data-table-search placeholder="Request, date or status" autocomplete="off">
        </label>
        <span class="table-search-count" data-table-search-count aria-live="polite"></span>
      </div>
      <div class="table-wrap data-table-card">
        <table class="records-table">
          <colgroup><col style="width:14%"><col style="width:24%"><col style="width:14%"><col style="width:48%"></colgroup>
          <thead><tr><th>Request</th><th>Submitted / dates</th><th>Status</th><th>Admin note / reason</th></tr></thead>
          <tbody>
            <?php
              $requestRows = [];
              foreach ($scheduleRequests as $request) {
                  $requestRows[] = ['Schedule', date('M j, Y g:i A', strtotime($request['submitted_at'])), $request['status'], $request['review_note']];
              }
              foreach ($leaveRequests as $request) {
                  $sameDay = $request['start_date'] === $request['end_date'];
                  $dateLabel = date('M j, Y', strtotime($request['start_date']));
                  if (!$sameDay) {
                      $dateLabel .= ' – ' . date('M j, Y', strtotime($request['end_date']));
                  }
                  $requestRows[] = ['Leave', $dateLabel, $request['status'], $request['reason']];
              }
            ?>
            <?php if ($requestRows): foreach ($requestRows as $index => $request): ?>
              <tr<?= $index % 2 ? ' class="alt"' : '' ?>>
                <td data-label="Request"><strong><?= e($request[0]) ?></strong></td>
                <td data-label="Submitted / dates"><?= e($request[1]) ?></td>
                <td data-label="Status"><span class="status <?= e(role_status_class($request[2])) ?>"><?= e($request[2]) ?></span></td>
                <td data-label="Admin note / reason"><span class="sched-note-text"><?= e($request[3] ?: '—') ?></span></td>
              </tr>
            <?php endforeach; else: ?>
              <tr class="empty-row"><td colspan="4"><div class="empty-state-box"><strong>No schedule or leave requests yet</strong><small>Requests you submit will appear here with the Admin's decision.</small></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <script>
      (function () {
        var form = document.getElementById('schedule-request-form');
        var errorBox = document.getElementById('schedule-form-error');
        var summary = document.getElementById('schedule-summary');
        var emptyRows = document.getElementById('sched-rows-empty');
        var dayNames = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };

        function toMinutes(value) {
          if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) return null;
          var parts = value.split(':');
          return Number(parts[0]) * 60 + Number(parts[1]);
        }
        function toTime(minutes) {
          var hours = Math.floor(minutes / 60), rest = minutes % 60;
          return ('0' + hours).slice(-2) + ':' + ('0' + rest).slice(-2);
        }
        function formatHours(minutes) {
          var hours = Math.floor(minutes / 60), rest = minutes % 60;
          return rest === 0 ? hours + ' h' : hours + ' h ' + rest + ' min';
        }
        function clock(minutes) {
          var hours = Math.floor(minutes / 60), rest = minutes % 60;
          return (hours % 12 || 12) + (rest ? ':' + ('0' + rest).slice(-2) : '') + (hours >= 12 ? 'p' : 'a');
        }
        function longClock(minutes) {
          var hours = Math.floor(minutes / 60);
          return (hours % 12 || 12) + ':' + ('0' + (minutes % 60)).slice(-2) + ' ' + (hours >= 12 ? 'PM' : 'AM');
        }
        function hourLabel(hour) {
          return (hour % 12 || 12) + ' ' + (hour >= 12 && hour < 24 ? 'PM' : 'AM');
        }
        function escapeHtml(text) {
          return String(text).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
          });
        }

        var panels = {
          hours: { button: document.getElementById('toggle-hours'), drop: document.getElementById('drop-hours') },
          leave: { button: document.getElementById('toggle-leave'), drop: document.getElementById('drop-leave') }
        };
        function setPanel(name, open) {
          var panel = panels[name];
          panel.drop.hidden = !open;
          panel.button.setAttribute('aria-expanded', open ? 'true' : 'false');
          panel.button.textContent = open ? panel.button.dataset.closeLabel : panel.button.dataset.openLabel;
        }
        function togglePanel(name) {
          var willOpen = panels[name].drop.hidden;
          Object.keys(panels).forEach(function (key) { setPanel(key, key === name && willOpen); });
          if (willOpen) panels[name].drop.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        Object.keys(panels).forEach(function (key) {
          panels[key].button.addEventListener('click', function () { togglePanel(key); });
        });
        var initialPanel = <?= json_encode($openPanel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        if (initialPanel && panels[initialPanel]) {
          setPanel(initialPanel, true);
          panels[initialPanel].drop.scrollIntoView({ block: 'nearest' });
        }

        var calData = JSON.parse(document.getElementById('cal-data').textContent);
        var sched = calData.schedule || {};
        var leaves = calData.leaves || [];
        var calBody = document.getElementById('cal-body');
        var calTitle = document.getElementById('cal-title');
        var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        var dayShort = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        var calendarView = 'month';
        var calendarCursor = (function () {
          var parts = calData.today.split('-');
          return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        }());
        var hourHeight = 44;

        function dateKey(date) {
          return date.getFullYear() + '-' + ('0' + (date.getMonth() + 1)).slice(-2) + '-' + ('0' + date.getDate()).slice(-2);
        }
        function dayOfWeek(date) {
          return date.getDay() || 7;
        }
        function leaveFor(date) {
          for (var index = 0; index < leaves.length; index++) {
            if (leaves[index].start <= date && date <= leaves[index].end) return leaves[index];
          }
          return null;
        }
        function leaveLabel(leave) {
          return leave.status === 'Approved' ? 'On leave' : 'Leave (pending)';
        }
        function leaveClass(leave) {
          return leave.status === 'Approved' ? 'leave-approved' : 'leave-pending';
        }
        function renderMonth() {
          var year = calendarCursor.getFullYear(), month = calendarCursor.getMonth();
          var offset = (new Date(year, month, 1).getDay() + 6) % 7;
          var count = new Date(year, month + 1, 0).getDate();
          var weeks = Math.ceil((offset + count) / 7);
          calTitle.textContent = monthNames[month] + ' ' + year;

          var html = '<div class="cal-dow">' + dayShort.map(function (name) { return '<span>' + name + '</span>'; }).join('') + '</div><div class="cal-cells">';
          for (var index = 0; index < weeks * 7; index++) {
            var date = new Date(year, month, 1 - offset + index);
            var key = dateKey(date);
            var classes = 'cal-cell' + (date.getMonth() !== month ? ' is-out' : '') + (key === calData.today ? ' is-today' : '');
            var inner = '<span class="cal-num">' + date.getDate() + '</span>';
            var leave = leaveFor(key);
            var schedule = sched[dayOfWeek(date)];
            if (leave) {
              inner += '<div class="cal-ev ' + leaveClass(leave) + '" title="' + escapeHtml(leave.reason || leaveLabel(leave)) + '">' + escapeHtml(leaveLabel(leave)) + '</div>';
            } else if (schedule) {
              inner += '<div class="cal-ev">' + clock(toMinutes(schedule.start)) + '–' + clock(toMinutes(schedule.end)) + '</div>';
              schedule.breaks.forEach(function (breakTime) {
                inner += '<div class="cal-break">Break ' + clock(toMinutes(breakTime[0])) + '–' + clock(toMinutes(breakTime[1])) + '</div>';
              });
            }
            html += '<div class="' + classes + '">' + inner + '</div>';
          }
          calBody.innerHTML = html + '</div>';
        }
        function renderWeek() {
          var back = (calendarCursor.getDay() + 6) % 7;
          var monday = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), calendarCursor.getDate() - back);
          var sunday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + 6);
          calTitle.textContent = monthNames[monday.getMonth()].slice(0, 3) + ' ' + monday.getDate() +
            ' – ' + (sunday.getMonth() !== monday.getMonth() ? monthNames[sunday.getMonth()].slice(0, 3) + ' ' : '') +
            sunday.getDate() + ', ' + sunday.getFullYear();

          var earliest = 1440, latest = 0;
          Object.keys(sched).forEach(function (key) {
            earliest = Math.min(earliest, toMinutes(sched[key].start));
            latest = Math.max(latest, toMinutes(sched[key].end));
          });
          if (latest === 0) { earliest = 8 * 60; latest = 18 * 60; }
          var startHour = Math.max(0, Math.floor(earliest / 60) - 1);
          var endHour = Math.min(24, Math.ceil(latest / 60) + 1);
          var height = (endHour - startHour) * hourHeight;

          var head = '<div class="cal-wk-row cal-wk-head"><div></div>';
          var allDay = '<div class="cal-wk-row cal-wk-allday"><div>All day</div>';
          var columns = '';
          for (var dayIndex = 0; dayIndex < 7; dayIndex++) {
            var date = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + dayIndex);
            var key = dateKey(date);
            var today = key === calData.today;
            var leave = leaveFor(key);
            var schedule = sched[dayIndex + 1];
            head += '<div class="' + (today ? 'is-today' : '') + '">' + dayShort[dayIndex] + '<span class="cal-num">' + date.getDate() + '</span></div>';
            allDay += '<div>' + (leave ? '<div class="cal-ev ' + leaveClass(leave) + '" title="' + escapeHtml(leave.reason || '') + '">' + escapeHtml(leaveLabel(leave)) + '</div>' : '') + '</div>';

            var blocks = '';
            if (schedule && !leave) {
              var start = toMinutes(schedule.start), end = toMinutes(schedule.end);
              blocks += '<div class="cal-wk-block" style="top:' + ((start - startHour * 60) / 60 * hourHeight) + 'px;height:' + ((end - start) / 60 * hourHeight) + 'px">' +
                longClock(start) + ' – ' + longClock(end) + '</div>';
              schedule.breaks.forEach(function (breakTime) {
                var breakStart = toMinutes(breakTime[0]), breakEnd = toMinutes(breakTime[1]);
                blocks += '<div class="cal-wk-block rest" style="top:' + ((breakStart - startHour * 60) / 60 * hourHeight) + 'px;height:' + ((breakEnd - breakStart) / 60 * hourHeight) + 'px">Break</div>';
              });
            }
            columns += '<div class="cal-wk-col' + (today ? ' is-today' : '') + (leave ? ' is-leave' : '') + '" style="height:' + height + 'px">' + blocks + '</div>';
          }

          var times = '<div class="cal-wk-times" style="height:' + height + 'px">';
          for (var hour = startHour + 1; hour <= endHour; hour++) {
            times += '<span style="top:' + ((hour - startHour) * hourHeight) + 'px">' + hourLabel(hour) + '</span>';
          }
          head += '</div>';
          allDay += '</div>';
          calBody.innerHTML = '<div class="cal-week"><div class="cal-week-inner">' + head + allDay +
            '<div class="cal-wk-row">' + times + columns + '</div></div></div>';
        }
        function renderCalendar() {
          if (calendarView === 'month') renderMonth(); else renderWeek();
          Array.prototype.forEach.call(document.querySelectorAll('.cal-view-btn'), function (button) {
            button.setAttribute('aria-pressed', button.dataset.view === calendarView ? 'true' : 'false');
          });
        }
        function stepCalendar(direction) {
          if (calendarView === 'month') {
            calendarCursor = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth() + direction, 1);
          } else {
            calendarCursor = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), calendarCursor.getDate() + direction * 7);
          }
          renderCalendar();
        }
        document.getElementById('cal-prev').addEventListener('click', function () { stepCalendar(-1); });
        document.getElementById('cal-next').addEventListener('click', function () { stepCalendar(1); });
        document.getElementById('cal-today').addEventListener('click', function () {
          var parts = calData.today.split('-');
          calendarCursor = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
          renderCalendar();
        });
        Array.prototype.forEach.call(document.querySelectorAll('.cal-view-btn'), function (button) {
          button.addEventListener('click', function () {
            calendarView = button.dataset.view;
            renderCalendar();
          });
        });
        renderCalendar();

        function showError(message, target) {
          errorBox.textContent = message;
          errorBox.hidden = false;
          (target || errorBox).scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        var days = Array.prototype.slice.call(document.querySelectorAll('[data-schedule-day]')).map(function (card) {
          var number = Number(card.dataset.day);
          var chip = document.querySelector('[data-chip="' + number + '"]');
          var toggle = chip.querySelector('[data-day-toggle]');
          var chipTime = chip.querySelector('[data-chip-time]');
          var startField = card.querySelector('[data-day-time="start"]');
          var endField = card.querySelector('[data-day-time="end"]');
          var breakList = card.querySelector('[data-break-list]');
          var addButton = card.querySelector('[data-add-break]');
          var lunchButton = card.querySelector('[data-add-lunch]');
          var totalBadge = card.querySelector('[data-day-total]');

          function breakRows() {
            return Array.prototype.slice.call(breakList.querySelectorAll('[data-break-row]'));
          }
          function readBreaks() {
            return breakRows().map(function (row) {
              return {
                start: row.querySelector('[data-break-time="start"]').value,
                end: row.querySelector('[data-break-time="end"]').value
              };
            });
          }
          function validRange() {
            var start = toMinutes(startField.value), end = toMinutes(endField.value);
            return (start === null || end === null || end <= start) ? null : { start: start, end: end };
          }
          function minutes() {
            var range = validRange();
            if (!range) return null;
            var total = range.end - range.start;
            readBreaks().forEach(function (item) {
              var from = toMinutes(item.start), to = toMinutes(item.end);
              if (from !== null && to !== null && to > from) total -= to - from;
            });
            return Math.max(0, total);
          }
          function render() {
            var range = validRange();
            var total = minutes();
            totalBadge.textContent = total === null ? 'Check times' : formatHours(total) + ' working';
            chipTime.textContent = !toggle.checked ? 'Off' : (range ? clock(range.start) + '–' + clock(range.end) : '—');
          }
          function changed() {
            render();
            updateSummary();
            card.classList.remove('is-invalid');
          }
          function setEnabled(enabled) {
            card.hidden = !enabled;
            chip.classList.toggle('is-on', enabled);
            startField.disabled = !enabled;
            endField.disabled = !enabled;
            breakList.querySelectorAll('input').forEach(function (field) { field.disabled = !enabled; });
            addButton.disabled = !enabled;
            lunchButton.disabled = !enabled;
            if (!enabled) card.classList.remove('is-invalid');
            render();
          }
          function bindRow(row) {
            row.querySelector('[data-remove-break]').addEventListener('click', function () {
              row.remove();
              changed();
            });
            row.querySelectorAll('input').forEach(function (input) {
              input.addEventListener('input', changed);
            });
          }
          function addBreak(start, end) {
            var index = Number(breakList.dataset.nextIndex);
            breakList.dataset.nextIndex = String(index + 1);
            var row = document.createElement('div');
            row.className = 'break-row';
            row.dataset.breakRow = '';
            row.innerHTML = '<label class="field">From<input type="time" name="breaks[' + number + '][' + index + '][start_time]" data-break-time="start"></label>' +
              '<label class="field">To<input type="time" name="breaks[' + number + '][' + index + '][end_time]" data-break-time="end"></label>' +
              '<button class="button secondary small" type="button" data-remove-break aria-label="Remove break">Remove</button>';
            row.querySelector('[data-break-time="start"]').value = start || '';
            row.querySelector('[data-break-time="end"]').value = end || '';
            breakList.appendChild(row);
            bindRow(row);
            return row;
          }
          function clearBreaks() {
            breakRows().forEach(function (row) { row.remove(); });
          }

          toggle.addEventListener('change', function () {
            setEnabled(toggle.checked);
            updateSummary();
          });
          startField.addEventListener('input', changed);
          endField.addEventListener('input', changed);
          breakRows().forEach(bindRow);

          card.querySelector('[data-remove-day]').addEventListener('click', function () {
            toggle.checked = false;
            setEnabled(false);
            updateSummary();
          });
          lunchButton.addEventListener('click', function () {
            var range = validRange();
            if (!range) {
              showError(dayNames[number] + ': set valid working hours before adding lunch.', card);
              return;
            }
            if (range.end - range.start < 60) {
              showError(dayNames[number] + ': working hours must span at least one hour to add lunch.', card);
              return;
            }
            var from = 720;
            if (from < range.start || from + 60 > range.end) {
              from = Math.max(range.start, Math.round(((range.start + range.end) / 2 - 30) / 15) * 15);
              from = Math.min(from, range.end - 60);
              from = Math.max(from, range.start);
            }
            addBreak(toTime(from), toTime(from + 60));
            changed();
          });
          addButton.addEventListener('click', function () {
            var row = addBreak('', '');
            row.querySelector('input').focus();
            changed();
          });

          render();
          return {
            number: number,
            label: dayNames[number],
            card: card,
            startField: startField,
            endField: endField,
            included: function () { return toggle.checked; },
            setIncluded: function (included) {
              toggle.checked = included;
              setEnabled(included);
            },
            minutes: minutes,
            readBreaks: readBreaks,
            addBreak: addBreak,
            clearBreaks: clearBreaks,
            refresh: render
          };
        });

        function updateSummary() {
          var included = days.filter(function (day) { return day.included(); });
          emptyRows.hidden = included.length > 0;
          if (!included.length) {
            summary.textContent = 'No working days selected.';
            return;
          }
          var total = 0;
          included.forEach(function (day) { total += day.minutes() || 0; });
          summary.textContent = included.length + ' working day' + (included.length === 1 ? '' : 's') +
            ' · ' + formatHours(total) + ' per week, excluding breaks';
        }

        function setDays(test) {
          days.forEach(function (day) { day.setIncluded(test(day.number)); });
          days.forEach(function (day) { day.card.classList.remove('is-invalid'); });
          errorBox.hidden = true;
          updateSummary();
        }
        document.getElementById('preset-weekdays').addEventListener('click', function () {
          setDays(function (number) { return number <= 5; });
        });
        document.getElementById('preset-sixdays').addEventListener('click', function () {
          setDays(function (number) { return number <= 6; });
        });
        document.getElementById('clear-days').addEventListener('click', function () {
          setDays(function () { return false; });
        });
        document.getElementById('copy-first-day').addEventListener('click', function () {
          var source = days.filter(function (day) { return day.included(); })[0];
          if (!source) {
            showError('Include at least one day to copy its hours.');
            return;
          }
          errorBox.hidden = true;
          var start = source.startField.value, end = source.endField.value, breaks = source.readBreaks();
          days.forEach(function (day) {
            if (!day.included() || day === source) return;
            day.startField.value = start;
            day.endField.value = end;
            day.clearBreaks();
            breaks.forEach(function (item) { day.addBreak(item.start, item.end); });
            day.refresh();
          });
          updateSummary();
        });

        form.addEventListener('submit', function (event) {
          errorBox.hidden = true;
          days.forEach(function (day) { day.card.classList.remove('is-invalid'); });
          var included = days.filter(function (day) { return day.included(); });
          var message = '', badCard = null;
          if (!included.length) message = 'Choose at least one working day.';
          included.forEach(function (day) {
            if (message) return;
            var start = toMinutes(day.startField.value), end = toMinutes(day.endField.value);
            if (start === null || end === null || end <= start) {
              message = day.label + ': the end time must be after the start time.';
              badCard = day.card;
              return;
            }
            var breaks = [];
            day.readBreaks().forEach(function (item) {
              if (message || (item.start === '' && item.end === '')) return;
              var from = toMinutes(item.start), to = toMinutes(item.end);
              if (from === null || to === null || to <= from) {
                message = day.label + ': complete each break or remove it.';
                badCard = day.card;
                return;
              }
              if (from < start || to > end) {
                message = day.label + ': breaks must fall within working hours.';
                badCard = day.card;
                return;
              }
              breaks.push([from, to]);
            });
            if (message) return;
            breaks.sort(function (first, second) { return first[0] - second[0]; });
            for (var index = 1; index < breaks.length; index++) {
              if (breaks[index][0] < breaks[index - 1][1]) {
                message = day.label + ': break times cannot overlap.';
                badCard = day.card;
                return;
              }
            }
          });
          if (message) {
            event.preventDefault();
            if (badCard) badCard.classList.add('is-invalid');
            showError(message, badCard);
          }
        });

        updateSummary();

        var leaveStart = document.getElementById('leave-start');
        var leaveEnd = document.getElementById('leave-end');
        var leaveLength = document.getElementById('leave-length');
        function updateLeave() {
          if (leaveStart.value) {
            leaveEnd.min = leaveStart.value;
            if (leaveEnd.value && leaveEnd.value < leaveStart.value) leaveEnd.value = leaveStart.value;
          }
          if (leaveStart.value && leaveEnd.value) {
            var difference = Math.round((new Date(leaveEnd.value + 'T00:00:00') -
              new Date(leaveStart.value + 'T00:00:00')) / 86400000) + 1;
            leaveLength.textContent = difference + ' day' + (difference === 1 ? '' : 's') +
              ' away. Appointments on these dates may need to be rescheduled by the clinic.';
          } else {
            leaveLength.textContent = 'Choose your first and last day away.';
          }
        }
        leaveStart.addEventListener('input', updateLeave);
        leaveEnd.addEventListener('input', updateLeave);
      }());
    </script>
    <?php
});
