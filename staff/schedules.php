<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['staff']);

$pdo = db();
$scheduleAccess = $pdo->prepare(
    'SELECT s.can_manage_vet_schedules
     FROM Users u
     JOIN Staff s ON s.staff_id = u.staff_id
     WHERE u.user_id = ? AND u.`role` = \'staff\''
);
$scheduleAccess->execute([(int) $_SESSION['user_id']]);
$canManageSchedules = (bool) $scheduleAccess->fetchColumn();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$canManageSchedules) {
    http_response_code(403);
    exit('Access denied.');
}
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$error = '';
$success = '';
$vetId = filter_var($_POST['vet_id'] ?? $_GET['vet_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$vetId = $vetId === false ? 0 : (int) $vetId;

if (!empty($_SESSION['staff_schedule_flash'])) {
    $success = (string) $_SESSION['staff_schedule_flash'];
    unset($_SESSION['staff_schedule_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (posted_string('action') !== 'save_schedule' || $vetId < 1) {
        $error = 'Choose a veterinarian and try again.';
    } else {
        $readTime = static function (array $values, string $key): string {
            $value = $values[$key] ?? '';
            return is_string($value) ? trim($value) : '';
        };
        $weekly = [];
        try {
            $submittedDays = $_POST['schedule'] ?? [];
            if (!is_array($submittedDays)) {
                throw new DomainException('The schedule form was invalid. Please try again.');
            }

            foreach ($days as $day => $label) {
                $entry = $submittedDays[$day] ?? [];
                if (!is_array($entry)) {
                    throw new DomainException('The schedule form was invalid. Please try again.');
                }
                $active = isset($entry['active']) && $entry['active'] === '1';
                if (!$active) {
                    $weekly[$day] = ['active' => false, 'breaks' => []];
                    continue;
                }

                $start = $readTime($entry, 'start');
                $end = $readTime($entry, 'end');
                if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $start)
                    || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $end)
                    || $start >= $end) {
                    throw new DomainException($label . ': enter valid opening and closing times.');
                }

                $breakRows = $entry['breaks'] ?? [];
                if (!is_array($breakRows) || count($breakRows) > 6) {
                    throw new DomainException($label . ': add no more than six break periods.');
                }
                $breaks = [];
                foreach ($breakRows as $breakRow) {
                    if (!is_array($breakRow)) {
                        throw new DomainException('The break-time form was invalid. Please try again.');
                    }
                    $breakStart = $readTime($breakRow, 'start');
                    $breakEnd = $readTime($breakRow, 'end');
                    if ($breakStart === '' && $breakEnd === '') {
                        continue;
                    }
                    if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $breakStart)
                        || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $breakEnd)
                        || $breakStart >= $breakEnd
                        || $breakStart < $start
                        || $breakEnd > $end) {
                        throw new DomainException($label . ': each break must be inside the working hours.');
                    }
                    $breaks[] = ['start' => $breakStart, 'end' => $breakEnd];
                }
                usort($breaks, static function (array $left, array $right): int {
                    return strcmp($left['start'], $right['start']);
                });
                for ($index = 1; $index < count($breaks); $index++) {
                    if ($breaks[$index]['start'] < $breaks[$index - 1]['end']) {
                        throw new DomainException($label . ': break periods cannot overlap.');
                    }
                }
                $weekly[$day] = ['active' => true, 'start' => $start, 'end' => $end, 'breaks' => $breaks];
            }

            $pdo->beginTransaction();
            $vetCheck = $pdo->prepare('SELECT vet_id FROM Veterinarians WHERE vet_id = ? FOR UPDATE');
            $vetCheck->execute([$vetId]);
            if (!$vetCheck->fetchColumn()) {
                throw new DomainException('That veterinarian no longer exists.');
            }

            $activeStatuses = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
            $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
            $appointments = $pdo->prepare(
                "SELECT appointment_date, duration_minutes
                 FROM Appointments
                 WHERE vet_id = ? AND appointment_date >= CURRENT_DATE
                   AND WEEKDAY(appointment_date) + 1 = ?
                   AND status IN ($statusPlaceholders)
                 ORDER BY appointment_date
                 FOR UPDATE"
            );
            $saveSchedule = $pdo->prepare(
                'INSERT INTO VetSchedules (vet_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time)'
            );
            $deleteSchedule = $pdo->prepare('DELETE FROM VetSchedules WHERE vet_id = ? AND day_of_week = ?');
            $deleteBreaks = $pdo->prepare('DELETE FROM VetScheduleBreaks WHERE vet_id = ? AND day_of_week = ?');
            $saveBreak = $pdo->prepare('INSERT INTO VetScheduleBreaks (vet_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)');

            foreach ($weekly as $day => $schedule) {
                $appointments->execute(array_merge([$vetId, $day], $activeStatuses));
                foreach ($appointments->fetchAll() as $appointment) {
                    if (!$schedule['active']) {
                        throw new DomainException($days[$day] . ' has active appointments. Reschedule them before closing this day.');
                    }

                    $appointmentStart = new DateTimeImmutable((string) $appointment['appointment_date']);
                    $appointmentEnd = $appointmentStart->modify('+' . (int) $appointment['duration_minutes'] . ' minutes');
                    $appointmentDate = $appointmentStart->format('Y-m-d');
                    $workStart = new DateTimeImmutable($appointmentDate . ' ' . $schedule['start'] . ':00');
                    $workEnd = new DateTimeImmutable($appointmentDate . ' ' . $schedule['end'] . ':00');
                    if ($appointmentStart < $workStart || $appointmentEnd > $workEnd) {
                        throw new DomainException($days[$day] . ' has an active appointment outside the new working hours.');
                    }
                    foreach ($schedule['breaks'] as $break) {
                        $breakStart = new DateTimeImmutable($appointmentDate . ' ' . $break['start'] . ':00');
                        $breakEnd = new DateTimeImmutable($appointmentDate . ' ' . $break['end'] . ':00');
                        if ($appointmentStart < $breakEnd && $appointmentEnd > $breakStart) {
                            throw new DomainException($days[$day] . ' has an active appointment during a break period.');
                        }
                    }
                }

                if ($schedule['active']) {
                    $saveSchedule->execute([$vetId, $day, $schedule['start'], $schedule['end']]);
                    $deleteBreaks->execute([$vetId, $day]);
                    foreach ($schedule['breaks'] as $break) {
                        $saveBreak->execute([$vetId, $day, $break['start'], $break['end']]);
                    }
                } else {
                    $deleteBreaks->execute([$vetId, $day]);
                    $deleteSchedule->execute([$vetId, $day]);
                }
            }

            $isAvailable = posted_string('is_available') === '1' ? 1 : 0;
            $pdo->prepare('UPDATE Veterinarians SET is_available = ? WHERE vet_id = ?')->execute([$isAvailable, $vetId]);
            role_log($pdo, (int) $_SESSION['user_id'], 'vet.schedule.updated', 'veterinarian', $vetId, 'Weekly availability updated');
            $pdo->commit();
            $_SESSION['staff_schedule_flash'] = 'Veterinarian schedule saved.';
            header('Location: schedules.php?vet_id=' . $vetId);
            exit;
        } catch (DomainException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Staff schedule update: ' . $ex->getMessage());
            $error = 'The veterinarian schedule could not be saved. Please try again.';
        }
    }
}

$vets = $pdo->query('SELECT vet_id, full_name, license_number, is_available FROM Veterinarians ORDER BY full_name')->fetchAll();
if ($vetId < 1 && $vets) {
    $vetId = (int) $vets[0]['vet_id'];
}
$selectedVet = null;
foreach ($vets as $vet) {
    if ((int) $vet['vet_id'] === $vetId) {
        $selectedVet = $vet;
        break;
    }
}
$scheduleByDay = [];
$breaksByDay = [];
if ($selectedVet) {
    $statement = $pdo->prepare('SELECT day_of_week, start_time, end_time FROM VetSchedules WHERE vet_id = ?');
    $statement->execute([$vetId]);
    foreach ($statement->fetchAll() as $row) {
        $scheduleByDay[(int) $row['day_of_week']] = $row;
    }
    $statement = $pdo->prepare('SELECT day_of_week, start_time, end_time FROM VetScheduleBreaks WHERE vet_id = ? ORDER BY day_of_week, start_time');
    $statement->execute([$vetId]);
    foreach ($statement->fetchAll() as $row) {
        $breaksByDay[(int) $row['day_of_week']][] = $row;
    }
}

role_render(
    'staff',
    ['schedules'],
    'Veterinarian Schedules',
    $canManageSchedules ? 'Manage schedules granted by clinic administration' : 'View approved veterinarian schedules',
    function (array $context) use ($days, $error, $success, $vetId, $vets, $selectedVet, $scheduleByDay, $breaksByDay, $canManageSchedules): void {
    if ($error !== '') {
        echo '<div class="notice error" role="alert">' . e($error) . '</div>';
    }
    if ($success !== '') {
        echo '<div class="notice success" role="status">' . e($success) . '</div>';
    }
    ?>
    <section class="panel">
      <div class="panel-header"><h2>Choose veterinarian</h2></div>
      <?php if (!$vets): ?>
        <div class="empty"><strong>No veterinarians are registered</strong><span>Add a veterinarian account before setting clinic hours.</span></div>
      <?php else: ?>
        <div class="panel-body">
          <form method="get" class="form-grid">
            <label class="field">Veterinarian
              <select name="vet_id" onchange="this.form.submit()">
                <?php foreach ($vets as $vet): ?>
                  <option value="<?= (int) $vet['vet_id'] ?>" <?= (int) $vet['vet_id'] === $vetId ? 'selected' : '' ?>><?= e($vet['full_name']) ?><?= empty($vet['is_available']) ? ' · unavailable' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </form>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($selectedVet): ?>
      <section class="panel">
        <div class="panel-header"><h2>Weekly clinic hours · <?= e($selectedVet['full_name']) ?></h2></div>
        <div class="panel-body">
          <?php if ($canManageSchedules): ?>
          <p class="form-note">Saved hours update real client booking availability. Existing active appointments must remain inside the working hours and outside breaks.</p>
          <form method="post" id="vet-schedule-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_schedule">
            <input type="hidden" name="vet_id" value="<?= $vetId ?>">
            <label class="field" style="display:flex;align-items:center;gap:9px;margin-bottom:14px">
              <input type="checkbox" name="is_available" value="1" <?= !empty($selectedVet['is_available']) ? 'checked' : '' ?>>
              Accept new online and staff bookings
            </label>
            <div class="schedule-week">
              <?php foreach ($days as $day => $label):
                  $current = $scheduleByDay[$day] ?? null;
                  $breakRows = $breaksByDay[$day] ?? [];
                  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['schedule'][$day]) && is_array($_POST['schedule'][$day])) {
                      $postedDay = $_POST['schedule'][$day];
                      $current = isset($postedDay['active']) ? ['start_time' => $postedDay['start'] ?? '', 'end_time' => $postedDay['end'] ?? ''] : null;
                      $breakRows = is_array($postedDay['breaks'] ?? null) ? $postedDay['breaks'] : [];
                  }
                  $working = $current !== null;
              ?>
                <fieldset class="schedule-day<?= $working ? '' : ' is-off' ?>" data-schedule-day>
                  <legend class="sr-only"><?= e($label) ?></legend>
                  <div class="schedule-day-head">
                    <label><input class="schedule-active" type="checkbox" name="schedule[<?= $day ?>][active]" value="1" <?= $working ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                    <span class="hint"><?= $working ? 'Open for appointments' : 'Closed' ?></span>
                  </div>
                  <div class="schedule-fields">
                    <label class="field">Opens
                      <input type="time" name="schedule[<?= $day ?>][start]" value="<?= e($working ? substr((string) $current['start_time'], 0, 5) : '') ?>" <?= $working ? '' : 'disabled' ?>>
                    </label>
                    <label class="field">Closes
                      <input type="time" name="schedule[<?= $day ?>][end]" value="<?= e($working ? substr((string) $current['end_time'], 0, 5) : '') ?>" <?= $working ? '' : 'disabled' ?>>
                    </label>
                  </div>
                  <div class="schedule-breaks">
                    <h3>Break periods</h3>
                    <div class="break-list" id="break-list-<?= $day ?>" data-next-index="<?= count($breakRows) ?>">
                      <?php foreach ($breakRows as $index => $break): ?>
                        <?php $breakStart = is_array($break) ? (string) ($break['start_time'] ?? $break['start'] ?? '') : ''; $breakEnd = is_array($break) ? (string) ($break['end_time'] ?? $break['end'] ?? '') : ''; ?>
                        <div class="break-row" data-index="<?= (int) $index ?>">
                          <label class="field">Break starts<input type="time" name="schedule[<?= $day ?>][breaks][<?= (int) $index ?>][start]" value="<?= e(substr($breakStart, 0, 5)) ?>" <?= $working ? '' : 'disabled' ?>></label>
                          <label class="field">Break ends<input type="time" name="schedule[<?= $day ?>][breaks][<?= (int) $index ?>][end]" value="<?= e(substr($breakEnd, 0, 5)) ?>" <?= $working ? '' : 'disabled' ?>></label>
                          <button class="button small secondary" type="button" data-remove-break <?= $working ? '' : 'disabled' ?>>Remove</button>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <button class="button small secondary" type="button" data-add-break="<?= $day ?>" <?= $working ? '' : 'disabled' ?>>Add break</button>
                  </div>
                </fieldset>
              <?php endforeach; ?>
            </div>
            <div class="schedule-actions"><button class="button" type="submit">Save weekly schedule</button></div>
          </form>
          <?php else: ?>
          <p class="hint">These published hours guide staff and client booking. Schedule changes are controlled by clinic administration.</p>
          <div class="table-wrap data-table-card">
            <table class="records-table">
              <colgroup><col style="width:24%"><col style="width:31%"><col></colgroup>
              <thead><tr><th>Day</th><th>Working hours</th><th>Break periods</th></tr></thead>
              <tbody>
                <?php foreach ($days as $day => $label): ?>
                  <?php
                  $current = $scheduleByDay[$day] ?? null;
                  $breakLabels = [];
                  foreach ($breaksByDay[$day] ?? [] as $break) {
                      $breakLabels[] = date('g:i A', strtotime((string) $break['start_time']))
                          . ' - ' . date('g:i A', strtotime((string) $break['end_time']));
                  }
                  ?>
                  <tr>
                    <td data-label="Day"><?= e($label) ?></td>
                    <td data-label="Working hours"><?= $current ? e(date('g:i A', strtotime((string) $current['start_time'])) . ' - ' . date('g:i A', strtotime((string) $current['end_time']))) : 'Closed' ?></td>
                    <td data-label="Break periods"><?= $breakLabels ? e(implode(', ', $breakLabels)) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </section>
      <?php if ($canManageSchedules): ?>
      <template id="break-template">
        <div class="break-row">
          <label class="field">Break starts<input type="time" data-break-start></label>
          <label class="field">Break ends<input type="time" data-break-end></label>
          <button class="button small secondary" type="button" data-remove-break>Remove</button>
        </div>
      </template>
      <script>
        (function () {
          var template = document.getElementById('break-template');
          document.querySelectorAll('[data-schedule-day]').forEach(function (day) {
            var toggle = day.querySelector('.schedule-active');
            function syncDay() {
              var enabled = toggle.checked;
              day.classList.toggle('is-off', !enabled);
              day.querySelectorAll('.schedule-fields input, .break-list input, [data-add-break], [data-remove-break]').forEach(function (field) {
                field.disabled = !enabled;
              });
            }
            toggle.addEventListener('change', syncDay);
            day.querySelector('[data-add-break]').addEventListener('click', function (event) {
              var button = event.currentTarget;
              var list = day.querySelector('.break-list');
              if (list.children.length >= 6) return;
              var index = parseInt(list.dataset.nextIndex, 10) || 0;
              list.dataset.nextIndex = String(index + 1);
              var row = template.content.firstElementChild.cloneNode(true);
              row.dataset.index = String(index);
              row.querySelector('[data-break-start]').name = 'schedule[' + toggle.name.match(/\d+/)[0] + '][breaks][' + index + '][start]';
              row.querySelector('[data-break-end]').name = 'schedule[' + toggle.name.match(/\d+/)[0] + '][breaks][' + index + '][end]';
              row.querySelector('[data-remove-break]').addEventListener('click', function () { row.remove(); });
              list.appendChild(row);
              button.disabled = list.children.length >= 6;
              syncDay();
            });
            day.querySelectorAll('[data-remove-break]').forEach(function (button) {
              button.addEventListener('click', function () {
                button.closest('.break-row').remove();
                day.querySelector('[data-add-break]').disabled = !toggle.checked || day.querySelector('.break-list').children.length >= 6;
              });
            });
            syncDay();
          });
        })();
      </script>
      <?php endif; ?>
    <?php endif; ?>
    <?php
});
