<?php
require_once __DIR__ . '/../includes/role_layout.php';
if (is_file(__DIR__ . '/../includes/pet_photos.php')) {
    require_once __DIR__ . '/../includes/pet_photos.php';   // pet_photo_url() for avatars in the patient finder
}
require_role(['staff']);

function staff_schedule_service_ids($values): array
{
    if (!is_array($values)) {
        return [];
    }

    $serviceIds = [];
    foreach ($values as $value) {
        $serviceId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($serviceId === false) {
            return [];
        }
        $serviceIds[(int) $serviceId] = (int) $serviceId;
    }
    return array_values($serviceIds);
}

function staff_schedule_datetime(string $value): ?DateTimeImmutable
{
    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    return $dateTime instanceof DateTimeImmutable && $dateTime->format('Y-m-d\TH:i') === $value ? $dateTime : null;
}

$pdo = db();
$error = '';
$old = [
    'pet_id' => posted_string('pet_id'),
    'vet_id' => posted_string('vet_id'),
    'appointment_datetime' => posted_string('appointment_datetime'),
    'service_ids' => staff_schedule_service_ids($_POST['service_ids'] ?? []),
    'payment_timing_preference' => posted_string('payment_timing_preference') ?: 'At clinic',
    'preferred_payment_method' => posted_string('preferred_payment_method') ?: 'Cash',
    'notes' => posted_string('notes'),
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (posted_string('action') !== 'create_appointment') {
        $error = 'Unsupported appointment request.';
    } else {
        $petId = filter_var($old['pet_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $vetId = filter_var($old['vet_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $serviceIds = $old['service_ids'];
        $visitAt = staff_schedule_datetime($old['appointment_datetime']);
        $paymentTimings = ['At clinic', 'Advance request'];
        $paymentMethods = ['Cash', 'Card', 'GCash', 'Maya', 'MariBank', 'Bank Transfer', 'Other / ask clinic'];
        $notes = mb_substr(trim($old['notes']), 0, 255);

        try {
            if ($petId === false || $vetId === false) {
                throw new DomainException('Choose a pet and veterinarian.');
            }
            if (!$serviceIds) {
                throw new DomainException('Choose at least one requested service.');
            }
            if (!$visitAt) {
                throw new DomainException('Choose a valid appointment date and time.');
            }
            if (!in_array($old['payment_timing_preference'], $paymentTimings, true)
                || !in_array($old['preferred_payment_method'], $paymentMethods, true)) {
                throw new DomainException('Choose a valid payment preference.');
            }
            $earliest = (new DateTimeImmutable('now'))->modify('+60 minutes');
            $latest = (new DateTimeImmutable('today'))->modify('+60 days')->setTime(23, 30);
            if ($visitAt < $earliest || $visitAt > $latest) {
                throw new DomainException('Choose a time at least one hour from now and within the next 60 days.');
            }
            if (!in_array((int) $visitAt->format('i'), [0, 30], true)) {
                throw new DomainException('Appointment times must start on the hour or half-hour.');
            }

            $pdo->beginTransaction();
            $vetStatement = $pdo->prepare(
                'SELECT vet_id, full_name, max_appointments_per_day
                 FROM Veterinarians
                 WHERE vet_id = ? AND is_available = 1
                 FOR UPDATE'
            );
            $vetStatement->execute([(int) $vetId]);
            $vet = $vetStatement->fetch();
            if (!$vet) {
                throw new DomainException('That veterinarian is unavailable for new bookings.');
            }
            $staffStatement = $pdo->prepare("SELECT staff_id FROM Users WHERE user_id = ? AND `role` = 'staff' AND staff_id IS NOT NULL FOR UPDATE");
            $staffStatement->execute([(int) $_SESSION['user_id']]);
            $staffId = (int) $staffStatement->fetchColumn();
            if ($staffId < 1) {
                throw new RuntimeException('The staff account is not linked to a staff profile.');
            }

            $petStatement = $pdo->prepare(
                'SELECT p.pet_id, p.owner_id, p.pet_name, o.full_name AS owner_name
                 FROM Pets p JOIN PetOwners o ON o.owner_id = p.owner_id
                 WHERE p.pet_id = ? FOR UPDATE'
            );
            $petStatement->execute([(int) $petId]);
            $pet = $petStatement->fetch();
            if (!$pet) {
                throw new DomainException('That pet is no longer registered.');
            }

            $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
            $serviceStatement = $pdo->prepare(
                "SELECT service_id, service_name, base_price, duration_minutes
                 FROM Services
                 WHERE is_active = 1 AND service_id IN ($servicePlaceholders)
                 ORDER BY service_name"
            );
            $serviceStatement->execute($serviceIds);
            $services = $serviceStatement->fetchAll();
            if (count($services) !== count($serviceIds)) {
                throw new DomainException('One or more selected services are no longer available.');
            }
            $duration = array_sum(array_map(static function (array $service): int {
                return (int) $service['duration_minutes'];
            }, $services));
            if ($duration < 1 || $duration > 480) {
                throw new DomainException('The selected services exceed the clinic’s maximum visit length.');
            }

            $qualification = $pdo->prepare("SELECT COUNT(DISTINCT service_id) FROM VetServices WHERE vet_id = ? AND service_id IN ($servicePlaceholders)");
            $qualification->execute(array_merge([(int) $vetId], $serviceIds));
            if ((int) $qualification->fetchColumn() !== count($serviceIds)) {
                throw new DomainException('That veterinarian is not qualified for every selected service.');
            }

            $date = $visitAt->format('Y-m-d');
            $dayOfWeek = (int) $visitAt->format('N');
            $scheduleStatement = $pdo->prepare('SELECT start_time, end_time FROM VetSchedules WHERE vet_id = ? AND day_of_week = ? FOR UPDATE');
            $scheduleStatement->execute([(int) $vetId, $dayOfWeek]);
            $schedule = $scheduleStatement->fetch();
            if (!$schedule) {
                throw new DomainException('That veterinarian has no working hours on the selected day.');
            }
            $visitEnd = $visitAt->modify('+' . $duration . ' minutes');
            $workStart = new DateTimeImmutable($date . ' ' . $schedule['start_time']);
            $workEnd = new DateTimeImmutable($date . ' ' . $schedule['end_time']);
            if ($visitAt < $workStart || $visitEnd > $workEnd) {
                throw new DomainException('The appointment does not fit inside the veterinarian’s working hours.');
            }

            $holiday = $pdo->prepare('SELECT holiday_name FROM ClinicHolidays WHERE holiday_date = ? LIMIT 1');
            $holiday->execute([$date]);
            if ($holiday->fetchColumn()) {
                throw new DomainException('The clinic is closed on the selected date.');
            }
            $leave = $pdo->prepare(
                "SELECT COUNT(*) FROM VetLeaves
                 WHERE vet_id = ? AND status = 'Approved' AND start_date <= ? AND end_date >= ?"
            );
            $leave->execute([(int) $vetId, $date, $date]);
            if ((int) $leave->fetchColumn() > 0) {
                throw new DomainException('That veterinarian is on approved leave on the selected date.');
            }

            $capacityStatuses = ['Scheduled', 'Waiting', 'Check-in', 'In Consultation', 'Complete'];
            $capacityPlaceholders = implode(',', array_fill(0, count($capacityStatuses), '?'));
            $dailyCount = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM Appointments
                 WHERE vet_id = ? AND DATE(appointment_date) = ?
                   AND status IN ($capacityPlaceholders)
                 FOR UPDATE"
            );
            $dailyCount->execute(array_merge([(int) $vetId, $date], $capacityStatuses));
            if ((int) $dailyCount->fetchColumn() >= (int) $vet['max_appointments_per_day']) {
                throw new DomainException('That veterinarian has reached the daily appointment capacity.');
            }

            $breakStatement = $pdo->prepare('SELECT start_time, end_time FROM VetScheduleBreaks WHERE vet_id = ? AND day_of_week = ?');
            $breakStatement->execute([(int) $vetId, $dayOfWeek]);
            foreach ($breakStatement->fetchAll() as $break) {
                $breakStart = new DateTimeImmutable($date . ' ' . $break['start_time']);
                $breakEnd = new DateTimeImmutable($date . ' ' . $break['end_time']);
                if ($visitAt < $breakEnd && $visitEnd > $breakStart) {
                    throw new DomainException('The appointment overlaps the veterinarian’s break.');
                }
            }

            $activeStatuses = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
            $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
            $conflicts = $pdo->prepare(
                "SELECT appointment_date, duration_minutes
                 FROM Appointments
                 WHERE vet_id = ? AND appointment_date < ?
                   AND DATE_ADD(appointment_date, INTERVAL duration_minutes MINUTE) > ?
                   AND status IN ($statusPlaceholders)
                 ORDER BY appointment_date
                 FOR UPDATE"
            );
            $conflicts->execute(array_merge([
                (int) $vetId,
                $visitEnd->format('Y-m-d H:i:s'),
                $visitAt->format('Y-m-d H:i:s'),
            ], $activeStatuses));
            if ($conflicts->fetch()) {
                throw new DomainException('That veterinarian already has an appointment during this time.');
            }

            foreach ($services as $service) {
                if ($service['service_name'] === 'Other / Request a Quote' && $notes === '') {
                    throw new DomainException('Describe the requested service in the visit notes.');
                }
            }

            $insertAppointment = $pdo->prepare(
                "INSERT INTO Appointments
                    (pet_id, vet_id, staff_id, appointment_date, duration_minutes, status, client_notes,
                     payment_timing_preference, preferred_payment_method, approved_at)
                 VALUES (?, ?, ?, ?, ?, 'Scheduled', ?, ?, ?, NOW())"
            );
            $insertAppointment->execute([
                (int) $petId,
                (int) $vetId,
                $staffId,
                $visitAt->format('Y-m-d H:i:s'),
                $duration,
                $notes !== '' ? $notes : null,
                $old['payment_timing_preference'],
                $old['preferred_payment_method'],
            ]);
            $appointmentId = (int) $pdo->lastInsertId();

            $insertService = $pdo->prepare('INSERT INTO AppointmentServices (appointment_id, service_id, charged_price) VALUES (?, ?, ?)');
            foreach ($services as $service) {
                $insertService->execute([$appointmentId, (int) $service['service_id'], (float) $service['base_price']]);
            }
            $pdo->prepare(
                'INSERT INTO AppointmentStatusHistory (appointment_id, previous_status, new_status, changed_by_user_id, reason)
                 VALUES (?, NULL, ?, ?, ?)'
            )->execute([$appointmentId, 'Scheduled', (int) $_SESSION['user_id'], 'Scheduled by clinic staff.']);

            $serviceNames = array_map(static function (array $service): string {
                return (string) $service['service_name'];
            }, $services);
            $notice = 'Appointment scheduled for ' . $pet['pet_name'] . ' (' . implode(', ', $serviceNames) . ') on '
                . $visitAt->format('M j, Y g:i A') . '.';
            role_notify_owner(
                $pdo,
                (int) $pet['owner_id'],
                'appointment',
                'Appointment scheduled',
                $notice,
                'client/appointments.php',
                'staff_scheduled_appointment_' . $appointmentId
            );
            role_notify_vet(
                $pdo,
                (int) $vetId,
                'appointment',
                'New appointment scheduled',
                $notice,
                'vet/appointments.php',
                'staff_scheduled_appointment_' . $appointmentId
            );
            role_notify_role(
                $pdo,
                'admin',
                'appointment',
                'Appointment scheduled by staff',
                $notice,
                'admin/appointments.php',
                'staff_scheduled_appointment_admin_' . $appointmentId
            );
            role_log($pdo, (int) $_SESSION['user_id'], 'appointment.scheduled', 'appointment', $appointmentId, $notice);
            $pdo->commit();

            $_SESSION['staff_appointment_flash'] = 'Appointment scheduled for ' . $pet['pet_name'] . '.';
            header('Location: appointments.php');
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
            error_log('Staff appointment scheduling: ' . $ex->getMessage());
            $error = 'The appointment could not be scheduled. Check availability and try again.';
        }
    }
}

$pets = $pdo->query(
    'SELECT p.pet_id, p.owner_id, p.pet_name, p.species, p.breed, o.full_name AS owner_name,
            IF(p.photo_data IS NULL, NULL, COALESCE(UNIX_TIMESTAMP(p.photo_updated_at), 1)) AS photo_ver
     FROM Pets p JOIN PetOwners o ON o.owner_id = p.owner_id
     ORDER BY o.full_name, p.pet_name'
)->fetchAll();
$services = $pdo->query(
    "SELECT service_id, service_name, base_price, duration_minutes, description
     FROM Services WHERE is_active = 1
     ORDER BY CASE WHEN service_name = 'Other / Request a Quote' THEN 1 ELSE 0 END, service_name"
)->fetchAll();
$vets = $pdo->query(
    'SELECT v.vet_id, v.full_name, GROUP_CONCAT(DISTINCT vs.service_id ORDER BY vs.service_id) AS service_ids
     FROM Veterinarians v LEFT JOIN VetServices vs ON vs.vet_id = v.vet_id
     WHERE v.is_available = 1
     GROUP BY v.vet_id, v.full_name
     ORDER BY v.full_name'
)->fetchAll();
$minimumDateTime = (new DateTimeImmutable('now'))->modify('+60 minutes');
$minutesToSlot = (30 - ((int) $minimumDateTime->format('i') % 30)) % 30;
if ($minutesToSlot > 0) {
    $minimumDateTime = $minimumDateTime->modify('+' . $minutesToSlot . ' minutes');
}
$minimumDateTime = $minimumDateTime->setTime((int) $minimumDateTime->format('H'), (int) $minimumDateTime->format('i'), 0);
$maximumDateTime = (new DateTimeImmutable('today'))->modify('+60 days')->setTime(23, 30);

role_render('staff', ['appointments'], 'Schedule an Appointment', 'Book a visit for an owner using current veterinarian availability', function (array $context) use ($error, $old, $pets, $services, $vets, $minimumDateTime, $maximumDateTime): void {
    $methods = ['Cash', 'Card', 'GCash', 'Maya', 'MariBank', 'Bank Transfer', 'Other / ask clinic'];
    $blocked = !$pets || !$services || !$vets;
    $petData = array_map(static function (array $p): array {
        return [
            'id' => (int) $p['pet_id'], 'pet' => (string) $p['pet_name'], 'owner' => (string) $p['owner_name'],
            'species' => (string) ($p['species'] ?? ''), 'breed' => (string) ($p['breed'] ?? ''),
            'photo' => ($p['photo_ver'] && function_exists('pet_photo_url')) ? pet_photo_url($p['photo_ver'], (int) $p['pet_id']) : '',
        ];
    }, $pets);
    ?>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$pets): ?><div class="notice info">Register an owner and pet before scheduling a visit. <a href="owners.php">Open Clients &amp; Pets</a></div>
    <?php elseif (!$services): ?><div class="notice info">There are no active services to schedule. Add a service first.</div>
    <?php elseif (!$vets): ?><div class="notice info">There are no veterinarians accepting new bookings.</div><?php endif; ?>

    <div class="sa-intro">
      <p>Every booking is checked against service qualifications, weekly hours, breaks, approved leave, holidays and existing appointments before it is saved.</p>
      <a class="button secondary small" href="appointments.php">← Back to appointments</a>
    </div>

    <form method="post" id="sa-form" class="sa-layout">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_appointment">

      <div class="sa-main">
        <!-- 1. Patient -->
        <section class="panel sa-step">
          <div class="panel-header"><h2><span class="sa-num">1</span>Patient</h2><span class="data-count"><?= count($pets) ?> registered</span></div>
          <div class="sa-body">
            <input type="hidden" name="pet_id" id="sa-pet" value="<?= e($old['pet_id']) ?>">
            <div class="sa-field" id="sa-pet-finder">
              <label for="sa-pet-search">Find a patient</label>
              <input type="search" id="sa-pet-search" placeholder="Type a pet name, owner name, species or breed…" autocomplete="off"
                role="combobox" aria-expanded="false" aria-controls="sa-pet-results" <?= $blocked ? 'disabled' : '' ?>>
              <span class="sa-hint">Start typing any part of a name, e.g. “bella”, “santos” or “bella santos”. Pick the patient from the list.</span>
              <div class="sa-results" id="sa-pet-results" role="listbox" aria-label="Matching patients" hidden></div>
            </div>
            <div class="sa-chosen" id="sa-pet-chosen" hidden>
              <span class="sa-avatar" id="sa-chosen-avatar" aria-hidden="true"></span>
              <div class="sa-chosen-copy"><strong id="sa-chosen-pet"></strong><span id="sa-chosen-detail"></span><small id="sa-chosen-owner"></small></div>
              <button class="button secondary small" type="button" id="sa-pet-change">Change</button>
            </div>
          </div>
        </section>

        <!-- 2. Services -->
        <section class="panel sa-step">
          <div class="panel-header"><h2><span class="sa-num">2</span>Requested services</h2><span class="data-count" id="sa-service-count">None selected</span></div>
          <div class="sa-body">
            <div class="sa-service-grid">
              <?php foreach ($services as $service): $checked = in_array((int) $service['service_id'], $old['service_ids'], true); ?>
                <label class="sa-service<?= $checked ? ' is-checked' : '' ?>">
                  <input type="checkbox" name="service_ids[]" value="<?= (int) $service['service_id'] ?>"
                    data-name="<?= e($service['service_name']) ?>" data-price="<?= e((string) $service['base_price']) ?>" data-duration="<?= (int) $service['duration_minutes'] ?>" <?= $checked ? 'checked' : '' ?>>
                  <span class="sa-service-box" aria-hidden="true"></span>
                  <span class="sa-service-copy">
                    <strong><?= e($service['service_name']) ?></strong>
                    <small><?= e($service['description'] ?: 'Ask the clinic for service details.') ?></small>
                    <span class="sa-service-meta"><b>₱<?= number_format((float) $service['base_price'], 2) ?></b><i><?= (int) $service['duration_minutes'] ?> min</i></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <!-- 3. Veterinarian and time -->
        <section class="panel sa-step">
          <div class="panel-header"><h2><span class="sa-num">3</span>Veterinarian &amp; time</h2></div>
          <div class="sa-body sa-two">
            <div class="sa-field">
              <label for="staff-vet">Veterinarian</label>
              <select name="vet_id" id="staff-vet" required>
                <option value="">Choose a veterinarian</option>
                <?php foreach ($vets as $vet): ?>
                  <option value="<?= (int) $vet['vet_id'] ?>" data-service-ids="<?= e((string) $vet['service_ids']) ?>" <?= (string) $old['vet_id'] === (string) $vet['vet_id'] ? 'selected' : '' ?>><?= e($vet['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="sa-hint" id="staff-vet-hint">Choose services to filter veterinarians by qualification.</span>
            </div>
            <div class="sa-field">
              <label for="sa-when">Appointment date and time</label>
              <input type="datetime-local" name="appointment_datetime" id="sa-when" required step="1800"
                min="<?= e($minimumDateTime->format('Y-m-d\TH:i')) ?>" max="<?= e($maximumDateTime->format('Y-m-d\TH:i')) ?>" value="<?= e($old['appointment_datetime']) ?>">
              <span class="sa-hint">30-minute start times, at least one hour ahead and within 60 days.</span>
            </div>
          </div>
        </section>

        <!-- 4. Payment and notes -->
        <section class="panel sa-step">
          <div class="panel-header"><h2><span class="sa-num">4</span>Payment &amp; notes</h2></div>
          <div class="sa-body sa-two">
            <div class="sa-field">
              <label for="sa-timing">Payment timing</label>
              <select name="payment_timing_preference" id="sa-timing" required>
                <option value="At clinic" <?= $old['payment_timing_preference'] === 'At clinic' ? 'selected' : '' ?>>Pay at the clinic</option>
                <option value="Advance request" <?= $old['payment_timing_preference'] === 'Advance request' ? 'selected' : '' ?>>Request advance-payment instructions</option>
              </select>
            </div>
            <div class="sa-field">
              <label for="sa-method">Preferred payment method</label>
              <select name="preferred_payment_method" id="sa-method" required>
                <?php foreach ($methods as $method): ?>
                  <option value="<?= e($method) ?>" <?= $old['preferred_payment_method'] === $method ? 'selected' : '' ?>><?= e($method === 'Maya' ? 'Maya (PayMaya)' : $method) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sa-field sa-full">
              <label for="sa-notes">Visit notes <span class="sa-optional">(optional; required for “Other / Request a Quote”)</span></label>
              <textarea name="notes" id="sa-notes" maxlength="255" rows="3" placeholder="Anything the veterinarian should know before the visit"><?= e($old['notes']) ?></textarea>
            </div>
          </div>
        </section>
      </div>

      <!-- Live booking summary -->
      <aside class="sa-side" aria-label="Booking summary">
        <section class="panel sa-summary">
          <div class="panel-header"><h2>Booking summary</h2></div>
          <div class="sa-body">
            <dl class="sa-sum">
              <div><dt>Pet</dt><dd id="sum-pet">—</dd></div>
              <div><dt>Owner</dt><dd id="sum-owner">—</dd></div>
              <div><dt>Services</dt><dd id="sum-services">—</dd></div>
              <div><dt>Duration</dt><dd id="sum-duration">—</dd></div>
              <div><dt>Veterinarian</dt><dd id="sum-vet">—</dd></div>
              <div><dt>Date &amp; time</dt><dd id="sum-when">—</dd></div>
              <div><dt>Payment</dt><dd id="sum-pay">—</dd></div>
            </dl>
            <div class="sa-total"><span>Estimated total</span><strong id="sum-total">₱0.00</strong></div>
            <p class="sa-missing" id="sa-missing" role="status"></p>
            <button class="button sa-submit" type="submit" id="sa-submit" <?= $blocked ? 'disabled' : '' ?>>Schedule visit</button>
            <p class="sa-fineprint">The owner, veterinarian and admin are notified once the visit is saved.</p>
          </div>
        </section>
      </aside>
    </form>

    <style>
      .sa-intro{margin:0 0 16px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
      .sa-intro p{margin:0;max-width:760px;color:#42637e;font-size:.9rem;line-height:1.5}
      .sa-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;align-items:start;gap:20px;margin-bottom:24px}
      .sa-main{min-width:0;display:grid;gap:18px}
      .sa-side{min-width:0;position:sticky;top:12px}
      .sa-step,.sa-summary{margin:0}
      .sa-step .panel-header h2{display:flex;align-items:center}
      .sa-num{width:26px;height:26px;margin-right:11px;flex:none;display:inline-grid;place-items:center;color:#015b8c;background:#fff;border-radius:50%;font-size:.8rem;font-weight:700}
      .sa-body{padding:20px 22px;display:grid;gap:18px}
      .sa-two{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 20px}
      .sa-full{grid-column:1/-1}
      .sa-field{min-width:0;display:grid;align-content:start;gap:7px}
      .sa-field label{color:#002e4a;font-size:.84rem;font-weight:700}
      .sa-field input,.sa-field select,.sa-field textarea{width:100%;min-height:46px;padding:10px 14px;color:#092947;background:#fff;border:1px solid #b8d2e5;border-radius:10px;font:inherit;font-size:.94rem;transition:border-color .15s,box-shadow .15s}
      .sa-field textarea{min-height:96px;resize:vertical}
      .sa-field input:hover,.sa-field select:hover,.sa-field textarea:hover{border-color:#7fb4d8}
      .sa-field input:focus,.sa-field select:focus,.sa-field textarea:focus{outline:0;border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.2)}
      .sa-hint{color:#5a7a92;font-size:.8rem;line-height:1.4}
      .sa-optional{color:#7b97ad;font-weight:500}

      .sa-service-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
      .sa-service{position:relative;padding:14px 15px;display:flex;align-items:flex-start;gap:12px;background:#fff;border:1px solid #cfe1ef;border-radius:12px;cursor:pointer;transition:border-color .15s,background-color .15s,box-shadow .15s}
      .sa-service:hover{border-color:#7fb4d8;background:#f7fbfe}
      .sa-service.is-checked{border-color:#0284c7;background:#eef7fd;box-shadow:0 0 0 1px #0284c7 inset}
      .sa-service input{position:absolute;opacity:0;pointer-events:none}
      .sa-service input:focus-visible+.sa-service-box{outline:3px solid rgba(2,132,199,.35);outline-offset:2px}
      .sa-service-box{width:20px;height:20px;margin-top:2px;flex:none;display:grid;place-items:center;background:#fff;border:2px solid #9bbdd6;border-radius:6px;transition:background-color .15s,border-color .15s}
      .sa-service.is-checked .sa-service-box{background:#0284c7;border-color:#0284c7}
      .sa-service.is-checked .sa-service-box::after{content:"";width:5px;height:10px;margin-top:-2px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
      .sa-service-copy{min-width:0;display:grid;gap:4px}
      .sa-service-copy strong{color:#002e4a;font-size:.92rem;line-height:1.3}
      .sa-service-copy small{color:#5a7a92;font-size:.8rem;line-height:1.4}
      .sa-service-meta{margin-top:2px;display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:.8rem}
      .sa-service-meta b{color:#015b8c}.sa-service-meta i{padding:2px 9px;color:#42637e;background:#eaf3fa;border-radius:999px;font-style:normal;font-weight:600}

      .sa-results{margin:2px 0 0;max-height:360px;overflow-y:auto;background:#fff;border:1px solid #cfe1ef;border-radius:12px}
      .sa-result{width:100%;padding:11px 14px;display:flex;align-items:center;gap:12px;color:inherit;background:none;border:0;border-bottom:1px solid #e6eff6;font:inherit;text-align:left;cursor:pointer;transition:background-color .12s}
      .sa-result:last-child{border-bottom:0}
      .sa-result:hover,.sa-result.is-active{background:#eaf3fa}
      .sa-result-copy{min-width:0;display:grid;gap:1px}
      .sa-result-copy strong{color:#002e4a;font-size:.94rem}.sa-result-copy span{color:#42637e;font-size:.82rem}
      .sa-result-copy mark{padding:0 1px;color:inherit;background:#ffe9a8;border-radius:3px}
      .sa-result-more{padding:9px 14px;color:#5a7a92;background:#f7fbfe;font-size:.78rem}
      .sa-none{padding:18px 16px;color:#42637e;font-size:.88rem;line-height:1.5}.sa-none a{color:#0284c7;font-weight:700}
      .sa-avatar{width:42px;height:42px;flex:none;display:grid;place-items:center;overflow:hidden;color:#015b8c;background:#d8ebf8;border-radius:50%;font-weight:700}
      .sa-avatar img{width:100%;height:100%;object-fit:cover}
      .sa-chosen{padding:14px 16px;display:flex;align-items:center;gap:14px;background:#eef7fd;border:1px solid #0284c7;border-radius:12px}
      .sa-chosen[hidden],.sa-results[hidden],#sa-pet-finder[hidden]{display:none}
      .sa-chosen .sa-avatar{width:50px;height:50px;font-size:1.1rem}
      .sa-chosen-copy{min-width:0;flex:1;display:grid;gap:2px}
      .sa-chosen-copy strong{color:#002e4a;font-size:1.02rem}.sa-chosen-copy span{color:#42637e;font-size:.86rem}.sa-chosen-copy small{color:#5a7a92;font-size:.82rem}

      .sa-sum{margin:0;display:grid;gap:0}
      .sa-sum div{padding:10px 0;display:grid;grid-template-columns:96px minmax(0,1fr);gap:12px;border-bottom:1px solid #e6eff6}
      .sa-sum dt{color:#5a7a92;font-size:.74rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding-top:2px}
      .sa-sum dd{margin:0;min-width:0;color:#002e4a;font-size:.9rem;font-weight:600;overflow-wrap:anywhere}
      .sa-sum dd.is-empty{color:#9bb2c3;font-weight:500}
      .sa-total{margin-top:-6px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f3f9fe;border:1px solid #d7e8f5;border-radius:12px}
      .sa-total span{color:#42637e;font-size:.84rem;font-weight:600}.sa-total strong{color:#002e4a;font-size:1.3rem}
      .sa-missing{margin:0;color:#8a5a10;font-size:.82rem;line-height:1.45}.sa-missing:empty{display:none}
      .sa-submit{width:100%;min-height:48px;font-size:1rem}
      .sa-submit[disabled]{opacity:.55;cursor:not-allowed}
      .sa-fineprint{margin:-6px 0 0;color:#7b97ad;font-size:.76rem;text-align:center}

      @media(max-width:1060px){.sa-layout{grid-template-columns:1fr}.sa-side{position:static}}
      @media(max-width:640px){.sa-two{grid-template-columns:1fr}.sa-body{padding:16px}.sa-service-grid{grid-template-columns:1fr}.sa-sum div{grid-template-columns:84px minmax(0,1fr)}}
    </style>
    <script>
    (function () {
      var form = document.getElementById('sa-form');
      var petInput = document.getElementById('sa-pet'), petSearch = document.getElementById('sa-pet-search');
      var petResults = document.getElementById('sa-pet-results'), petFinder = document.getElementById('sa-pet-finder'), petChosen = document.getElementById('sa-pet-chosen');
      var petData = <?= json_encode($petData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
      var chosenPet = null, shown = [], activeIdx = -1;
      var vet = document.getElementById('staff-vet'), vetHint = document.getElementById('staff-vet-hint');
      var when = document.getElementById('sa-when'), timing = document.getElementById('sa-timing'), method = document.getElementById('sa-method');
      var notes = document.getElementById('sa-notes'), submit = document.getElementById('sa-submit');
      var checks = Array.prototype.slice.call(document.querySelectorAll('input[name="service_ids[]"]'));
      var money = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      var locked = <?= $blocked ? 'true' : 'false' ?>;

      function setText(id, text, empty) {
        var el = document.getElementById(id);
        el.textContent = text || '—';
        el.classList.toggle('is-empty', !text || !!empty);
      }
      function selected() { return checks.filter(function (c) { return c.checked; }); }

      // ---- Patient finder: type any part of a pet / owner name, species or breed ----
      function detail(p) { return [p.species, p.breed].filter(Boolean).join(' · ') || 'Pet'; }
      function avatar(el, p) {
        el.innerHTML = '';
        if (p.photo) { var img = document.createElement('img'); img.src = p.photo; img.alt = ''; img.loading = 'lazy'; el.appendChild(img); }
        else el.textContent = (p.pet || '?').charAt(0).toUpperCase();
      }
      function highlight(el, text, terms) {
        var low = text.toLowerCase(), best = -1, len = 0;
        terms.forEach(function (t) { var i = low.indexOf(t); if (i !== -1 && (best === -1 || i < best)) { best = i; len = t.length; } });
        if (best === -1) { el.textContent = text; return; }
        el.appendChild(document.createTextNode(text.slice(0, best)));
        var m = document.createElement('mark'); m.textContent = text.slice(best, best + len); el.appendChild(m);
        el.appendChild(document.createTextNode(text.slice(best + len)));
      }
      function findPets(term) {
        var terms = term.toLowerCase().split(/\s+/).filter(Boolean);
        if (!terms.length) return { terms: terms, list: [], total: 0 };
        var all = petData.filter(function (p) {
          var hay = (p.pet + ' ' + p.owner + ' ' + p.species + ' ' + p.breed).toLowerCase();
          return terms.every(function (t) { return hay.indexOf(t) !== -1; });
        });
        return { terms: terms, list: all.slice(0, 8), total: all.length };
      }
      function closeResults() { petResults.hidden = true; petResults.innerHTML = ''; petSearch.setAttribute('aria-expanded', 'false'); shown = []; activeIdx = -1; }
      function setActive(i) {
        activeIdx = i;
        Array.prototype.forEach.call(petResults.querySelectorAll('.sa-result'), function (b, n) { b.classList.toggle('is-active', n === i); if (n === i) b.scrollIntoView({ block: 'nearest' }); });
      }
      function renderResults() {
        var term = petSearch.value.trim(), r = findPets(term);
        petResults.innerHTML = '';
        if (!term) { closeResults(); return; }
        shown = r.list; activeIdx = -1;
        if (!r.list.length) {
          var none = document.createElement('div'); none.className = 'sa-none';
          none.innerHTML = 'No patient matches “<b></b>”. Check the spelling, or <a href="owners.php">register the owner and pet</a> first.';
          none.querySelector('b').textContent = term; petResults.appendChild(none);
        } else {
          r.list.forEach(function (p) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'sa-result'; b.setAttribute('role', 'option');
            var av = document.createElement('span'); av.className = 'sa-avatar'; avatar(av, p);
            var copy = document.createElement('span'); copy.className = 'sa-result-copy';
            var name = document.createElement('strong'); highlight(name, p.pet, r.terms);
            var line = document.createElement('span'); line.appendChild(document.createTextNode(detail(p) + ' · Owner: ')); var own = document.createElement('span'); highlight(own, p.owner, r.terms); line.appendChild(own);
            copy.appendChild(name); copy.appendChild(line); b.appendChild(av); b.appendChild(copy);
            b.addEventListener('mousedown', function (e) { e.preventDefault(); choosePet(p); });
            petResults.appendChild(b);
          });
          if (r.total > r.list.length) { var more = document.createElement('div'); more.className = 'sa-result-more'; more.textContent = (r.total - r.list.length) + ' more match — keep typing to narrow the list.'; petResults.appendChild(more); }
        }
        petResults.hidden = false; petSearch.setAttribute('aria-expanded', 'true');
      }
      function choosePet(p, silent) {
        chosenPet = p; petInput.value = p.id; petSearch.value = ''; closeResults();
        avatar(document.getElementById('sa-chosen-avatar'), p);
        document.getElementById('sa-chosen-pet').textContent = p.pet;
        document.getElementById('sa-chosen-detail').textContent = detail(p);
        document.getElementById('sa-chosen-owner').textContent = 'Owner: ' + p.owner;
        petFinder.hidden = true; petChosen.hidden = false;
        if (!silent) update();
      }
      function clearPet() {
        chosenPet = null; petInput.value = ''; petChosen.hidden = true; petFinder.hidden = false; petSearch.focus(); update();
      }
      petSearch.addEventListener('input', renderResults);
      petSearch.addEventListener('focus', function () { if (petSearch.value.trim()) renderResults(); });
      petSearch.addEventListener('blur', function () { setTimeout(closeResults, 120); });
      petSearch.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' && shown.length) { e.preventDefault(); setActive((activeIdx + 1) % shown.length); }
        else if (e.key === 'ArrowUp' && shown.length) { e.preventDefault(); setActive((activeIdx - 1 + shown.length) % shown.length); }
        else if (e.key === 'Enter') { e.preventDefault(); if (shown.length) choosePet(shown[activeIdx > -1 ? activeIdx : 0]); }
        else if (e.key === 'Escape') { closeResults(); }
      });
      document.getElementById('sa-pet-change').addEventListener('click', clearPet);

      function filterVets() {
        var ids = selected().map(function (c) { return c.value; }), visible = 0;
        Array.prototype.forEach.call(vet.options, function (o) {
          if (!o.value) return;
          var q = (o.dataset.serviceIds || '').split(',').filter(Boolean);
          var ok = !ids.length || ids.every(function (id) { return q.indexOf(id) !== -1; });
          o.hidden = !ok; o.disabled = !ok;
          if (ok) visible++;
          if (!ok && o.selected) vet.value = '';
        });
        vetHint.textContent = visible
          ? (ids.length ? 'Only veterinarians qualified for every selected service are shown.' : 'Choose services to filter veterinarians by qualification.')
          : 'No available veterinarian is qualified for every selected service.';
      }

      function update() {
        var chosen = selected(), minutes = 0, price = 0, names = [];
        chosen.forEach(function (c) { minutes += Number(c.dataset.duration || 0); price += Number(c.dataset.price || 0); names.push(c.dataset.name); });
        checks.forEach(function (c) { c.closest('.sa-service').classList.toggle('is-checked', c.checked); });
        document.getElementById('sa-service-count').textContent = chosen.length ? chosen.length + ' selected' : 'None selected';

        setText('sum-pet', chosenPet ? chosenPet.pet : '');
        setText('sum-owner', chosenPet ? chosenPet.owner : '');
        setText('sum-services', names.join(', '));
        setText('sum-duration', chosen.length ? minutes + ' minutes' : '');
        setText('sum-vet', vet.value ? vet.options[vet.selectedIndex].text : '');
        var when_ = '';
        if (when.value) { var d = new Date(when.value); if (!isNaN(d)) when_ = d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }); }
        setText('sum-when', when_);
        setText('sum-pay', (timing.value === 'Advance request' ? 'Advance payment requested' : 'Pay at clinic') + ' · ' + method.value);
        document.getElementById('sum-total').textContent = '₱' + money.format(price);

        var missing = [];
        if (!petInput.value) missing.push('a pet');
        if (!chosen.length) missing.push('at least one service');
        if (!vet.value) missing.push('a veterinarian');
        if (!when.value) missing.push('a date and time');
        document.getElementById('sa-missing').textContent = missing.length ? 'Still needed: ' + missing.join(', ') + '.' : '';
        submit.disabled = locked || missing.length > 0;
      }

      checks.forEach(function (c) { c.addEventListener('change', function () { filterVets(); update(); }); });
      [vet, when, timing, method].forEach(function (el) { el.addEventListener('change', update); });
      when.addEventListener('input', update);

      // Prevent accidental duplicate submissions.
      form.addEventListener('submit', function () {
        setTimeout(function () { submit.disabled = true; submit.textContent = 'Scheduling…'; }, 0);
      });

      // Restore a previously chosen patient (e.g. after a validation error).
      if (petInput.value) {
        var restored = petData.filter(function (p) { return String(p.id) === petInput.value; })[0];
        if (restored) choosePet(restored, true); else petInput.value = '';
      }
      filterVets();
      update();
    })();
    </script>
    <?php
});