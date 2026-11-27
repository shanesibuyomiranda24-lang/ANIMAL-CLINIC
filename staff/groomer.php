<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_once __DIR__ . '/../includes/pet_photos.php';
require_role(['staff']);

$pdo = db();
$userId = (int) $_SESSION['user_id'];
$staffPositionStatement = $pdo->prepare(
  "SELECT s.`role`
   FROM Users u
   JOIN Staff s ON s.staff_id = u.staff_id
   WHERE u.user_id = ? AND u.`role` = 'staff' AND u.status = 'active'"
);
$staffPositionStatement->execute([$userId]);
$isGroomerAccount = $staffPositionStatement->fetchColumn() === 'Groomer';
$currentGroomerStatement = $pdo->prepare(
  "SELECT g.groomer_id, g.full_name,
      (COALESCE(s.can_manage_groomer_schedules, 0) = 1 OR s.`role` = 'Groomer') AS has_schedule_access
   FROM Groomers g
   JOIN Users u ON u.user_id = g.user_id
   LEFT JOIN Staff s ON s.staff_id = u.staff_id
   WHERE g.user_id = ? AND g.is_available = 1"
);
$currentGroomerStatement->execute([$userId]);
$currentGroomer = $currentGroomerStatement->fetch();
$currentGroomerId = (int) ($currentGroomer['groomer_id'] ?? 0);
$error = '';
$success = (($_GET['ready'] ?? '') === '1')
  ? 'The pet is ready for pickup and the owner has been notified.'
  : ((($_GET['started'] ?? '') === '1')
    ? 'The grooming session has started.'
    : ((($_GET['assigned'] ?? '') === '1')
      ? 'The groomer assignment was saved.'
      : ((($_GET['added'] ?? '') === '1') ? 'The groomer was added to the roster.' : '')));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (posted_string('action') === 'add_groomer') {
      $contactNumber = trim(posted_string('contact_number'));
      if ($isGroomerAccount) {
        $error = 'Ask clinic staff to link your groomer profile.';
      } elseif ($currentGroomerId > 0) {
        $error = 'Your staff account already has a groomer profile.';
      } elseif (mb_strlen($contactNumber) > 20) {
        $error = 'The contact number must be 20 characters or fewer.';
      } else {
        try {
          $fullName = (string) ($_SESSION['full_name'] ?? '');
          $accountStatement = $pdo->prepare(
              "SELECT u.full_name FROM Users u
               JOIN Staff s ON s.staff_id = u.staff_id
               WHERE u.user_id = ? AND u.`role` = 'staff' AND u.status = 'active'"
          );
          $accountStatement->execute([$userId]);
          $fullName = (string) $accountStatement->fetchColumn();
          if ($fullName === '') {
            throw new InvalidArgumentException('An active staff account is required to create a groomer profile.');
          }
          $pdo->beginTransaction();
          $accountStatement = $pdo->prepare(
              'SELECT groomer_id FROM Groomers WHERE user_id IS NULL AND full_name = ? LIMIT 1 FOR UPDATE'
          );
          $accountStatement->execute([$fullName]);
          $unlinkedGroomerId = (int) $accountStatement->fetchColumn();
          if ($unlinkedGroomerId > 0) {
            $pdo->prepare('UPDATE Groomers SET user_id = ?, contact_number = ? WHERE groomer_id = ? AND user_id IS NULL')
                ->execute([$userId, $contactNumber !== '' ? $contactNumber : null, $unlinkedGroomerId]);
          } else {
            $pdo->prepare('INSERT INTO Groomers (user_id, full_name, contact_number) VALUES (?, ?, ?)')
                ->execute([$userId, $fullName, $contactNumber !== '' ? $contactNumber : null]);
          }
          $pdo->commit();
          header('Location: groomer.php?added=1');
          exit;
        } catch (InvalidArgumentException $exception) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          $error = $exception->getMessage();
        } catch (Throwable $exception) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          error_log('Groomer profile creation failed: ' . $exception->getMessage());
          $error = 'The groomer could not be added. Please try again.';
        }
      }
    } elseif (
        ($isGroomerAccount && !in_array(posted_string('action'), ['start_grooming', 'mark_ready'], true))
        || (!$isGroomerAccount && !in_array(posted_string('action'), ['mark_ready', 'assign_groomer'], true))
    ) {
        $error = $isGroomerAccount && posted_string('action') === 'assign_groomer'
            ? 'Groomers cannot assign or change groomer assignments.'
            : 'Choose a valid grooming action.';
    } else {
        $appointmentId = filter_var(posted_string('appointment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($appointmentId === false) {
            $error = 'Choose a valid appointment.';
        } else {
            try {
                $pdo->beginTransaction();
                $appointmentStatement = $pdo->prepare(
                    'SELECT a.appointment_id, a.pet_id, a.appointment_date, a.status, a.pickup_ready_at, a.picked_up_at,
                            p.pet_name, p.owner_id
                     FROM Appointments a
                     JOIN Pets p ON p.pet_id = a.pet_id
                     WHERE a.appointment_id = ?
                     FOR UPDATE'
                );
                $appointmentStatement->execute([(int) $appointmentId]);
                $appointment = $appointmentStatement->fetch();
                if (!$appointment) {
                    throw new InvalidArgumentException('Appointment not found.');
                }
                $groomingStatement = $pdo->prepare(
                    "SELECT EXISTS(
                        SELECT 1
                        FROM AppointmentServices aps
                        JOIN Services s ON s.service_id = aps.service_id
                        WHERE aps.appointment_id = ?
                          AND s.service_category = 'Grooming & Hygiene'
                    )"
                );
                $groomingStatement->execute([(int) $appointmentId]);
                if (!(int) $groomingStatement->fetchColumn()) {
                    throw new InvalidArgumentException('This appointment does not include a grooming service.');
                }

                if ($isGroomerAccount) {
                  if ($currentGroomerId < 1) {
                    throw new InvalidArgumentException('Your groomer profile is not available. Ask clinic staff for help.');
                  }
                  $assignedStatement = $pdo->prepare(
                    "SELECT EXISTS(
                        SELECT 1
                        FROM AppointmentServices aps
                        JOIN Services s ON s.service_id = aps.service_id
                        WHERE aps.appointment_id = ?
                          AND aps.groomer_id = ?
                          AND s.service_category = 'Grooming & Hygiene'
                    )"
                  );
                  $assignedStatement->execute([(int) $appointmentId, $currentGroomerId]);
                  if (!(int) $assignedStatement->fetchColumn()) {
                    throw new InvalidArgumentException('You can only update appointments assigned to you.');
                  }
                }

                if (posted_string('action') === 'assign_groomer') {
                  if ($appointment['pickup_ready_at'] !== null || $appointment['picked_up_at'] !== null
                    || in_array($appointment['status'], ['Cancelled', 'Rejected'], true)) {
                    throw new InvalidArgumentException('A groomer cannot be assigned to a cancelled, pickup-ready, or picked-up appointment.');
                  }
                  $groomerId = filter_var(posted_string('groomer_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                  if ($groomerId === false) {
                    throw new InvalidArgumentException('Choose an available groomer.');
                  }
                  $groomerStatement = $pdo->prepare('SELECT groomer_id FROM Groomers WHERE groomer_id = ? AND is_available = 1');
                  $groomerStatement->execute([(int) $groomerId]);
                  if (!$groomerStatement->fetchColumn()) {
                    throw new InvalidArgumentException('That groomer is not available. Refresh and choose another groomer.');
                  }
                  $pdo->prepare(
                    "UPDATE AppointmentServices aps
                     JOIN Services s ON s.service_id = aps.service_id
                     SET aps.groomer_id = ?
                     WHERE aps.appointment_id = ?
                       AND s.service_category = 'Grooming & Hygiene'"
                  )->execute([(int) $groomerId, (int) $appointmentId]);
                  role_log($pdo, $userId, 'grooming.groomer_assigned', 'appointment', (int) $appointmentId, 'Groomer assigned to grooming services');
                  $pdo->commit();
                  header('Location: groomer.php?assigned=1');
                  exit;
                }

                if (posted_string('action') === 'start_grooming') {
                  if (!$isGroomerAccount || $appointment['status'] !== 'Check-in'
                    || substr((string) $appointment['appointment_date'], 0, 10) !== date('Y-m-d')) {
                    throw new InvalidArgumentException('You can only start an assigned, checked-in grooming appointment on its appointment day.');
                  }
                  if ($appointment['pickup_ready_at'] !== null || $appointment['picked_up_at'] !== null) {
                    throw new InvalidArgumentException('This appointment is already ready for pickup or picked up.');
                  }
                  $startStatement = $pdo->prepare(
                    "UPDATE Appointments
                     SET status = 'In Consultation'
                     WHERE appointment_id = ? AND status = 'Check-in'
                       AND pickup_ready_at IS NULL AND picked_up_at IS NULL"
                  );
                  $startStatement->execute([(int) $appointmentId]);
                  if ($startStatement->rowCount() !== 1) {
                    throw new InvalidArgumentException('This grooming appointment could not be started. Refresh and try again.');
                  }
                  role_log($pdo, $userId, 'grooming.started', 'appointment', (int) $appointmentId, 'Grooming session started');
                  $pdo->commit();
                  header('Location: groomer.php?started=1');
                  exit;
                }

                if ($appointment['pickup_ready_at'] !== null || $appointment['picked_up_at'] !== null) {
                  throw new InvalidArgumentException('This pet is already marked ready for pickup.');
                }
                if (!in_array($appointment['status'], ['Check-in', 'Waiting', 'In Consultation', 'Complete'], true)
                  || $appointment['appointment_date'] > date('Y-m-d H:i:s')) {
                  throw new InvalidArgumentException('Only a checked-in grooming appointment can be marked ready after its appointment time.');
                }

                $pdo->prepare(
                    'UPDATE Appointments SET pickup_ready_at = NOW()
                     WHERE appointment_id = ? AND pickup_ready_at IS NULL AND picked_up_at IS NULL'
                )->execute([(int) $appointmentId]);
                role_log($pdo, $userId, 'grooming.pickup_ready', 'appointment', (int) $appointmentId, 'Grooming completed; pet ready for pickup');
                role_notify_owner(
                    $pdo,
                    (int) $appointment['owner_id'],
                    'appointment',
                    'Pet ready for pickup',
                    $appointment['pet_name'] . ' is ready for pickup from the clinic (appointment #' . (int) $appointmentId . ').',
                    'client/appointments.php',
                    'grooming_pickup_ready_' . (int) $appointmentId
                );
                $pdo->commit();
                header('Location: groomer.php?ready=1');
                exit;
            } catch (InvalidArgumentException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Groomer pickup update failed: ' . $exception->getMessage());
                $error = 'The grooming status could not be updated. Please try again.';
            }
        }
    }
}

$serviceStatement = $pdo->prepare(
    "SELECT service_name, base_price, duration_minutes, description
     FROM Services
     WHERE is_active = 1
       AND service_category = 'Grooming & Hygiene'
     ORDER BY service_name"
);
$serviceStatement->execute();
$services = $serviceStatement->fetchAll();
$groomerStatement = $pdo->query(
  "SELECT g.groomer_id, g.user_id, g.full_name, u.full_name AS account_name,
      (COALESCE(s.can_manage_groomer_schedules, 0) = 1 OR s.`role` = 'Groomer') AS has_schedule_access
   FROM Groomers g
   LEFT JOIN Users u ON u.user_id = g.user_id
   LEFT JOIN Staff s ON s.staff_id = u.staff_id
   WHERE g.is_available = 1
   ORDER BY g.full_name"
);
$groomers = $groomerStatement->fetchAll();

$appointmentFilter = '';
$appointmentParameters = [];
if ($isGroomerAccount) {
  if ($currentGroomerId > 0) {
    $appointmentFilter = "AND EXISTS (
        SELECT 1
        FROM AppointmentServices assigned_aps
        JOIN Services assigned_service ON assigned_service.service_id = assigned_aps.service_id
        WHERE assigned_aps.appointment_id = a.appointment_id
          AND assigned_aps.groomer_id = ?
          AND assigned_service.service_category = 'Grooming & Hygiene'
    )";
    $appointmentParameters[] = $currentGroomerId;
  } else {
    $appointmentFilter = 'AND 1 = 0';
  }
}

$appointmentStatement = $pdo->prepare(
    "SELECT a.appointment_id, a.appointment_date, a.duration_minutes, a.status, a.pickup_ready_at, a.picked_up_at,
            p.pet_id, p.pet_name, p.species, p.breed, p.photo_updated_at,
            o.full_name AS owner_name,
            (SELECT GROUP_CONCAT(DISTINCT g.full_name ORDER BY g.full_name SEPARATOR ', ')
               FROM AppointmentServices aps
               JOIN Services s ON s.service_id = aps.service_id
               JOIN Groomers g ON g.groomer_id = aps.groomer_id
              WHERE aps.appointment_id = a.appointment_id
              AND s.service_category = 'Grooming & Hygiene') AS groomer_names,
            (SELECT CASE WHEN MIN(aps.groomer_id) = MAX(aps.groomer_id) THEN MAX(aps.groomer_id) ELSE NULL END
               FROM AppointmentServices aps
               JOIN Services s ON s.service_id = aps.service_id
              WHERE aps.appointment_id = a.appointment_id
              AND s.service_category = 'Grooming & Hygiene') AS assigned_groomer_id,
            (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
               FROM AppointmentServices aps
               JOIN Services s ON s.service_id = aps.service_id
              WHERE aps.appointment_id = a.appointment_id
                AND s.service_category = 'Grooming & Hygiene') AS service_names
     FROM Appointments a
     JOIN Pets p ON p.pet_id = a.pet_id
     JOIN PetOwners o ON o.owner_id = p.owner_id
     WHERE a.status NOT IN ('Cancelled', 'Rejected')
       $appointmentFilter
       AND EXISTS (
           SELECT 1
           FROM AppointmentServices aps
           JOIN Services s ON s.service_id = aps.service_id
           WHERE aps.appointment_id = a.appointment_id
             AND s.service_category = 'Grooming & Hygiene'
       )
     ORDER BY (a.appointment_date >= CURRENT_DATE) DESC,
              CASE WHEN a.appointment_date >= CURRENT_DATE THEN a.appointment_date END ASC,
              a.appointment_date DESC"
);
$appointmentStatement->execute($appointmentParameters);
$appointments = $appointmentStatement->fetchAll();
$readyCount = 0;
$waitingCount = 0;
foreach ($appointments as $appointment) {
    if ($appointment['picked_up_at'] !== null) {
        continue;
    } elseif ($appointment['pickup_ready_at'] !== null) {
        $readyCount++;
    } else {
        $waitingCount++;
    }
}

$groomerMenuActive = $isGroomerAccount ? ['appointments'] : ['grooming'];
$pageTitle = $isGroomerAccount ? 'Appointments' : 'Grooming';
$pageSubtitle = $isGroomerAccount ? 'Your assigned grooming appointments' : 'Manage grooming services and let owners know when pets are ready';
role_render('staff', $groomerMenuActive, $pageTitle, $pageSubtitle, function (array $context) use ($services, $appointments, $groomers, $currentGroomer, $currentGroomerId, $readyCount, $waitingCount, $error, $success, $isGroomerAccount): void {
    ?>
    <style>
      .groomer-pet-cell{min-width:150px;white-space:nowrap}
      .groomer-pet-thumb{width:38px;height:38px;margin-right:8px;display:inline-block;vertical-align:middle;object-fit:cover;border:1px solid #d7e8f5;border-radius:50%}
      .groomer-pet-copy{max-width:calc(100% - 54px);display:inline-block;vertical-align:middle;white-space:normal}
      .groomer-pet-copy small{display:block;opacity:.75}
      .groomer-roster-form{max-width:760px;display:grid;grid-template-columns:minmax(180px,1fr) auto;align-items:end;gap:12px}
      .groomer-roster-list{margin:12px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:8px;list-style:none}
      .groomer-roster-list li{padding:6px 10px;color:#315d7c;background:#eef6fc;border:1px solid #d7e8f5;border-radius:8px;font-size:.82rem}
      @media(max-width:700px){.groomer-roster-form{grid-template-columns:1fr}.groomer-roster-form .button{width:100%}}
    </style>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice success" role="status"><?= e($success) ?></div><?php endif; ?>

    <section class="stat-grid" aria-label="Grooming summaries">
      <article class="stat-card" style="--accent:#0284c7"><span class="stat-label">Appointments</span><strong class="stat-value"><?= count($appointments) ?></strong></article>
      <article class="stat-card" style="--accent:#b67815"><span class="stat-label">Awaiting pickup-ready update</span><strong class="stat-value"><?= $waitingCount ?></strong></article>
      <article class="stat-card" style="--accent:#008b78"><span class="stat-label">Ready for pickup</span><strong class="stat-value"><?= $readyCount ?></strong></article>
    </section>

    <?php if ($isGroomerAccount && !$currentGroomer): ?>
      <div class="notice error" role="alert">Your groomer profile is not linked or available. Ask clinic staff for help.</div>
    <?php elseif (!$isGroomerAccount && $currentGroomer): ?>
      <div id="my-grooming-schedule">
        <?php $GLOBALS['groomer_schedule_embedded'] = true; require __DIR__ . '/groomer_schedule.php'; unset($GLOBALS['groomer_schedule_embedded']); ?>
      </div>
    <?php elseif (!$isGroomerAccount): ?>
      <section class="panel">
        <div class="panel-header"><h2>Your groomer profile</h2><span class="data-count">Not set up</span></div>
        <div class="panel-body">
          <p class="hint">This staff login is not linked to a groomer profile yet. Create a profile for your own account to request hours or be assigned to appointments.</p>
          <form method="post" class="groomer-roster-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_groomer">
            <label class="field">Your contact number<input type="tel" name="contact_number" maxlength="20"></label>
            <button class="button" type="submit">Create my groomer profile</button>
          </form>
        </div>
      </section>
    <?php endif; ?>

    <?php if (!$isGroomerAccount): ?><section class="panel">
      <div class="panel-header"><h2>Grooming services</h2><span class="data-count"><?= count($services) ?> active</span></div>
      <div class="table-wrap data-table-card">
        <table class="records-table">
          <thead><tr><th>Service</th><th>Details</th><th>Duration</th><th>Price</th></tr></thead>
          <tbody>
            <?php foreach ($services as $service): ?>
              <tr>
                <td data-label="Service"><strong><?= e($service['service_name']) ?></strong></td>
                <td data-label="Details"><?= e($service['description'] ?: 'Grooming service') ?></td>
                <td data-label="Duration"><?= (int) $service['duration_minutes'] ?> min</td>
                <td data-label="Price">₱<?= number_format((float) $service['base_price'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$services): ?>
              <tr class="empty-row"><td colspan="4"><div class="empty-state-box"><strong>No grooming services are configured</strong><small>Apply the clinic grooming-services database migration and reload this page.</small></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section><?php endif; ?>

    <section class="panel">
      <div class="panel-header"><h2>Grooming appointments</h2><span class="data-count"><?= count($appointments) ?> visits</span></div>
      <?php if ($isGroomerAccount): ?>
        <div class="panel-body table-search-toolbar">
          <label class="field">Search grooming visits
            <input type="search" id="groomer-appointment-search" placeholder="Pet, owner, service or status" autocomplete="off">
          </label>
          <span class="table-search-count" id="groomer-appointment-search-count" aria-live="polite"></span>
        </div>
        <div class="table-wrap data-table-card">
          <table class="records-table groomer-appointments-table">
            <colgroup><col style="width:15%"><col style="width:19%"><col style="width:17%"><col style="width:23%"><col style="width:12%"><col style="width:14%"></colgroup>
            <thead><tr><th>Date &amp; time</th><th>Pet</th><th>Owner</th><th>Services</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="groomer-appointment-rows">
              <?php foreach ($appointments as $appointment): ?>
                <?php
                  $timestamp = strtotime((string) $appointment['appointment_date']);
                  $dateKey = date('Y-m-d', $timestamp);
                  $todayKey = date('Y-m-d');
                  $tomorrowKey = date('Y-m-d', strtotime('+1 day'));
                  $dayTag = $dateKey === $todayKey ? 'Today' : ($dateKey === $tomorrowKey ? 'Tomorrow' : '');
                  $status = (string) $appointment['status'];
                  $pickedUp = $appointment['picked_up_at'] !== null;
                  $pickupReady = $appointment['pickup_ready_at'] !== null && !$pickedUp;
                  $canStart = $dateKey === $todayKey
                      && $status === 'Check-in'
                      && !$pickupReady && !$pickedUp;
                  $canMarkReady = !$pickupReady && !$pickedUp
                      && in_array($status, ['Check-in', 'Waiting', 'In Consultation', 'Complete'], true)
                      && $appointment['appointment_date'] <= date('Y-m-d H:i:s');
                  $petLine = trim((string) $appointment['species'] . (!empty($appointment['breed']) ? ' · ' . $appointment['breed'] : ''));
                  $photoUrl = pet_photo_url($appointment['photo_updated_at'] ?? null, (int) $appointment['pet_id']);
                ?>
                <tr data-groomer-appointment>
                  <td class="groomer-appt-when" data-label="Date &amp; time">
                    <?php if ($dayTag !== ''): ?><span class="groomer-appt-day"><?= e($dayTag) ?></span><?php endif; ?>
                    <strong><?= e(date('M j, Y', $timestamp)) ?></strong>
                    <small><?= e(date('g:i A', $timestamp)) ?> · <?= (int) $appointment['duration_minutes'] ?> min</small>
                  </td>
                  <td class="groomer-pet-cell" data-label="Pet">
                    <?php if ($photoUrl !== ''): ?>
                      <img class="groomer-pet-thumb" src="<?= e($photoUrl) ?>" alt="Photo of <?= e($appointment['pet_name']) ?>" loading="lazy">
                    <?php else: ?>
                      <span class="groomer-pet-thumb" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($appointment['pet_name'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <span class="groomer-pet-copy"><strong><?= e($appointment['pet_name']) ?></strong><?php if ($petLine !== ''): ?><small><?= e($petLine) ?></small><?php endif; ?></span>
                  </td>
                  <td data-label="Owner"><?= e($appointment['owner_name']) ?></td>
                  <td class="groomer-service-cell" data-label="Services">
                    <span class="groomer-service-preview"><?= e($appointment['service_names'] ?: '—') ?></span>
                    <button class="button secondary small groomer-service-view-all" type="button" data-view-services="<?= e($appointment['service_names'] ?: '—') ?>">View all</button>
                  </td>
                  <td data-label="Status"><span class="status <?= e(role_status_class($status)) ?>"><?= e($status) ?></span></td>
                  <td data-label="Actions">
                    <div class="groomer-appt-actions">
                      <a class="button secondary small" href="pets.php?pet=<?= (int) $appointment['pet_id'] ?>">History</a>
                      <?php if ($canStart): ?>
                        <form method="post">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="start_grooming">
                          <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                          <button class="button small" type="submit">Start</button>
                        </form>
                      <?php elseif ($pickedUp): ?>
                        <span class="status complete">Picked up</span>
                        <small><?= e(date('M j, g:i A', strtotime((string) $appointment['picked_up_at']))) ?></small>
                      <?php elseif ($pickupReady): ?>
                        <span class="status pickup-ready-status">Ready for pickup</span>
                        <small><?= e(date('M j, g:i A', strtotime((string) $appointment['pickup_ready_at']))) ?></small>
                      <?php elseif ($canMarkReady): ?>
                        <form method="post">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="mark_ready">
                          <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                          <button class="button small" type="submit">Mark ready</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr class="empty-row" id="groomer-appointment-empty" <?= $appointments ? 'hidden' : '' ?>>
                <td colspan="6">
                  <div class="empty-state-box">
                    <strong><?= $appointments ? 'No appointments match' : 'No assigned appointments yet' ?></strong>
                    <small><?= $appointments ? 'Try a different search term.' : 'Grooming appointments assigned to you will appear here.' ?></small>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <dialog class="groomer-services-dialog" id="groomer-services-dialog" aria-labelledby="groomer-services-title">
          <h2 id="groomer-services-title">Grooming services</h2>
          <p id="groomer-services-content"></p>
          <form method="dialog"><button class="button secondary" type="submit">Close</button></form>
        </dialog>
      <?php else: ?>
        <div class="panel-body table-search-toolbar">
          <label class="field">Search grooming visits
            <input type="search" data-table-search placeholder="Pet, owner, service or status" autocomplete="off">
          </label>
          <span class="table-search-count" data-table-search-count aria-live="polite"></span>
        </div>
        <div class="table-wrap data-table-card">
          <table class="records-table">
            <thead><tr><th>Date &amp; time</th><th>Pet</th><th>Owner</th><th>Services</th><th>Groomer</th><th>Status</th><th>Pickup</th></tr></thead>
            <tbody>
              <?php foreach ($appointments as $appointment): ?>
                <?php
                  $timestamp = strtotime((string) $appointment['appointment_date']);
                  $status = (string) $appointment['status'];
                  $pickedUp = $appointment['picked_up_at'] !== null;
                  $pickupReady = $appointment['pickup_ready_at'] !== null && !$pickedUp;
                  $canMarkReady = !$pickupReady && !$pickedUp
                      && in_array($status, ['Check-in', 'Waiting', 'In Consultation', 'Complete'], true)
                      && $appointment['appointment_date'] <= date('Y-m-d H:i:s');
                  $petLine = trim((string) $appointment['species'] . (!empty($appointment['breed']) ? ' · ' . $appointment['breed'] : ''));
                  $searchText = mb_strtolower(implode(' ', [
                      $appointment['pet_name'], $appointment['owner_name'], $appointment['service_names'] ?? '',
                      $status, date('M j Y g:i A', $timestamp), $petLine,
                  ]));
                  $photoUrl = pet_photo_url($appointment['photo_updated_at'] ?? null, (int) $appointment['pet_id']);
                ?>
                <tr data-search-item data-search="<?= e($searchText) ?>">
                  <td data-label="Date &amp; time"><strong><?= e(date('M j, Y', $timestamp)) ?></strong><small><?= e(date('g:i A', $timestamp)) ?></small></td>
                  <td class="groomer-pet-cell" data-label="Pet">
                    <?php if ($photoUrl !== ''): ?>
                      <img class="groomer-pet-thumb" src="<?= e($photoUrl) ?>" alt="Photo of <?= e($appointment['pet_name']) ?>" loading="lazy">
                    <?php else: ?>
                      <span class="groomer-pet-thumb" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($appointment['pet_name'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <span class="groomer-pet-copy"><strong><?= e($appointment['pet_name']) ?></strong><?php if ($petLine !== ''): ?><small><?= e($petLine) ?></small><?php endif; ?></span>
                  </td>
                  <td data-label="Owner"><?= e($appointment['owner_name']) ?></td>
                  <td data-label="Services"><?= e($appointment['service_names'] ?: '—') ?></td>
                  <td data-label="Groomer">
                    <?php if (!empty($appointment['groomer_names'])): ?><strong><?= e($appointment['groomer_names']) ?></strong><?php else: ?><span class="hint">Not assigned</span><?php endif; ?>
                    <?php if ($groomers && !$pickupReady && !$pickedUp): ?>
                      <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="assign_groomer">
                        <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                        <label class="field">Assign groomer
                          <select name="groomer_id" required>
                            <option value="">Choose groomer</option>
                            <?php foreach ($groomers as $groomer): ?>
                              <option value="<?= (int) $groomer['groomer_id'] ?>" <?= (string) ($appointment['assigned_groomer_id'] ?? '') === (string) $groomer['groomer_id'] ? 'selected' : '' ?>><?= e($groomer['full_name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <button class="button secondary small" type="submit">Save</button>
                      </form>
                    <?php elseif (!$groomers && !$pickupReady && !$pickedUp): ?>
                      <small class="hint">No available groomers are set up.</small>
                    <?php endif; ?>
                  </td>
                  <td data-label="Status"><span class="status <?= e(role_status_class($status)) ?>"><?= e($status) ?></span></td>
                  <td data-label="Pickup">
                    <?php if ($pickedUp): ?>
                      <span class="status complete">Picked up</span>
                      <small><?= e(date('M j, g:i A', strtotime((string) $appointment['picked_up_at']))) ?></small>
                    <?php elseif ($pickupReady): ?>
                      <span class="status pickup-ready-status">Ready for pickup</span>
                      <small><?= e(date('M j, g:i A', strtotime((string) $appointment['pickup_ready_at']))) ?></small>
                    <?php elseif ($canMarkReady): ?>
                      <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_ready">
                        <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                        <button class="button small" type="submit">Mark ready</button>
                      </form>
                    <?php else: ?>
                      <span class="hint">Not ready</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$appointments): ?>
                <tr class="empty-row"><td colspan="7"><div class="empty-state-box"><strong>No grooming appointments yet</strong><small>Appointments containing an active grooming service will appear here.</small></div></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php if ($isGroomerAccount): ?>
      <style>
        .groomer-appointments-table{min-width:880px;table-layout:fixed}
        .groomer-appt-when strong,.groomer-appt-when small{display:block}
        .groomer-appt-day{display:inline-block;margin-bottom:.2rem;font-size:.75rem;font-weight:700}
        .groomer-service-preview{display:block;max-width:30ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .groomer-service-view-all{margin-top:4px}
        .groomer-appt-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem}
        .groomer-appt-actions form{margin:0}
        .groomer-services-dialog{width:min(36rem,calc(100vw - 2rem));max-height:80vh;padding:1.25rem;color:var(--ink);border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 50px rgba(0,46,74,.25)}
        .groomer-services-dialog::backdrop{background:rgba(0,28,46,.55)}
        .groomer-services-dialog h2{margin:0 0 .75rem;font-size:1.1rem}
        .groomer-services-dialog p{max-height:55vh;margin:0 0 1rem;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}
        .groomer-services-dialog form{display:flex;justify-content:flex-end}
      </style>
      <script>
        (function () {
          var search = document.getElementById('groomer-appointment-search');
          var rows = Array.prototype.slice.call(document.querySelectorAll('[data-groomer-appointment]'));
          var count = document.getElementById('groomer-appointment-search-count');
          var emptyRow = document.getElementById('groomer-appointment-empty');
          var servicesDialog = document.getElementById('groomer-services-dialog');
          var servicesContent = document.getElementById('groomer-services-content');
          var emptyTitle = emptyRow.querySelector('strong');
          var emptyText = emptyRow.querySelector('small');

          search.addEventListener('input', function () {
            var query = search.value.trim().toLocaleLowerCase();
            var visible = 0;
            rows.forEach(function (row) {
              var matches = !query || row.textContent.toLocaleLowerCase().indexOf(query) !== -1;
              row.hidden = !matches;
              if (matches) visible++;
            });
            emptyRow.hidden = rows.length > 0 && visible > 0;
            emptyTitle.textContent = rows.length > 0 ? 'No appointments match' : 'No assigned appointments yet';
            emptyText.textContent = rows.length > 0
              ? 'Try a different search term.'
              : 'Grooming appointments assigned to you will appear here.';
            count.textContent = query ? visible + ' matching visits' : '';
          });

          document.querySelectorAll('[data-view-services]').forEach(function (button) {
            button.addEventListener('click', function () {
              servicesContent.textContent = button.dataset.viewServices || '—';
              servicesDialog.showModal();
            });
          });
        })();
      </script>
    <?php endif; ?>
    <?php
});