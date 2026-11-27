<?php
// client/client_layout.php - shared shell for every pet-owner (client) page.
// Same sidebar + top bar + panels as the admin pages (see reports.php).
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pet_photos.php';
require_once __DIR__ . '/../includes/notifications.php';

/* ---------- EDIT THIS: clinic details + booking rules ---------- */
const CLINIC_INFO = [
    'name'    => 'Animal Clinic',
    'address' => 'Bicol University College of Science (BUCS)',
    'phone'   => '+63 900 000 0000',
    'email'   => 'hello@animalclinic.example',
];
const SLOT_INTERVAL_MINUTES = 30;
const LEAD_MINUTES = 60;      // earliest booking = now + this
const ACTIVE_STATUSES = ['Pending', 'Scheduled', 'Waiting', 'Check-in', 'In Consultation'];
const SPECIES = ['Dog', 'Cat', 'Rabbit', 'Bird', 'Hamster', 'Other'];

/* ---------- Helpers ---------- */
function service_price(array $s): float
{
    foreach (['price', 'service_price', 'base_price', 'fee', 'cost'] as $k) {
        if (isset($s[$k]) && is_numeric($s[$k])) {
            return (float) $s[$k];
        }
    }
    return 0.0;
}

function pick(array $row, array $keys, $default = '')
{
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

function pet_age(?string $birth): string
{
    if (!$birth || $birth === '0000-00-00') {
        return '';
    }
    try {
        $d = (new DateTime($birth))->diff(new DateTime('today'));
    } catch (Throwable $e) {
        return '';
    }
    if ($d->y >= 1) {
        return $d->y . ($d->y === 1 ? ' yr' : ' yrs');
    }
    return $d->m >= 1 ? $d->m . ($d->m === 1 ? ' mo' : ' mos') : 'Under 1 mo';
}

function status_class($v): string
{
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $v), '_'));
}

function client_notification_href(?string $link): string
{
    $routes = [
        'client/client_dashboard.php' => 'client_dashboard.php',
        'client/book_appointment.php' => 'book_appointment.php',
        'client/appointments.php' => 'appointments.php',
        'client/my_pets.php' => 'my_pets.php',
        'client/vaccinations.php' => 'vaccinations.php',
        'client/medical_records.php' => 'medical_records.php',
        'client/billing.php' => 'billing.php',
        'client/client_settings.php' => 'client_settings.php',
    ];
    return $routes[$link ?? ''] ?? 'client_dashboard.php';
}

function qall(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $ex) {
        error_log('client query: ' . $ex->getMessage());
        return [];
    }
}

const APPT_SQL = "SELECT a.appointment_id, a.appointment_date, a.status, a.pickup_ready_at, a.picked_up_at, a.client_notes,
        a.payment_timing_preference, a.preferred_payment_method, p.pet_name, v.full_name AS vet_name,
        (SELECT GROUP_CONCAT(DISTINCT g.full_name ORDER BY g.full_name SEPARATOR ', ')
           FROM AppointmentServices aps
           JOIN Services s ON s.service_id = aps.service_id
           JOIN Groomers g ON g.groomer_id = aps.groomer_id
          WHERE aps.appointment_id = a.appointment_id
            AND s.service_category = 'Grooming & Hygiene') AS groomer_names,
        (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
           FROM AppointmentServices aps
           JOIN Services s ON s.service_id = aps.service_id
          WHERE aps.appointment_id = a.appointment_id
            AND s.service_category = 'Grooming & Hygiene') AS grooming_service_names,
        (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
           FROM AppointmentServices aps
           JOIN Services s ON s.service_id = aps.service_id
          WHERE aps.appointment_id = a.appointment_id
            AND s.service_category <> 'Grooming & Hygiene') AS veterinary_service_names,
        (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
           FROM AppointmentServices aps JOIN Services s ON s.service_id = aps.service_id
          WHERE aps.appointment_id = a.appointment_id) AS service_names
    FROM Appointments a
    JOIN Pets p ON p.pet_id = a.pet_id
    LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
    WHERE p.owner_id = ? AND a.archived_at IS NULL AND ";

function client_upcoming(PDO $pdo, int $owner, int $limit = 20, ?string $serviceType = null): array
{
    $serviceFilter = '';
    $pickupReadyCondition = "(a.pickup_ready_at IS NOT NULL AND a.picked_up_at IS NULL
                              AND a.status NOT IN ('Cancelled','Rejected'))";
    $pickedUpFilter = 'AND a.picked_up_at IS NULL';
    if ($serviceType === 'grooming') {
        $serviceFilter = "AND EXISTS (
            SELECT 1 FROM AppointmentServices aps
            JOIN Services s ON s.service_id = aps.service_id
            WHERE aps.appointment_id = a.appointment_id
              AND s.service_category = 'Grooming & Hygiene'
        )
        AND NOT EXISTS (
            SELECT 1 FROM AppointmentServices aps
            JOIN Services s ON s.service_id = aps.service_id
            WHERE aps.appointment_id = a.appointment_id
              AND s.service_category = 'Grooming & Hygiene'
              AND aps.groomer_id IS NULL
        )
        AND NOT EXISTS (
            SELECT 1
            FROM Appointments vet_appointment
            JOIN AppointmentServices vet_aps ON vet_aps.appointment_id = vet_appointment.appointment_id
            JOIN Services vet_service ON vet_service.service_id = vet_aps.service_id
            WHERE vet_appointment.pet_id = a.pet_id
              AND vet_appointment.appointment_id <> a.appointment_id
              AND vet_appointment.appointment_date = a.appointment_date
              AND vet_appointment.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation')
              AND vet_service.service_category <> 'Grooming & Hygiene'
        )";
    } elseif ($serviceType === 'veterinary') {
        $serviceFilter = "AND EXISTS (
            SELECT 1 FROM AppointmentServices aps
            JOIN Services s ON s.service_id = aps.service_id
            WHERE aps.appointment_id = a.appointment_id
              AND s.service_category <> 'Grooming & Hygiene'
        )";
        $pickupReadyCondition = "(a.pickup_ready_at IS NOT NULL
                                  AND a.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation'))";
        $pickedUpFilter = '';
    } elseif ($serviceType !== null) {
        throw new InvalidArgumentException('Choose a valid appointment service type.');
    }

    return qall($pdo, APPT_SQL . "(
                                      (a.appointment_date >= CURRENT_DATE
                                       AND a.status IN ('Pending','Scheduled','Waiting','Check-in','In Consultation'))
                                      OR $pickupReadyCondition
                                  )
                                  $pickedUpFilter
                                  $serviceFilter
                                  ORDER BY a.appointment_date ASC LIMIT " . (int) $limit, [$owner]);
}

function client_past(PDO $pdo, int $owner, int $limit = 50, ?string $serviceType = null): array
{
    $serviceFilter = '';
    $pickupHistoryCondition = $serviceType === 'grooming' ? 'a.picked_up_at IS NOT NULL OR ' : '';
    $pickupReadyPastFilter = $serviceType === 'veterinary' ? '' : 'a.pickup_ready_at IS NULL AND ';
    if ($serviceType === 'grooming') {
        $serviceFilter = "AND EXISTS (
            SELECT 1 FROM AppointmentServices aps
            JOIN Services s ON s.service_id = aps.service_id
            WHERE aps.appointment_id = a.appointment_id
              AND s.service_category = 'Grooming & Hygiene'
        )
        AND NOT EXISTS (
            SELECT 1 FROM AppointmentServices aps
            JOIN Services s ON s.service_id = aps.service_id
            WHERE aps.appointment_id = a.appointment_id
              AND s.service_category = 'Grooming & Hygiene'
              AND aps.groomer_id IS NULL
        )";
    } elseif ($serviceType === 'veterinary') {
        $serviceFilter = "AND EXISTS (
            SELECT 1 FROM AppointmentServices aps
            JOIN Services s ON s.service_id = aps.service_id
            WHERE aps.appointment_id = a.appointment_id
              AND s.service_category <> 'Grooming & Hygiene'
        )";
    } elseif ($serviceType !== null) {
        throw new InvalidArgumentException('Choose a valid appointment service type.');
    }

    return qall($pdo, APPT_SQL . "(
                                      $pickupHistoryCondition
                                      a.status IN ('Cancelled','Rejected')
                                      OR (
                                          $pickupReadyPastFilter
                                          (a.appointment_date < CURRENT_DATE OR a.status = 'Complete')
                                          AND a.status NOT IN ('Pending','Scheduled','Waiting','Check-in','In Consultation')
                                      )
                                  )
                                  $serviceFilter
                                  ORDER BY " . ($serviceType === 'grooming' ? 'COALESCE(a.picked_up_at, a.appointment_date)' : 'a.appointment_date') . " DESC LIMIT " . (int) $limit, [$owner]);
}

function client_unpaid(PDO $pdo, int $owner): array
{
    return qall($pdo,
        "SELECT a.appointment_id, a.appointment_date, p.pet_name, c.service_names, c.total_charged, COALESCE(pay.total_paid,0) AS total_paid
         FROM Appointments a
         JOIN Pets p ON p.pet_id = a.pet_id
         JOIN (SELECT aps.appointment_id, SUM(aps.charged_price) AS total_charged,
                      GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ') AS service_names
                 FROM AppointmentServices aps JOIN Services s ON s.service_id = aps.service_id GROUP BY aps.appointment_id) c
              ON c.appointment_id = a.appointment_id
         LEFT JOIN (SELECT appointment_id, SUM(amount_paid) AS total_paid FROM Payments
                    WHERE payment_status = 'Recorded' GROUP BY appointment_id) pay
              ON pay.appointment_id = a.appointment_id
         WHERE p.owner_id = ? AND a.status = 'Complete' AND c.total_charged > COALESCE(pay.total_paid,0)
         ORDER BY a.appointment_date DESC", [$owner]);
}

function appt_table(array $rows, bool $cancel = false, string $tableId = '', string $emptyId = '', ?string $serviceType = null): void
{
  $showGroomer = $serviceType !== 'veterinary';
  $showVeterinarian = $serviceType !== 'grooming';
  $hasBothProviders = $showGroomer && $showVeterinarian;
  $columnCount = 5 + (int) $showGroomer + (int) $showVeterinarian + ($cancel ? 1 : 0);
  $columnWidths = $cancel
      ? ($hasBothProviders
          ? '<col style="width:15%"><col style="width:9%"><col style="width:14%"><col style="width:11%"><col style="width:14%"><col style="width:14%"><col style="width:11%"><col style="width:12%">'
          : '<col style="width:16%"><col style="width:9%"><col style="width:17%"><col style="width:15%"><col style="width:16%"><col style="width:12%"><col style="width:15%">')
      : ($hasBothProviders
          ? '<col style="width:16%"><col style="width:10%"><col style="width:15%"><col style="width:12%"><col style="width:16%"><col style="width:18%"><col style="width:13%">'
          : '<col style="width:17%"><col style="width:13%"><col style="width:23%"><col style="width:17%"><col style="width:19%"><col style="width:11%">');
    echo '<div class="table-wrap appointment-table-wrap data-table-card"><table class="records-table appointment-table' . ($cancel ? ' has-actions' : '') . '"><colgroup>'
        . $columnWidths
        . '</colgroup><thead><tr>'
    . '<th>Date &amp; time</th><th>Pet</th><th>Service</th>'
        . ($showGroomer ? '<th>Groomer</th>' : '')
        . ($showVeterinarian ? '<th>Veterinarian</th>' : '')
        . '<th>Payment preference</th><th class="col-center">Status</th>'
        . ($cancel ? '<th class="col-center">Action</th>' : '')
        . '</tr></thead><tbody' . ($tableId !== '' ? ' id="' . e($tableId) . '"' : '') . '>';
    if (!$rows) {
        echo '<tr class="appointment-empty-row"><td colspan="' . $columnCount . '"><div class="appointment-empty-state">'
            . ($cancel
                ? '<strong>No upcoming appointments</strong><span>Book a visit and choose from the open time slots.</span><a class="button small" href="book_appointment.php">Book a visit</a>'
                : '<strong>No past ' . ($serviceType === 'grooming' ? 'grooming ' : ($serviceType === 'veterinary' ? 'veterinary ' : '')) . 'visits yet</strong><span>Completed and cancelled visits will appear here.</span>')
            . '</div></td></tr>';
    }
    foreach ($rows as $a) {
        $serviceNames = $serviceType === 'grooming'
            ? ($a['grooming_service_names'] ?? '')
            : ($serviceType === 'veterinary' ? ($a['veterinary_service_names'] ?? '') : ($a['service_names'] ?? ''));
        $serviceNames = $serviceNames ?: 'To be confirmed';
        $hasGroomingServices = !empty($a['grooming_service_names']);
        $pickedUp = !$cancel && $serviceType === 'grooming' && $hasGroomingServices && !empty($a['picked_up_at']);
        $pickupReady = $serviceType === 'grooming' && $hasGroomingServices && !empty($a['pickup_ready_at']) && empty($a['picked_up_at']);
        $appointmentTimestamp = strtotime($pickedUp ? $a['picked_up_at'] : $a['appointment_date']);
        echo '<tr data-search-item><td class="appointment-datetime-cell" data-label="Date &amp; time"><span class="appointment-datetime-date">' . e(date('M j, Y', $appointmentTimestamp))
          . '</span><time datetime="' . e(date('c', $appointmentTimestamp)) . '">' . e(date('g:i A', $appointmentTimestamp)) . '</time>'
          . ($pickedUp ? '<small class="pickup-ready-time">Picked up</small>' : '') . '</td><td data-label="Pet">' . e($a['pet_name'])
          . '</td><td data-label="Service"><span class="appointment-service-preview">' . e($serviceNames) . '</span>'
          . '<button class="button secondary small appointment-service-view-all" type="button" data-view-services="' . e($serviceNames) . '">View all</button></td>'
          . ($showGroomer ? '<td class="appointment-groomer-cell" data-label="Groomer">' . e($a['groomer_names'] ?: 'Not assigned') . '</td>' : '')
          . ($showVeterinarian ? '<td data-label="Veterinarian">' . e($a['vet_name'] ?: 'To be assigned') . '</td>' : '')
            . '<td data-label="Payment preference">' . (!empty($a['payment_timing_preference'])
                ? e($a['payment_timing_preference'] === 'Advance request' ? 'Advance instructions requested' : 'Pay at clinic')
                    . (!empty($a['preferred_payment_method']) ? '<br><span class="hint">' . e($a['preferred_payment_method']) . '</span>' : '')
                : 'Not specified')
            . '</td><td class="col-center" data-label="Status">';
          if ($pickedUp) {
            echo '<span class="status complete">Complete</span>';
          } elseif ($pickupReady) {
            echo '<span class="status pickup-ready-status">Ready for pickup</span>';
            echo '<small class="pickup-ready-services">Services: ' . e($a['grooming_service_names']) . '</small>';
            echo '<small class="pickup-ready-time">' . e(date('M j, g:i A', strtotime($a['pickup_ready_at']))) . '</small>';
          } else {
            echo '<span class="status ' . e(status_class($a['status'])) . '">' . e($a['status']) . '</span>';
          }
          echo '</td>';
        if ($cancel) {
            echo '<td class="col-center" data-label="Action">';
            if ($pickupReady) {
                echo '<form method="post" action="appointments.php" onsubmit="return confirm(\'Confirm that you picked up this pet?\')">'
                    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="confirm_pickup">'
                    . '<input type="hidden" name="appointment_id" value="' . (int) $a['appointment_id'] . '"><button class="button secondary small pickup-confirm-button" type="submit">Confirm pickup</button></form>';
            } elseif (in_array($a['status'], ['Pending', 'Scheduled'], true)) {
                echo '<form method="post" action="appointments.php" onsubmit="return confirm(\'Cancel this appointment?\')">'
                    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="cancel">'
                    . '<input type="hidden" name="appointment_id" value="' . (int) $a['appointment_id'] . '"><button class="button secondary small" type="submit">Cancel</button></form>';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    if ($rows && $emptyId !== '') {
        echo '<tr class="empty-row" id="' . e($emptyId) . '" data-search-empty hidden><td colspan="' . $columnCount
            . '"><div class="empty-state-box"><strong>No appointments match your search</strong><small>Try another pet, service or status.</small></div></td></tr>';
    }
    echo '</tbody></table></div>';
}

const FLASH = [
    'booked'    => 'Visit requested. The clinic will confirm it shortly.',
    'cancelled' => 'Your appointment was cancelled.',
    'petsaved'  => 'Pet saved.',
    'saved'     => 'Your changes were saved.',
    'welcome'   => 'Thanks, your name is saved.',
];

/* ---------- Bootstrap: auth, account, owner record, first-login name ---------- */
function client_boot(): array
{
    require_role(['client']);
    $c = ['pdo' => null, 'userId' => (int) $_SESSION['user_id'], 'ownerId' => 0, 'fullName' => '', 'dbError' => '', 'nameError' => ''];
    try {
        $pdo = db();
        $c['pdo'] = $pdo;
        $st = $pdo->prepare('SELECT full_name, owner_id FROM users WHERE user_id = ? LIMIT 1');
        $st->execute([$c['userId']]);
        $account = $st->fetch();
        if (!$account) {
            header('Location: ../logout.php?session_context=client');
            exit;
        }
        $c['fullName'] = trim((string) $account['full_name']);
        $c['ownerId'] = (int) ($account['owner_id'] ?? 0);
        if ($c['ownerId'] === 0) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO petowners (full_name, email) VALUES (?, ?)')->execute([$c['fullName'], (string) ($_SESSION['email'] ?? '')]);
            $c['ownerId'] = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE users SET owner_id = ? WHERE user_id = ?')->execute([$c['ownerId'], $c['userId']]);
            $pdo->commit();
        }
    } catch (Throwable $ex) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('client boot: ' . $ex->getMessage());
        $c['dbError'] = 'We could not load your account right now. Please try again in a moment.';
        return $c;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && posted_string('action') === 'save_name') {
        $newName = trim(preg_replace('/\s+/', ' ', posted_string('full_name')));
        if (!csrf_valid(posted_string('csrf'))) {
            $c['nameError'] = 'Your session expired. Please try again.';
        } elseif (mb_strlen($newName) < 2 || mb_strlen($newName) > 100 || !valid_full_name($newName)) {
            $c['nameError'] = 'Enter your full name using letters (2 to 100 characters).';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET full_name = ? WHERE user_id = ?')->execute([$newName, $c['userId']]);
                $pdo->prepare('UPDATE petowners SET full_name = ? WHERE owner_id = ?')->execute([$newName, $c['ownerId']]);
                $pdo->commit();
                $_SESSION['full_name'] = $newName;
                header('Location: ' . basename($_SERVER['PHP_SELF']) . '?ok=welcome');
                exit;
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('client name save: ' . $ex->getMessage());
                $c['nameError'] = 'Your name could not be saved. Please try again.';
            }
        }
    }
    return $c;
}

function client_search(string $inputId, string $label, string $placeholder, string $targetId, string $countId, string $emptyId): void
{
    ?>
    <div class="toolbar client-search" role="search">
      <label for="<?= e($inputId) ?>"><?= e($label) ?><input type="search" id="<?= e($inputId) ?>" placeholder="<?= e($placeholder) ?>" maxlength="100" autocomplete="off"
        data-live-search data-search-target="<?= e($targetId) ?>" data-search-count="<?= e($countId) ?>" data-search-empty="<?= e($emptyId) ?>"></label>
      <span class="search-count" id="<?= e($countId) ?>" aria-live="polite"></span>
    </div>
    <?php
}

/* ---------- Page shell ---------- */
function client_header(array $c, string $title, string $subtitle, string $active): void
{
    $nav = [
        'home'         => ['client_dashboard.php', 'Home', '<path d="M3 10.8 12 3l9 7.8v9a1.2 1.2 0 0 1-1.2 1.2h-5.3v-6.2h-5V21H4.2A1.2 1.2 0 0 1 3 19.8z"/>'],
        'book'         => ['book_appointment.php', 'Book a Visit', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M12 13v5m-2.5-2.5h5"/>'],
        'pets'         => ['my_pets.php', 'My Pets', '<path d="M12 21s-7-4.4-9.3-9A5.2 5.2 0 0 1 12 6.5 5.2 5.2 0 0 1 21.3 12C19 16.6 12 21 12 21z"/>'],
        'appointments' => ['appointments.php', 'Appointments', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M7 14h3m-3 3h3m4-3h3"/>'],
        'medical'      => ['medical_records.php', 'Medical History', '<path d="M4 5h16v16H4zM8 3v4M16 3v4M12 9v8M8 13h8"/>'],
        'billing'      => ['billing.php', 'Billing', '<path d="M3 7h18v10H3zM3 11h18M7 15h3"/>'],
        'settings'     => ['client_settings.php', 'Settings', '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'],
    ];
    $name = $c['fullName'] !== '' ? $c['fullName'] : 'Pet owner';
    $notificationCount = 0;
    $notificationItems = [];
    $notificationError = '';
    if (!$c['dbError'] && $c['pdo'] instanceof PDO) {
        try {
            $notificationCount = notification_unread_count($c['pdo'], (int) $c['userId']);
            foreach (notification_recent($c['pdo'], (int) $c['userId'], 8) as $row) {
                $notificationItems[] = [
                    'id' => (int) $row['notification_id'],
                    'label' => (string) $row['title'],
                    'detail' => (string) $row['message'],
                    'href' => client_notification_href($row['link'] !== null ? (string) $row['link'] : null),
                    'kind' => preg_match('/\A[a-z0-9_-]{1,30}\z/i', (string) $row['category']) ? strtolower((string) $row['category']) : 'general',
                    'count' => 0,
                ];
            }
        } catch (PDOException $ex) {
            error_log('Client notifications could not load: ' . $ex->getMessage());
            $notificationError = 'Notifications are temporarily unavailable.';
        }
    }
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($title) ?> | <?= e(CLINIC_INFO['name']) ?></title>
  <link rel="icon" href="../assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png">
  <script src="../assets/JS/admin-sidebar-state.js" data-role="client"></script>
  <style>
    :root{--blue-50:#eaf3fa;--blue-100:#c2dcf1;--blue-300:#81b7e0;--blue-500:#0284c7;--blue-700:#015b8c;--blue-900:#002e4a;--ink:#092947;--muted:#42637e;--line:#d7e8f5;--grid-line:#d3e3ef;--grid-strong:#8fbadb}
    *{box-sizing:border-box}html,body{width:100%;height:100%;margin:0}
    html{scroll-behavior:smooth}
    body.client-app{min-width:320px;height:100vh;height:100dvh;overflow:hidden;color:var(--ink);background:#f4f9fd;font:14px/1.45 Arial,Helvetica,sans-serif}
    a{color:inherit}:focus-visible{outline:3px solid rgba(2,132,199,.35);outline-offset:2px}
    .app-shell{width:100%;height:100%;display:grid;grid-template-columns:198px minmax(0,1fr);overflow:hidden}
    .sidebar{position:relative;z-index:2;min-width:0;padding:20px 10px 16px;overflow:hidden auto;color:#fff;background:linear-gradient(180deg,var(--blue-700),var(--blue-900))}
    .sidebar:after{position:absolute;z-index:-1;right:-60px;bottom:0;left:-60px;height:210px;background:url("../assets/images/Abstract%20Blue%20Wave%20Gradient.png") center bottom/cover no-repeat;opacity:.34;content:"";pointer-events:none}
    .brand-lockup{margin:2px 0 26px;display:flex;align-items:center;flex-direction:column;gap:8px;color:#fff;text-align:center;text-decoration:none}
    .brand-logo{width:94px;height:94px;object-fit:cover;background:#fff;border:2px solid rgba(255,255,255,.55);border-radius:50%}
    .brand-copy{font-size:.92rem;font-weight:700;line-height:1.35}.brand-copy span{display:block;font-size:.82rem;font-weight:400}
    .side-nav{display:grid;gap:5px}
    .nav-item{min-height:44px;padding:9px 12px;display:grid;grid-template-columns:23px minmax(0,1fr);align-items:center;gap:12px;color:#eaf3fa;border-radius:10px;font-size:.92rem;text-decoration:none;transition:background-color .15s}
    .nav-item svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:2}
    .nav-item:hover,.nav-item.active{color:#fff;background:var(--blue-500)}.logout-link{margin-top:14px}
    .main-area{min-width:0;min-height:0;display:grid;grid-template-rows:76px minmax(0,1fr);overflow:hidden}
    .topbar{z-index:5;padding:10px clamp(18px,2.4vw,36px);display:flex;align-items:center;justify-content:space-between;gap:16px;background:linear-gradient(100deg,var(--blue-50),#fff 76%);border-bottom:1px solid var(--line);box-shadow:0 2px 9px rgba(0,46,74,.06)}
    .topbar h1{margin:0;color:var(--blue-900);font-size:1.48rem;line-height:1.2}.topbar p{margin:2px 0 0;color:var(--blue-700);font-size:.91rem}
    .profile-display{display:flex;align-items:center;gap:9px;color:var(--blue-900);text-decoration:none}.profile-display:hover{opacity:.82}
    .profile-avatar{width:42px;height:42px;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-100);border-radius:50%;font-weight:700}
    .profile-name{max-width:180px;overflow:hidden;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
    .notification-menu{position:relative}
    .notification{position:relative;width:40px;height:40px;padding:0;display:grid;place-items:center;color:var(--blue-700);background:#fff;border:1px solid var(--line);border-radius:11px;list-style:none;cursor:pointer;transition:background-color .15s,border-color .15s}
    .notification::-webkit-details-marker{display:none}.notification svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .notification:hover,.notification-menu[open]>.notification{background:var(--blue-50);border-color:#b8d2e5}
    .notification:focus-visible{outline:3px solid rgba(2,132,199,.28);outline-offset:3px}
    .notification-count{position:absolute;top:-3px;right:-5px;min-width:18px;height:18px;padding:0 4px;display:grid;place-items:center;color:#fff;background:#e33b43;border:2px solid #fff;border-radius:999px;font-size:.64rem;font-weight:700}
    .notification-panel{position:absolute;top:calc(100% + 13px);right:-18px;z-index:8;width:min(360px,calc(100vw - 24px));max-height:min(460px,calc(100dvh - 100px));padding:8px;overflow-y:auto;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:13px;box-shadow:0 14px 36px rgba(0,46,74,.2)}
    .notification-panel h2{margin:4px 8px 8px;color:var(--blue-900);font-size:.95rem}
    .notification-item{padding:9px 8px;display:grid;grid-template-columns:10px minmax(0,1fr) auto;align-items:center;gap:9px;border-top:1px solid #edf3f8;text-decoration:none}
    .notification-item:hover{background:var(--blue-50)}.notification-dot{width:9px;height:9px;border-radius:50%;background:var(--blue-500)}
    .notification-dot.billing,.notification-dot.inventory{background:#e59824}.notification-dot.appointment{background:#39a267}
    .notification-copy{min-width:0;display:grid;gap:2px}.notification-copy strong{color:var(--blue-900);font-size:.8rem}
    .notification-copy small,.notification-empty{color:var(--muted);font-size:.75rem}
    .notification-empty{margin:0;padding:14px 8px;text-align:center}.notification-empty.error{color:#8c3232}
    .notification-panel-footer{padding:8px 8px 2px;display:flex;justify-content:flex-end;border-top:1px solid #edf3f8}
    .notification-mark-all{padding:6px 9px;color:var(--blue-700);background:var(--blue-50);border:0;border-radius:7px;font:inherit;font-size:.75rem;font-weight:700;cursor:pointer}
    .notification-mark-all:hover{background:var(--blue-100)}
    .page-content{width:100%;max-width:none;min-width:0;min-height:0;margin:0;padding:14px clamp(14px,1.8vw,26px) 28px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;scroll-behavior:smooth}
    .notice{margin-bottom:14px;padding:11px 14px;border-radius:9px}.notice.error{color:#842626;background:#fdeaea}.notice.ok{color:#14573a;background:#dff3e8}

    .button,button.button{min-height:40px;padding:8px 16px;display:inline-flex;align-items:center;justify-content:center;gap:6px;color:#fff;background:var(--blue-500);border:1px solid var(--blue-500);border-radius:8px;font:inherit;font-size:.86rem;font-weight:700;text-decoration:none;cursor:pointer;transition:background-color .15s}
    .button:hover{background:var(--blue-700);border-color:var(--blue-700)}
    .button.secondary{color:var(--blue-700);background:#fff;border-color:#b8d2e5}.button.secondary:hover{background:var(--blue-50)}
    .button.light{color:var(--blue-900);background:#fff;border-color:#fff}.button.light:hover{background:var(--blue-50)}
    .button.ghost{color:#fff;background:transparent;border-color:rgba(255,255,255,.7)}.button.ghost:hover{background:rgba(255,255,255,.15)}
    .button.danger{color:#fff;background:#b93d45;border-color:#b93d45}.button.danger:hover{background:#972f37;border-color:#972f37}
    .button.small{min-height:34px;padding:5px 12px;font-size:.8rem}.button:disabled{color:#9fb6c8;background:#eef4f9;border-color:#dce8f1;cursor:not-allowed}
    .button.danger:disabled{color:#9fb6c8;background:#eef4f9;border-color:#dce8f1}

    .panel{min-width:0;margin:0 0 16px;background:#fff;border:1px solid #cbdfee;border-radius:16px;box-shadow:0 6px 18px rgba(0,46,74,.11)}
    .panel-header{min-height:48px;padding:9px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:#fff;background:linear-gradient(105deg,#015b8c,#002e4a);border-radius:15px 15px 0 0}
    .panel-header h2{margin:0;color:inherit;font-size:.98rem}.panel-body{min-width:0;padding:16px}.data-count{color:#eaf3fa;font-size:.78rem;font-weight:700}
    .form-note{margin:0 0 13px;color:var(--muted);font-size:.83rem}

    .stat-grid{margin:0 0 16px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .stat-grid.four{grid-template-columns:repeat(4,minmax(0,1fr))}
    .stat-card{min-height:104px;padding:18px 22px;display:grid;align-content:center;gap:7px;background:#fff;border:1px solid #e0ebf4;border-left:4px solid var(--accent,#0284c7);border-radius:12px;box-shadow:0 1px 2px rgba(0,46,74,.05),0 4px 14px rgba(0,46,74,.05)}
    .stat-label{color:var(--muted);font-size:.74rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase}
    .stat-value{color:var(--blue-900);font-size:1.75rem;font-weight:700;letter-spacing:-.03em;line-height:1.1;font-variant-numeric:tabular-nums}
    .stat-value.due{color:#9c3333}.stat-note{color:var(--muted);font-size:.76rem}
    .dashboard-home .stat-grid{gap:12px}
    .dashboard-home .stat-card{min-width:0;min-height:112px;padding:15px;grid-template-columns:40px minmax(0,1fr);grid-template-areas:"icon label" "icon value" "icon note";align-content:center;align-items:center;gap:3px 12px;background:linear-gradient(145deg,#fff 60%,#f7fbfe);border:1px solid #e0ebf4;border-top:3px solid var(--accent,#0284c7);border-radius:14px;box-shadow:0 5px 16px rgba(0,46,74,.055)}
    .dashboard-home .stat-icon{width:40px;height:40px;grid-area:icon;display:grid;place-items:center;color:var(--accent,#0284c7);background:#edf5fa;border-radius:12px}
    .dashboard-home .stat-icon svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
    .dashboard-home .stat-label{min-width:0;grid-area:label;font-size:.67rem;letter-spacing:.055em}
    .dashboard-home .stat-value{grid-area:value;font-size:1.6rem}
    .dashboard-home .stat-value.due{color:#9c3333}
    .dashboard-home .stat-note{min-width:0;grid-area:note;font-size:.72rem;line-height:1.35}

    .table-wrap{width:100%;overflow-x:auto;overflow-y:hidden;background:#fff;border:1px solid var(--grid-line);border-radius:12px;box-shadow:0 5px 16px rgba(0,46,74,.08)}
    .panel>.table-wrap{border:0;border-radius:0 0 15px 15px}
    .records-table{width:100%;min-width:640px;table-layout:fixed;border-collapse:separate;border-spacing:0;text-align:left}
    .records-table th,.records-table td{padding:12px 14px;border-right:1px solid var(--grid-line);border-bottom:1px solid var(--grid-line);line-height:1.35;vertical-align:middle}
    .records-table th:last-child,.records-table td:last-child{border-right:0}
    .records-table thead th{position:sticky;top:0;z-index:1;color:var(--blue-900);background:linear-gradient(180deg,#e4f1fb,#cfe5f5);border-bottom:2px solid var(--grid-strong);border-right-color:#b5d2e7;font-size:.77rem;letter-spacing:.04em;text-transform:uppercase}
    .records-table tbody td{color:#244661;font-size:.83rem;background:#fff;transition:background-color .12s ease}
    .records-table tbody tr:nth-child(even) td,.records-table tbody tr.alt td{background:#f1f7fc}
    .records-table tbody tr:hover td{background:#e1effa}
    .records-table tbody tr:last-child td{border-bottom:0}.records-table tbody tr:not(.empty-row) td:first-child{color:var(--blue-900);font-weight:600}
    .records-table .col-money,.records-table .col-due{font-variant-numeric:tabular-nums}
    .col-center{text-align:center}.col-right{text-align:right}.col-money{font-weight:700;color:#0b6b57}.col-due{font-weight:700;color:#9c3333}
    .appointment-panel-body{min-height:0;display:flex;flex-direction:column;gap:12px}
    .appointment-panel-body .client-search{flex:none;margin:0}
    .appointment-table-wrap{width:100%;min-height:0;max-height:none;overflow-x:auto;overflow-y:hidden;border:1px solid var(--grid-strong);border-radius:10px;box-shadow:none}
    .appointment-table{width:100%;min-width:760px;table-layout:fixed}
    .appointment-table:not(.has-actions) th:nth-child(1){width:17%}.appointment-table:not(.has-actions) th:nth-child(2){width:13%}
    .appointment-table:not(.has-actions) th:nth-child(3){width:23%}.appointment-table:not(.has-actions) th:nth-child(4){width:17%}
    .appointment-table:not(.has-actions) th:nth-child(5){width:19%}.appointment-table:not(.has-actions) th:nth-child(6){width:11%}
    .appointment-table.has-actions{min-width:820px}
    .appointment-table.has-actions th:nth-child(1){width:16%}.appointment-table.has-actions th:nth-child(2){width:9%}
    .appointment-table.has-actions th:nth-child(3){width:17%}.appointment-table.has-actions th:nth-child(4){width:15%}
    .appointment-table.has-actions th:nth-child(5){width:16%}.appointment-table.has-actions th:nth-child(6){width:12%}
    .appointment-table.has-actions th:nth-child(7){width:15%}
    .appointment-table.has-actions td[data-label="Action"]{white-space:nowrap}
    .pickup-confirm-button{min-width:108px;padding-left:8px;padding-right:8px;white-space:nowrap}
    .appointment-table th,.appointment-table td{border-right-color:#b5d2e7;border-bottom-color:var(--grid-line)}
    .appointment-table td{overflow-wrap:anywhere}
    .appointment-table tbody tr:nth-child(even) td{background:#edf5fb}
    .appointment-table tbody tr:hover td{background:#dcecf8}
    .appointment-table .appointment-empty-row{height:100%}
    .appointment-table .appointment-empty-row td{padding:0;background:#fff;border-right:0}
    .appointment-empty-state{min-height:280px;height:100%;padding:24px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:9px;color:var(--muted);text-align:center;white-space:normal}
    .appointment-empty-state strong{color:#4d6f8a;font-size:1.05rem}
    .appointment-empty-state span{font-size:.88rem}
    .appointment-empty-state .button{margin-top:5px}
    .appointment-table.billing-payment-table{min-width:950px}
    .appointment-table.billing-payment-table th:nth-child(1){width:18%}.appointment-table.billing-payment-table th:nth-child(2){width:27%}
    .appointment-table.billing-payment-table th:nth-child(3){width:19%}.appointment-table.billing-payment-table th:nth-child(4){width:17%}
    .appointment-table.billing-payment-table th:nth-child(5){width:19%}
    .appointment-table.vaccination-history-table{min-width:900px}
    .appointment-table.vaccination-history-table th:nth-child(1){width:20%}.appointment-table.vaccination-history-table th:nth-child(2){width:26%}
    .appointment-table.vaccination-history-table th:nth-child(3){width:18%}.appointment-table.vaccination-history-table th:nth-child(4){width:18%}
    .appointment-table.vaccination-history-table th:nth-child(5){width:18%}
    .appointment-table.vaccination-due-table{min-width:760px}
    .appointment-table.vaccination-due-table:not(.has-actions) th:nth-child(1),
    .appointment-table.vaccination-due-table:not(.has-actions) th:nth-child(2),
    .appointment-table.vaccination-due-table:not(.has-actions) th:nth-child(3),
    .appointment-table.vaccination-due-table:not(.has-actions) th:nth-child(4){width:25%}
    .vaccination-due-wrap{width:100%;overflow-x:auto;overflow-y:hidden;border:1px solid var(--grid-strong);border-radius:10px;box-shadow:none}
    .settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:stretch;gap:16px}
    .settings-grid.single{grid-template-columns:minmax(0,1fr)}
    .settings-panel{margin:0;display:flex;flex-direction:column}
    .settings-panel .panel-header{min-height:64px;padding:12px 17px}
    .settings-panel-heading{min-width:0;display:grid;gap:2px}
    .settings-panel-heading p{margin:0;color:#d7e8f5;font-size:.75rem;font-weight:400}
    .settings-badge{padding:5px 9px;color:#e5f4fd;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:999px;font-size:.63rem;font-weight:800;letter-spacing:.08em}
    .settings-form{min-height:390px;flex:1;display:flex;flex-direction:column;gap:16px;padding:clamp(18px,2.2vw,24px)}
    .settings-form .form-grid{gap:15px}
    .settings-note{margin:0;padding:12px 14px;display:flex;align-items:flex-start;gap:10px;color:#345b77;background:#f1f7fc;border:1px solid #e0ebf4;border-radius:10px;font-size:.8rem;line-height:1.5}
    .settings-note-icon{width:21px;height:21px;flex:none;display:grid;place-items:center;color:#fff;background:var(--blue-700);border-radius:50%;font-size:.72rem;font-weight:800}
    .settings-note p{margin:0}
    .settings-action{margin-top:auto;padding-top:15px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-top:1px solid var(--line)}
    .settings-action .hint{max-width:28ch;font-size:.75rem}.settings-action .button{flex:none}
    .status{padding:5px 11px;display:inline-flex;color:#225d40;background:#e3f5e9;border-radius:999px;font-size:.72rem;font-weight:700;white-space:nowrap}
    .status.pickup-ready-status{max-width:100%;margin-top:5px;color:#14532d;background:#dcfce7;white-space:normal;text-align:center;line-height:1.2}
    .status.pending{color:#7a4a00;background:#fdf0d5}.status.scheduled{color:#155c86;background:#e2f2fc}.status.check_in{color:#55368f;background:#eee7fb}
    .status.in_consultation{color:#8a2f6d;background:#fbe6f3}.status.cancelled{color:#5f7385;background:#e8eef4}.status.overdue{color:#9c3333;background:#fdeaea}
    .empty{padding:34px 18px;color:var(--muted);text-align:center}.empty strong{display:block;margin-bottom:4px;color:#4d6f8a;font-size:.95rem}.empty .button{margin-top:12px}

    /* forms */
    .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.form-grid .full{grid-column:1/-1}
    label.field{display:grid;gap:5px;color:var(--blue-900);font-size:.8rem;font-weight:700}
    .field input,.field select,.field textarea{width:100%;min-height:42px;padding:9px 11px;color:var(--ink);background:#fff;border:1px solid #bed4e5;border-radius:8px;font:inherit}
    .field textarea{min-height:78px;resize:vertical}.field input[readonly]{background:#f1f6fa;color:var(--muted)}
    .field input:focus,.field select:focus,.field textarea:focus{outline:3px solid rgba(2,132,199,.16);border-color:var(--blue-500)}
    .hint{margin:0;color:var(--muted);font-size:.8rem}.hide{display:none!important}

    /* pets */
    .pet-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
    .pet-card{padding:18px;display:grid;gap:12px;background:#fff;border:1px solid #cbdfee;border-top:4px solid var(--blue-500);border-radius:14px;box-shadow:0 4px 14px rgba(0,46,74,.07)}
    .pet-toolbar{margin:0 0 15px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:14px;background:linear-gradient(110deg,var(--blue-50),#fff);border:1px solid var(--line);border-radius:12px}
    .pet-toolbar h3{margin:0;color:var(--blue-900);font-size:.98rem}.pet-toolbar p{margin:3px 0 0;color:var(--muted);font-size:.82rem}
    .pet-actions{display:flex;flex-wrap:wrap;gap:8px}.pet-delete-note{margin:0;color:var(--muted);font-size:.75rem}
    .pet-top{display:flex;align-items:center;gap:13px}.pet-dot{width:52px;height:52px;flex:none;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-100);border-radius:50%;font-size:1.2rem;font-weight:700}
    .pet-top h3{margin:0;color:var(--blue-900);font-size:1.05rem}.pet-top span{color:var(--muted);font-size:.84rem}
    .pet-facts{margin:0;display:grid;grid-template-columns:1fr 1fr;gap:8px 14px}.pet-facts dt{color:var(--muted);font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase}.pet-facts dd{margin:0;color:var(--blue-900);font-weight:600}

    /* booking */
    .steps{margin:0 0 16px;padding:0;display:flex;gap:8px;list-style:none}
    .steps li{flex:1;padding:8px 4px;color:var(--muted);border-bottom:3px solid var(--blue-100);font-size:.82rem;font-weight:700}
    .steps li.on{color:var(--blue-700);border-color:var(--blue-500)}.steps li.done{color:var(--blue-900);border-color:var(--blue-700)}
    .step{display:none;gap:13px}.step.on{display:grid}.step h3{margin:0;color:var(--blue-900);font-size:1.02rem}
    .svc{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px}
    .svc label{padding:13px 15px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-weight:600;background:#fff;border:1px solid var(--line);border-radius:10px;cursor:pointer}
    .svc label:has(input:checked){border-color:var(--blue-500);background:var(--blue-50)}.svc input{width:auto;min-height:0;margin-right:8px}.svc .p{color:var(--muted);font-weight:700;white-space:nowrap}
    .slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(98px,1fr));gap:8px}
    .slots button{min-width:0;padding:10px 6px;color:var(--blue-900);background:#fff;border:1px solid var(--blue-300);border-radius:9px;font:inherit;font-weight:700;white-space:normal;overflow-wrap:anywhere;line-height:1.35;text-align:center;cursor:pointer}
    .slots button:hover:not(:disabled){background:var(--blue-50)}.slots button.sel{color:#fff;background:var(--blue-500);border-color:var(--blue-500)}
    .slots button:disabled{color:#9db2c3;background:#eef3f7;border-color:#e0e8ee;text-decoration:line-through;cursor:not-allowed}
    .summary{padding:12px 14px;background:var(--blue-50);border-radius:10px;font-weight:600;color:var(--blue-900)}
    .nav-row{margin-top:6px;display:flex;justify-content:space-between;gap:10px}

    /* home / website sections */
    .hero{position:relative;margin:0 0 16px;overflow:hidden;color:#fff;background:var(--blue-900) url("../assets/images/Banner.png") center/cover no-repeat;border-radius:16px;box-shadow:0 6px 18px rgba(0,46,74,.18)}
    .hero:before{position:absolute;inset:0;content:"";background:linear-gradient(100deg,rgba(0,9,20,.86),rgba(0,46,74,.74) 55%,rgba(1,91,140,.35))}
    .hero-in{position:relative;max-width:640px;padding:clamp(28px,5vw,58px) clamp(22px,4vw,48px)}
    .hero h2{margin:0 0 10px;font-size:clamp(1.7rem,3.4vw,2.5rem);line-height:1.15}.hero p{margin:0 0 22px;color:#dcebf6;font-size:1.02rem}
    .hero-actions{display:flex;flex-wrap:wrap;gap:10px}
    .next-visit{margin:0 0 16px;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#fff;border:1px solid #cbdfee;border-left:6px solid var(--blue-500);border-radius:14px}
    .next-visit small{color:var(--muted);font-weight:700}.next-visit b{display:block;color:var(--blue-900);font-size:1.15rem}
    .site-section{margin:0 0 16px;padding:clamp(22px,3vw,34px);background:#fff;border:1px solid #cbdfee;border-radius:16px;box-shadow:0 6px 18px rgba(0,46,74,.08);scroll-margin-top:12px}
    .site-section h2{margin:0 0 6px;color:var(--blue-900);font-size:1.4rem}.site-section>p.lead{margin:0 0 20px;max-width:62ch;color:var(--muted)}
    .section-eyebrow{margin:0 0 9px;color:var(--blue-700);font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
    .about-section{padding:clamp(26px,4vw,48px)}
    .about-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(200px,.65fr);gap:clamp(24px,5vw,72px);align-items:center}
    .about-copy h2{max-width:19ch;margin:0 0 14px;font-size:clamp(1.8rem,3vw,2.55rem);letter-spacing:-.035em;line-height:1.12}
    .about-copy>p:not(.section-eyebrow){max-width:58ch;margin:0 0 18px;color:#35546d;font-size:1rem;line-height:1.7}
    .about-copy .button{margin-top:2px}
    .about-art{min-height:0;padding:0;display:grid;place-items:center;background:transparent}.about-art img{width:min(100%,230px);height:auto;object-fit:contain}
    .about-highlights{margin-top:30px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .about-highlight{min-width:0;padding:16px;display:grid;grid-template-columns:34px minmax(0,1fr);align-content:start;gap:11px;background:linear-gradient(145deg,#f7fbfe,#eef6fb);border:1px solid #e1edf5;border-radius:12px}
    .about-highlight>span{width:32px;height:32px;display:grid;place-items:center;color:#fff;background:var(--blue-700);border-radius:10px;font-size:.7rem;font-weight:800}
    .about-highlight h3{margin:1px 0 4px;color:var(--blue-900);font-size:.87rem}
    .about-highlight p{margin:0;color:var(--muted);font-size:.78rem;line-height:1.5}
    .card-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch;gap:14px}
    .card{padding:20px;background:#fff;border:1px solid #d5e7f4;border-top:4px solid var(--accent,#0284c7);border-radius:12px}
    .card h3{margin:0 0 6px;color:var(--blue-900);font-size:1.02rem}.card p{margin:0;color:var(--muted);font-size:.9rem}
    .services-section>.section-eyebrow{margin-bottom:5px}
    .services-section>h2{font-size:clamp(1.45rem,2.4vw,1.8rem)}
    .services-grid{gap:15px}
    .service-card{position:relative;min-height:166px;padding:18px;display:flex;flex-direction:column;overflow:hidden;background:linear-gradient(155deg,#fff,#f8fbfe);border:1px solid #dceaf4;border-top:0;border-radius:14px;box-shadow:0 4px 12px rgba(0,46,74,.045);transition:transform .18s ease,box-shadow .18s ease}
    .service-card:before{position:absolute;top:0;right:0;left:0;height:4px;content:"";background:var(--accent,#0284c7)}
    .service-card:hover{transform:translateY(-3px);box-shadow:0 10px 22px rgba(0,46,74,.1)}
    .service-card h3{margin:1px 0 8px;font-size:.98rem}
    .service-card p:not(.service-meta){font-size:.84rem;line-height:1.55}
    .service-card .service-meta{margin-top:auto!important;padding-top:14px;font-size:.82rem}
    .roles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0;padding:0;list-style:none}
    .roles li{padding:18px;background:var(--blue-50);border-radius:12px}.roles strong{display:block;margin-bottom:4px;color:var(--blue-900)}.roles span{color:var(--muted);font-size:.88rem}
    .faq{display:grid;gap:8px;max-width:none}.faq>h3{margin:0 0 4px;color:var(--blue-900);font-size:1.05rem}
    .faq details{overflow:hidden;background:#fff;border:1px solid #dce8f1;border-radius:11px;transition:border-color .15s,box-shadow .15s}
    .faq details[open]{border-color:#b9d5e7;box-shadow:0 4px 12px rgba(0,46,74,.06)}
    .faq summary{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:14px;color:var(--blue-900);font-size:.87rem;font-weight:700;list-style:none;cursor:pointer}
    .faq summary::-webkit-details-marker{display:none}
    .faq summary:after{width:22px;height:22px;flex:none;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-50);border-radius:50%;content:"+";font-size:1rem}
    .faq details[open] summary:after{content:"−"}
    .faq details[open] summary{border-bottom:1px solid var(--line)}
    .faq p{margin:0;padding:13px 16px;color:#35546d;font-size:.84rem;line-height:1.6}
    .faq p a{color:var(--blue-700);font-weight:700}
    .contact-layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);align-items:start;gap:22px}
    .contact-details{min-width:0;display:grid;gap:20px}
    .contact-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .contact-card{min-width:0;padding:15px;background:#f5f9fc;border:1px solid #e2edf5;border-radius:12px}
    .contact-label{display:block;margin-bottom:7px;color:var(--muted);font-size:.68rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
    .contact-card h3{margin:0;color:var(--blue-900);font-size:.83rem;line-height:1.45;overflow-wrap:anywhere}
    .contact-card a{color:var(--blue-700);font-weight:700;text-decoration:none}.contact-card a:hover{text-decoration:underline}
    .location-card{min-width:0;padding:17px;background:#f5f9fc;border:1px solid #e0ebf4;border-radius:14px}
    .location-heading{margin:0 0 12px;display:flex;align-items:baseline;justify-content:space-between;gap:10px}
    .location-heading h3{margin:0;color:var(--blue-900);font-size:1rem}
    .location-heading span{color:var(--muted);font-size:.74rem}
    .map-frame{width:100%;aspect-ratio:4/3;overflow:hidden;background:#e8f1f7;border:1px solid #d4e3ed;border-radius:10px}
    .map-frame iframe{width:100%;height:100%;display:block;border:0}
    .map-link{margin-top:11px;display:inline-flex;color:var(--blue-700);font-size:.8rem;font-weight:700;text-decoration:none}
    .map-link:hover{text-decoration:underline}
    .hours{margin:0;padding:0;list-style:none}.hours li{padding:4px 0;display:flex;justify-content:space-between;gap:10px;color:#244661}
    .site-footer{margin:24px 0 0;padding:28px clamp(20px,3vw,36px) 16px;color:#d6e9f6;background:linear-gradient(115deg,#015b8c,#002e4a 72%);border-radius:16px;font-size:.86rem}
    .footer-main{padding-bottom:22px;display:grid;grid-template-columns:minmax(0,1.4fr) minmax(140px,.7fr) minmax(210px,1fr);gap:28px}
    .footer-brand{display:flex;align-items:flex-start;gap:13px}
    .footer-brand img{width:54px;height:54px;flex:none;object-fit:contain}
    .footer-brand strong{display:block;margin:2px 0 5px;color:#fff;font-size:1.04rem}
    .footer-brand p{max-width:34ch;margin:0;color:#d6e9f6;font-size:.81rem;line-height:1.55}
    .footer-links,.footer-contact{display:grid;align-content:start;justify-items:start;gap:8px}
    .footer-heading{margin:2px 0 3px;color:#fff;font-size:.83rem;font-weight:800}
    .site-footer a{color:#fff;font-weight:600;text-decoration:none}
    .site-footer a:hover{text-decoration:underline}
    .footer-bottom{padding-top:14px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid rgba(214,233,246,.25);color:#c6dce9;font-size:.76rem}
    .site-jump{margin:0 0 20px;padding:12px 14px;display:flex;align-items:center;flex-wrap:wrap;gap:10px 12px;background:#fff;border:1px solid #d5e7f4;border-radius:12px}
    .site-jump a{padding:9px 14px;color:var(--blue-700);background:var(--blue-50);border-radius:999px;font-size:.82rem;font-weight:700;line-height:1.25;text-decoration:none;transition:background-color .15s,color .15s}
    .site-jump a:hover{color:#fff;background:var(--blue-700)}
    .site-jump a:focus-visible{outline:3px solid rgba(2,132,199,.3);outline-offset:2px}
    .service-meta{margin-top:11px!important;color:var(--blue-700)!important;font-weight:700}
    .gallery-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .gallery-card{min-width:0;margin:0;overflow:hidden;background:#fff;border:1px solid #d5e7f4;border-radius:14px;box-shadow:0 4px 14px rgba(0,46,74,.07);transition:transform .2s ease,box-shadow .2s ease}
    .gallery-card:hover{transform:translateY(-3px);box-shadow:0 12px 24px rgba(0,46,74,.13)}
    .gallery-card img{width:100%;height:auto;aspect-ratio:3/4;display:block;object-fit:cover;background:var(--blue-50);transition:transform .35s ease}
    .gallery-card:hover img{transform:scale(1.035)}
    .gallery-card figcaption{padding:13px 15px;color:var(--blue-900);font-size:.87rem;font-weight:700}
    .story-callout{padding:20px;display:flex;align-items:center;gap:16px;background:linear-gradient(110deg,var(--blue-50),#fff);border:1px solid #d5e7f4;border-radius:13px}
    .story-mark{color:var(--blue-300);font-family:Georgia,serif;font-size:4rem;line-height:.7}
    .story-callout h3{margin:0 0 5px;color:var(--blue-900)}.story-callout p{margin:0;color:var(--muted)}
    .story-callout .button{margin-left:auto;white-space:nowrap}
    .booking-panel>.panel-body{padding:clamp(16px,2.4vw,26px)}
    .booking-progress-shell{margin:0 0 20px}
    .booking-progress{margin:0;padding:0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;list-style:none}
    .booking-progress li{min-width:0;display:flex;align-items:center;gap:8px;color:var(--muted);font-size:.77rem;font-weight:700;white-space:nowrap}
    .booking-progress-number{width:29px;height:29px;flex:none;display:grid;place-items:center;color:#71869a;background:#edf3f7;border-radius:50%;font-size:.66rem;transition:background-color .2s,color .2s}
    .booking-progress-label{overflow:hidden;text-overflow:ellipsis}
    .booking-progress li.on{color:var(--blue-700)}
    .booking-progress li.on .booking-progress-number,.booking-progress li.done .booking-progress-number{color:#fff;background:var(--blue-700)}
    .booking-progress li.done{color:#55738b}
    .booking-progress-track{height:6px;margin-top:12px;overflow:hidden;background:#e8eef3;border-radius:999px}
    .booking-progress-track>span{width:0;height:100%;display:block;background:linear-gradient(90deg,var(--blue-500),#39a1c8);border-radius:inherit;transition:width .25s ease}
    #book-form{display:grid;gap:14px}
    .booking-section{min-width:0;padding:clamp(16px,2.2vw,24px);background:#fff;border:1px solid #dce8f1;border-radius:15px;box-shadow:0 4px 14px rgba(0,46,74,.045)}
    .booking-section-heading{margin:0 0 17px;display:flex;align-items:flex-start;gap:12px}
    .booking-section-number{width:34px;height:34px;flex:none;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-50);border:1px solid #d7e9f5;border-radius:11px;font-size:.7rem;font-weight:800}
    .booking-section-heading h3{margin:1px 0 4px;color:var(--blue-900);font-size:1.08rem}
    .booking-section-heading p{margin:0;color:var(--muted);font-size:.82rem;line-height:1.5}
    .pet-picker{min-width:0;margin:0;padding:0;border:0}
    .pet-picker legend,.service-picker legend{margin-bottom:10px;color:var(--blue-900);font-size:.82rem;font-weight:800}
    .pet-options{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
    .pet-choice{position:relative;min-width:0;display:block;cursor:pointer}
    a.pet-choice{color:inherit;text-decoration:none}
    .pet-choice>input{position:absolute;width:1px;height:1px;opacity:0}
    .pet-choice-card{min-height:76px;padding:11px;display:grid;grid-template-columns:52px minmax(0,1fr) 22px;align-items:center;gap:11px;background:#fff;border:1px solid #d7e5ef;border-radius:12px;transition:border-color .16s,background-color .16s,box-shadow .16s}
    .pet-choice-card>img{width:52px;height:52px;display:block;object-fit:cover;background:#edf5fa;border-radius:11px}
    .pet-choice-copy{min-width:0;display:grid;gap:4px}
    .pet-choice-copy strong{overflow:hidden;color:var(--blue-900);font-size:.85rem;text-overflow:ellipsis;white-space:nowrap}
    .pet-choice-copy small{overflow:hidden;color:var(--muted);font-size:.72rem;text-overflow:ellipsis;white-space:nowrap}
    .pet-choice-check{width:20px;height:20px;display:grid;place-items:center;color:#fff;background:var(--blue-700);border-radius:50%;font-size:.72rem;opacity:0;transform:scale(.8);transition:opacity .16s,transform .16s}
    .pet-choice>input:checked+.pet-choice-card{background:#f2f9fd;border-color:var(--blue-500);box-shadow:0 0 0 2px rgba(2,132,199,.1)}
    .pet-choice>input:checked+.pet-choice-card .pet-choice-check{opacity:1;transform:scale(1)}
    .pet-choice>input:focus-visible+.pet-choice-card{outline:3px solid rgba(2,132,199,.25);outline-offset:2px}
    .add-pet-choice .pet-choice-card{grid-template-columns:52px minmax(0,1fr);border-style:dashed}
    .add-pet-choice:hover .pet-choice-card{background:#f7fbfe;border-color:var(--blue-500)}
    .add-pet-choice:focus-visible .pet-choice-card{outline:3px solid rgba(2,132,199,.25);outline-offset:2px}
    .pet-add-icon{width:48px;height:48px;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-50);border:1px solid #d7e9f5;border-radius:50%;font-size:1.5rem;font-weight:400;line-height:1}
    .pet-picker-empty{margin:12px 0 0;padding:11px 13px;color:#526c81;background:#f4f8fb;border-radius:10px;font-size:.8rem}
    .service-picker{min-width:0;margin:20px 0 0;padding:0;border:0}
    .service-type-sections{display:grid;gap:18px}
    .service-type-group{min-width:0;transition:opacity .15s ease}
    .service-type-group h4{margin:0 0 9px;color:var(--blue-900);font-size:.92rem}
    .service-type-group.is-locked{opacity:.5}
    .booking-section .svc{grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:10px}
    .service-option{min-width:0;min-height:92px;margin:0;padding:14px!important;align-items:center!important;border-radius:12px!important;transition:border-color .16s,background-color .16s,box-shadow .16s}
    .service-option:hover{border-color:#9fc6df;background:#f9fcfe}
    .service-option:has(input:checked){border-color:var(--blue-500);background:#f2f9fd;box-shadow:0 0 0 2px rgba(2,132,199,.1)}
    .service-option:focus-within{outline:3px solid rgba(2,132,199,.16);outline-offset:1px}
    .service-option-copy{min-width:0}
    .service-option-title{min-width:0;gap:7px}
    .service-option-title input{flex:none}
    .service-option-copy small{line-height:1.45}
    .service-option>strong{flex:none;font-size:.82rem}
    .service-selection-summary{margin:12px 0 0;padding:10px 12px;color:var(--blue-700);background:var(--blue-50);border-radius:9px;font-size:.8rem;font-weight:700}
    .booking-notes{margin-top:18px}
    .calendar-card{padding:clamp(13px,2vw,20px);background:#f7fbfe;border:1px solid #e0ebf4;border-radius:14px}
    .calendar-toolbar{margin-bottom:14px}
    .calendar-legend{margin-bottom:14px}
    .calendar-grid{max-width:620px;margin:0 auto}
    .calendar-weekdays{max-width:620px;margin:0 auto}
    .calendar-day{min-height:48px;border-radius:11px}
    .calendar-day:focus-visible{outline:3px solid rgba(2,132,199,.32);outline-offset:2px}
    .calendar-card .hint{margin-top:14px}
    .selected-date-label:not(:empty){margin-top:10px;padding:10px 12px;color:#4f357f;background:#f5f0ff;border-radius:9px;font-size:.83rem}
    .booking-time-panel{margin-top:18px;padding:18px;background:#f7fbfe;border:1px solid #e0ebf4;border-radius:14px}
    .booking-subsection-heading{margin-bottom:14px;display:flex;align-items:flex-start;gap:10px}
    .booking-subsection-heading>span{padding:5px 7px;color:var(--blue-700);background:var(--blue-50);border-radius:6px;font-size:.58rem;font-weight:800;letter-spacing:.04em}
    .booking-subsection-heading h4{margin:0 0 4px;color:var(--blue-900);font-size:.97rem}
    .booking-subsection-heading p{margin:0;color:var(--muted);font-size:.8rem}
    .booking-time-panel>.hint{margin:12px 0}
    .booking-step-actions{margin-top:20px;padding-top:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-top:1px solid var(--line)}
    .booking-step-actions .button{min-width:135px}
    .booking-step-actions-next{justify-content:flex-end}
    .slots{grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px}
    .slots button{min-height:43px;border-radius:10px;transition:background-color .15s,border-color .15s,transform .15s}
    .slots button:hover:not(:disabled){transform:translateY(-1px)}
    .slots button.sel{box-shadow:0 0 0 3px rgba(122,79,196,.16)}
    .booking-submit-row{margin-top:18px;padding-top:16px;display:flex;align-items:center;justify-content:flex-end;gap:12px;border-top:1px solid var(--line)}
    .booking-submit-row .hint{margin:0}
    .booking-submit-row .button{flex:none}
    .booking-submit-row>.hint{margin-right:auto}
    .service-option{align-items:flex-start!important}
    .service-option-copy{display:grid;gap:5px}
    .service-option-copy small{color:var(--muted);font-size:.77rem;font-weight:400}
    .service-option-title{display:flex;align-items:center;color:var(--blue-900);font-weight:700}
    .service-option>strong{color:var(--blue-700);white-space:nowrap}
    .calendar-toolbar{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:12px}
    .calendar-toolbar strong{text-align:center;color:var(--blue-900);font-size:1rem}
    .calendar-legend{display:flex;flex-wrap:wrap;gap:9px 16px;color:var(--muted);font-size:.75rem}
    .calendar-legend span{display:inline-flex;align-items:center;gap:6px}
    .legend-dot{width:10px;height:10px;display:inline-block;border-radius:50%}
    .legend-dot.available{background:#23935b}.legend-dot.unavailable{background:#d64b4b}
    .legend-dot.blocked{background:#aebdca}.legend-dot.selected{background:#7a4fc4}
    .calendar-weekdays,.calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}
    .calendar-weekdays span{padding:6px 2px;color:var(--muted);font-size:.74rem;font-weight:700;text-align:center}
    .calendar-day{min-height:48px;padding:7px 4px;color:#fff;border:1px solid transparent;border-radius:11px;font:inherit;font-weight:700;cursor:pointer;transition:transform .12s,box-shadow .12s}
    .calendar-day:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,46,74,.16)}
    .calendar-day.available{background:#23935b}.calendar-day.unavailable{color:#fff;background:#cf4949;cursor:not-allowed}
    .calendar-day.blocked{color:#6e8292;background:#e8eef3;cursor:not-allowed}
    .calendar-day.selected{color:#fff;background:#7a4fc4;border-color:#5c31a4;box-shadow:0 0 0 3px rgba(122,79,196,.2)}
    .calendar-day:disabled{opacity:.82}
    .calendar-blank{min-height:44px}
    .selected-date-label{margin:0;color:var(--blue-700);font-weight:700}
    .assignment-card{padding:18px;display:grid;gap:7px;background:linear-gradient(120deg,var(--blue-50),#fff);border:1px solid #cbdfee;border-left:5px solid var(--blue-500);border-radius:12px}
    .assignment-card strong{color:var(--blue-900);font-size:1.2rem}.assignment-card p{margin:0;color:var(--muted)}
    .review-card{padding:16px;display:grid;grid-template-columns:110px minmax(0,1fr);align-items:center;gap:18px;background:#f8fbfe;border:1px solid #d5e7f4;border-radius:12px}
    .review-card img{width:110px;height:110px;object-fit:cover;background:var(--blue-50);border-radius:12px}
    .review-card dl{margin:0;display:grid;gap:9px}.review-card dl>div{display:grid;grid-template-columns:minmax(125px,.7fr) minmax(0,1.3fr);gap:10px}
    .review-card dt{color:var(--muted);font-size:.78rem;font-weight:700}.review-card dd{margin:0;color:var(--blue-900);font-weight:600}
    .nav-row{margin-top:18px;display:flex;align-items:center;justify-content:space-between;gap:10px}

    /* Shared client-page controls mirror the admin workspace while preserving client actions. */
    .topbar-title h1{margin:0;color:var(--blue-900);font-size:1.48rem;line-height:1.2}
    .profile-area{display:flex;align-items:center;gap:12px;color:var(--blue-900)}
    .profile-divider{width:1px;height:34px;background:var(--blue-300)}
    .profile-copy{min-width:0;display:grid;gap:1px}.profile-copy small{color:var(--muted);font-size:.72rem}
    .panel{overflow:hidden}.panel-header{border-radius:15px 15px 0 0}
    .toolbar{margin:0 0 13px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:9px}
    .toolbar label{min-width:180px;flex:1 1 230px;display:grid;gap:5px;color:var(--blue-900);font-size:.79rem;font-weight:700}
    .toolbar input{width:100%;min-height:41px;padding:9px 11px;color:var(--ink);background:#fff;border:1px solid #bed4e5;border-radius:8px;font:inherit}
    .toolbar input:focus{outline:3px solid rgba(2,132,199,.16);border-color:var(--blue-500)}
    .search-count{min-height:41px;display:flex;align-items:center;color:var(--muted);font-size:.78rem}
    .records-table{table-layout:fixed}.records-table thead th{position:sticky;top:0;z-index:1}
    [hidden]{display:none!important}
    .records-table tr[hidden],.pet-card[hidden]{display:none}
    .empty-row td{padding:0!important;border-right:0!important}
    .empty-state-box{min-height:100px;padding:16px;display:grid;place-content:center;gap:4px;color:var(--muted);text-align:center;white-space:normal}
    .empty-state-box strong{color:#4d6f8a;font-size:.92rem}.empty-state-box small{font-size:.76rem}
    .page-intro{margin:0 0 14px;display:flex;align-items:flex-end;justify-content:space-between;gap:12px}
    .page-intro h2{margin:0;color:var(--blue-900);font-size:1.3rem}.page-intro p{margin:3px 0 0;color:var(--muted);font-size:.86rem}
    .pet-card{position:relative;overflow:hidden;border-top:0;border-left:4px solid var(--blue-500);transition:transform .18s ease,box-shadow .18s ease}
    .pet-card:hover{transform:translateY(-2px);box-shadow:0 9px 22px rgba(0,46,74,.12)}
    .pet-photo{width:66px;height:66px;flex:none;display:grid;place-items:center;overflow:hidden;color:var(--blue-700);background:linear-gradient(135deg,var(--blue-50),var(--blue-100));border-radius:16px;font-size:1.35rem;font-weight:700}
    .pet-photo img{width:100%;height:100%;object-fit:cover}
    .photo-preview{width:100%;height:160px;display:grid;place-items:center;overflow:hidden;color:var(--muted);background:linear-gradient(135deg,var(--blue-50),var(--blue-100));border:1px dashed #9fc2dc;border-radius:12px}
    .photo-preview img{width:100%;height:100%;object-fit:cover}
    .photo-crop-layout{display:grid;grid-template-columns:minmax(0,260px) minmax(0,1fr);align-items:center;gap:18px}
    .photo-crop-viewport{position:relative;width:min(100%,260px);aspect-ratio:1;overflow:hidden;touch-action:none;background:linear-gradient(135deg,var(--blue-50),var(--blue-100));border:1px solid #b8d2e5;border-radius:16px;cursor:grab}
    .photo-crop-viewport:active{cursor:grabbing}.photo-crop-viewport img{position:absolute;max-width:none;max-height:none;user-select:none;pointer-events:none}
    .photo-crop-viewport img[hidden]{display:none}
    .photo-crop-placeholder{position:absolute;inset:0;padding:24px;display:grid;place-items:center;color:var(--muted);font-size:.83rem;text-align:center;pointer-events:none}
    .photo-crop-controls{min-width:0;display:grid;align-content:center;gap:12px}.photo-crop-controls .field{margin:0}
    .photo-crop-help{margin:0;color:var(--muted);font-size:.8rem;line-height:1.45}
    .delete-dialog-content{padding:18px 20px;display:grid;gap:10px}.delete-dialog-content p{margin:0;color:var(--muted);line-height:1.5}
    .delete-dialog-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px}
    .upload-hint{margin:0;color:var(--muted);font-size:.76rem;font-weight:400}
    .pet-facts{gap:9px 14px}.pet-facts dd{font-size:.84rem}
    .field input[type=file]{min-height:0;padding:9px;background:#f8fbfe}
    .photo-remove-button{justify-self:start}
    .photo-remove-button[hidden]{display:none!important}

    dialog{width:min(520px,calc(100vw - 24px));max-height:calc(100vh - 24px);padding:0;color:var(--ink);background:#fff;border:0;border-radius:16px;box-shadow:0 24px 60px rgba(0,9,20,.4)}
    dialog::backdrop{background:rgba(0,28,46,.62)}.dlg-form{padding:18px 20px 20px;display:grid;gap:13px}
    .nd-top{padding:22px 24px 16px;text-align:center;background:linear-gradient(135deg,var(--blue-50),var(--blue-100))}.nd-top img{width:66px;height:66px;border-radius:50%;background:#fff}
    .nd-top h2{margin:8px 0 2px;color:var(--blue-900);font-size:1.35rem}.nd-top p{margin:0;color:var(--muted)}

    @media(max-width:1050px){.card-grid,.contact-grid{grid-template-columns:1fr 1fr}.roles{grid-template-columns:1fr 1fr}.stat-grid.four{grid-template-columns:1fr 1fr}.about-grid{grid-template-columns:1fr}}
    @media(max-width:760px){
      .main-area{grid-template-rows:68px minmax(0,1fr)}.topbar{padding:8px 12px}.topbar h1,.topbar-title h1{font-size:1.12rem}.topbar p{font-size:.76rem}.profile-copy,.profile-divider{display:none}.profile-avatar{width:36px;height:36px}
     .page-content{padding:12px 11px 20px}.stat-grid,.stat-grid.four,.card-grid,.contact-grid,.about-highlights,.contact-layout,.roles,.form-grid{grid-template-columns:1fr}.steps{font-size:.74rem}.search-count{width:100%;min-height:0}.pet-photo{width:58px;height:58px}.footer-main{grid-template-columns:1fr 1fr;gap:20px}.footer-brand{grid-column:1/-1}
     .site-jump{flex-wrap:nowrap;overflow-x:auto;overscroll-behavior-x:contain;scrollbar-width:thin}.site-jump a{flex:none;white-space:nowrap}}
    @media(max-width:760px){.settings-grid{grid-template-columns:1fr}.settings-form{min-height:0}}
    @media(max-width:600px){.pet-toolbar{align-items:flex-start;flex-direction:column}.photo-crop-layout{grid-template-columns:1fr;justify-items:center}.photo-crop-controls{width:100%}.appointment-panel-body{height:auto}.appointment-empty-state{min-height:240px;padding:16px}.settings-panel .panel-header{padding:11px 13px}.settings-form{padding:16px}.settings-action{align-items:stretch;flex-direction:column}.settings-action .button{width:100%}}
    @media(max-width:480px){.footer-main{grid-template-columns:1fr;gap:18px}.footer-brand{grid-column:auto}.footer-bottom{align-items:flex-start;flex-direction:column}.location-heading{align-items:flex-start;flex-direction:column;gap:3px}}
    @media(max-width:760px){.gallery-grid{grid-template-columns:1fr}.story-callout{align-items:flex-start;flex-wrap:wrap}.story-callout .button{margin-left:0}.review-card{grid-template-columns:76px minmax(0,1fr);gap:12px}.review-card img{width:76px;height:76px}.review-card dl>div{grid-template-columns:1fr;gap:2px}.calendar-weekdays,.calendar-grid{gap:4px}.calendar-day,.calendar-blank{min-height:38px}.notification-panel{right:-45px}
      .booking-progress{grid-template-columns:repeat(4,minmax(0,1fr));gap:5px}.booking-progress li{display:grid;justify-items:center;gap:5px;text-align:center;white-space:normal}.booking-progress-label{font-size:.66rem;line-height:1.2}.booking-step-actions{align-items:stretch}.booking-step-actions .button{min-width:0;flex:1}.booking-submit-row{align-items:stretch;flex-direction:column}.booking-submit-row .button{width:100%}}
    @media(max-width:480px){.booking-section{padding:15px}.pet-options{grid-template-columns:1fr}.calendar-legend{gap:8px 12px}.calendar-legend span{font-size:.7rem}}
    @media(prefers-reduced-motion:reduce){html,.page-content{scroll-behavior:auto}.dashboard-home *,.booking-panel *{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
    body.client-app{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5}
    body.client-app .topbar h1,body.client-app .topbar-title h1{font-size:1.4rem;line-height:1.2}
    body.client-app .topbar p,body.client-app .topbar-title p,body.client-app .page-intro p{font-size:.875rem}
    body.client-app .page-intro h2{font-size:1.25rem}
    body.client-app .panel,body.client-app .booking-panel{border-radius:14px}
    body.client-app .panel-header{min-height:44px;padding:9px 14px}
    body.client-app .panel-header h2,body.client-app .panel-title h2{font-size:1rem}
    body.client-app .button,body.client-app button.button{min-height:40px;font-size:.85rem;line-height:1.25}
    body.client-app .button.small{min-height:34px;font-size:.8rem}
    body.client-app .records-table thead th,body.client-app .records-table th{font-size:.75rem}
    body.client-app .records-table tbody td,body.client-app .records-table td{font-size:.84rem;line-height:1.4}
    body.client-app .stat-label{font-size:.75rem}
    body.client-app .stat-value{font-size:1.75rem}
    body.client-app .status{font-size:.75rem}
    body.client-app .form-grid label,body.client-app label.field,body.client-app .field-label{font-size:.82rem}
    body.client-app input,body.client-app select,body.client-app textarea{font-family:inherit;font-size:.875rem}
    body.client-app .hint,body.client-app .form-note,body.client-app .data-count{font-size:.8rem}
    @media(max-width:760px){body.client-app .topbar h1,body.client-app .topbar-title h1{font-size:1.12rem}body.client-app .topbar p,body.client-app .topbar-title p{font-size:.78rem}}
    @media(max-width:480px){body.client-app .topbar h1,body.client-app .topbar-title h1{font-size:1.05rem}body.client-app .page-intro h2{font-size:1.15rem}body.client-app .panel-header{padding:9px 11px}body.client-app .panel-header h2,body.client-app .panel-title h2{font-size:.95rem}body.client-app .panel-body{padding:12px}}
  </style>
  <link rel="stylesheet" href="../assets/css/data-tables.css">
  <link rel="stylesheet" href="../assets/css/admin-professional.css">
  <link rel="stylesheet" href="../assets/css/dashboard-banner.css">
</head>
<body class="client-app">
  <div class="app-shell">
    <aside class="sidebar" id="client-navigation">
      <a class="brand-lockup" href="client_dashboard.php"><img class="brand-logo" src="../assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="<?= e(CLINIC_INFO['name']) ?>"><span class="brand-copy"><?= e(CLINIC_INFO['name']) ?><span>Client Portal</span></span></a>
      <nav class="side-nav" aria-label="Client navigation">
        <?php foreach ($nav as $key => $n): ?>
          <a class="nav-item<?= $key === $active ? ' active' : '' ?>" href="<?= e($n[0]) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><?= $n[2] ?></svg><span><?= e($n[1]) ?></span></a>
        <?php endforeach; ?>
        <a class="nav-item logout-link" href="../logout.php?session_context=client"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg><span>Logout</span></a>
      </nav>
    </aside>
    <div class="main-area">
      <header class="topbar">
        <div class="topbar-leading">
          <button class="admin-hamburger" id="client-sidebar-toggle" type="button" aria-label="Toggle navigation" aria-controls="client-navigation" aria-expanded="true" title="Toggle navigation">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <div class="topbar-title"><h1><?= e($title) ?></h1><p><?= e($subtitle) ?></p></div>
        </div>
        <div class="profile-area">
          <details class="notification-menu" id="notification-menu">
            <summary class="notification" id="notification-summary" aria-label="Open clinic notifications" title="Clinic notifications">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"/></svg>
              <?php if ($notificationCount > 0): ?><span class="notification-count"><?= $notificationCount > 99 ? '99+' : $notificationCount ?></span><?php endif; ?>
            </summary>
            <div class="notification-panel">
              <h2>Clinic notifications</h2>
              <div id="notification-items" aria-live="polite">
                <?php if ($notificationError): ?><p class="notification-empty error" role="alert"><?= e($notificationError) ?></p>
                <?php elseif ($notificationItems): ?>
                  <?php foreach ($notificationItems as $item): ?>
                    <a class="notification-item" href="<?= e($item['href']) ?>" data-notification-id="<?= (int) $item['id'] ?>">
                      <span class="notification-dot <?= e($item['kind']) ?>" aria-hidden="true"></span>
                      <span class="notification-copy"><strong><?= e($item['label']) ?></strong><small><?= e($item['detail']) ?></small></span>
                    </a>
                  <?php endforeach; ?>
                <?php else: ?><p class="notification-empty">No notifications right now.</p><?php endif; ?>
              </div>
              <div class="notification-panel-footer"><button class="notification-mark-all" type="button" id="notification-mark-all">Mark all as read</button></div>
            </div>
          </details>
          <span class="profile-divider" aria-hidden="true"></span>
          <a class="profile-display" href="client_settings.php" aria-label="Account settings">
          <span class="profile-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><span class="profile-copy"><span class="profile-name"><?= e($name) ?></span><small>Pet owner</small></span>
          </a>
        </div>
      </header>
      <main class="page-content" id="page-content">
        <?php if ($c['dbError']): ?><div class="notice error" role="alert"><?= e($c['dbError']) ?></div><?php endif; ?>
        <?php $ok = $_GET['ok'] ?? ''; if (isset(FLASH[$ok])): ?><div class="notice ok" role="status"><?= e(FLASH[$ok]) ?></div><?php endif; ?>
<?php
}

function client_footer(array $c, bool $siteFooter = false): void
{
    if ($siteFooter) {
        ?>
        <footer class="site-footer">
          <div class="footer-main">
            <div class="footer-brand">
              <img src="../assets/images/Animal Clinic Heart Emblem Transparent.png" alt="">
              <div><strong><?= e(CLINIC_INFO['name']) ?></strong><p>Care, guidance, and practical tools to help you look after your pet.</p></div>
            </div>
            <nav class="footer-links" aria-label="Footer navigation">
              <span class="footer-heading">Explore</span>
              <a href="#about">About the clinic</a>
              <a href="#services">Our services</a>
              <a href="#gallery">Clinic gallery</a>
              <a href="#contact">Contact us</a>
            </nav>
            <div class="footer-contact">
              <span class="footer-heading">Get in touch</span>
              <span><?= e(CLINIC_INFO['address']) ?></span>
              <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', CLINIC_INFO['phone'])) ?>"><?= e(CLINIC_INFO['phone']) ?></a>
              <a href="mailto:<?= e(CLINIC_INFO['email']) ?>"><?= e(CLINIC_INFO['email']) ?></a>
            </div>
          </div>
          <div class="footer-bottom">
            <span>© <?= date('Y') ?> <?= e(CLINIC_INFO['name']) ?>. All rights reserved.</span>
            <a href="#page-content">Back to top</a>
          </div>
        </footer>
<?php
    }
    ?>
      </main>
    </div>
  </div>
  <script src="../assets/JS/client-shell.js" defer></script>
  <script>
    (function () {
      document.querySelectorAll('[data-live-search]').forEach(function (input) {
        var target = document.getElementById(input.dataset.searchTarget);
        var count = document.getElementById(input.dataset.searchCount);
        var empty = document.getElementById(input.dataset.searchEmpty);
        if (!target) return;
        var items = Array.prototype.slice.call(target.querySelectorAll('[data-search-item]'));
        function apply() {
          var query = input.value.trim().toLocaleLowerCase();
          var shown = 0;
          items.forEach(function (item) {
            var matches = !query || item.textContent.toLocaleLowerCase().indexOf(query) !== -1;
            item.hidden = !matches;
            if (item.tagName === 'TR') item.classList.toggle('alt', matches && shown % 2 === 1);
            if (matches) shown++;
          });
          if (count) count.textContent = shown + ' of ' + items.length + ' shown';
          if (empty) empty.hidden = shown !== 0 || query === '';
        }
        input.addEventListener('input', apply);
        apply();
      });
    })();
  </script>
  <script src="../assets/JS/notifications.js" defer></script>
  <?php if (!$c['dbError'] && $c['fullName'] === ''): ?>
  <dialog id="name-dialog" aria-labelledby="nd-title">
    <div class="nd-top"><img src="../assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt=""><h2 id="nd-title">Welcome to <?= e(CLINIC_INFO['name']) ?></h2><p>Before we begin, what’s your name?</p></div>
    <form class="dlg-form" method="post" action="<?= e(basename($_SERVER['PHP_SELF'])) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_name">
      <?php if ($c['nameError']): ?><div class="notice error" role="alert" style="margin:0"><?= e($c['nameError']) ?></div><?php endif; ?>
      <label class="field">Full name<input type="text" name="full_name" required minlength="2" maxlength="100" autocomplete="name" placeholder="e.g. Maria Santos" autofocus></label>
      <button class="button" type="submit">Continue</button>
    </form>
  </dialog>
  <script>(function(){var d=document.getElementById('name-dialog');d.addEventListener('cancel',function(e){e.preventDefault()});d.showModal();})();</script>
  <?php endif; ?>
</body>
</html>
<?php
}