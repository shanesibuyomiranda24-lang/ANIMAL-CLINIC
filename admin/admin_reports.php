<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_role(['admin']);

$today = date('Y-m-d');
$fromDefault = date('Y-m-01');
$fromValue = $_GET['from'] ?? $fromDefault;
$toValue = $_GET['to'] ?? $today;
$dateError = '';
$isValidDate = function ($value): bool {
    if (!is_string($value)) {
        return false;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};
if (!$isValidDate($fromValue) || !$isValidDate($toValue) || $fromValue > $toValue) {
    $dateError = 'Choose a valid date range. Showing this month to date.';
    $fromDate = $fromDefault;
    $toDate = $today;
} else {
    $fromDate = $fromValue;
    $toDate = $toValue;
    if ($toDate > $today) {
        $toDate = $today; // never report on the future
        if ($fromDate > $toDate) {
            $fromDate = $toDate;
        }
    }
}

// Quick ranges (shown as the left column of the date picker)
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$presets = [
    'Today'       => [$today, $today],
    'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), $today],
    'This week'   => [date('Y-m-d', strtotime('monday this week')), $today],
    'This month'  => [date('Y-m-01'), $today],
    'Last month'  => [$lastMonthStart, date('Y-m-t', strtotime($lastMonthStart))],
    'This year'   => [date('Y-01-01'), $today],
];
$activeRange = [$fromDate, $toDate];

// Human label for the chosen period (used on screen and in the download)
$periodLabel = null;
foreach ($presets as $label => $range) {
    if ($range === $activeRange) {
        $periodLabel = $label;
        break;
    }
}
if ($periodLabel === null) {
    // A full calendar month (or the current month to date) gets its month name
    $fromTs = strtotime($fromDate);
    $monthEnd = min(date('Y-m-t', $fromTs), $today);
    if ($fromDate === date('Y-m-01', $fromTs) && $toDate === $monthEnd) {
        $periodLabel = date('F Y', $fromTs);
    }
}
if ($periodLabel === null) {
    $periodLabel = 'Custom range';
}
$rangeLabel = date('M j, Y', strtotime($fromDate)) . ' – ' . date('M j, Y', strtotime($toDate));

// Previous period of the same length (for the sales comparison)
$fromDt = new DateTime($fromDate);
$toDt = new DateTime($toDate);
$periodDays = $fromDt->diff($toDt)->days + 1;
$prevTo = (clone $fromDt)->modify('-1 day');
$prevFrom = (clone $prevTo)->modify('-' . ($periodDays - 1) . ' days');

// Data handed to the date picker script
$pickerConfig = [
    'from'    => $fromDate,
    'to'      => $toDate,
    'today'   => $today,
    'presets' => array_map(
        fn($label, $range) => ['label' => $label, 'from' => $range[0], 'to' => $range[1]],
        array_keys($presets),
        array_values($presets)
    ),
];

$summary = ['sales' => 0.0, 'transactions' => 0, 'appointments' => 0, 'completed' => 0, 'scheduled' => 0, 'cancelled' => 0];
$prevSales = 0.0;
$balanceSummary = ['balance_count' => 0, 'balance_total' => 0.0];
$transactions = [];
$balances = [];
$methods = [];
$statusCounts = [];
$topServices = [];
$chart = [];
$chartMonthly = $periodDays > 62;
$error = '';
try {
    $pdo = db();

    // Sales in the selected period
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) AS sales, COUNT(*) AS transactions
         FROM Payments
         WHERE payment_status = 'Recorded'
           AND payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY)"
    );
    $statement->execute([$fromDate, $toDate]);
    $salesData = $statement->fetch() ?: [];
    $summary['sales'] = (float) ($salesData['sales'] ?? 0);
    $summary['transactions'] = (int) ($salesData['transactions'] ?? 0);

    // Sales in the previous period
    $statement->execute([$prevFrom->format('Y-m-d'), $prevTo->format('Y-m-d')]);
    $prevData = $statement->fetch() ?: [];
    $prevSales = (float) ($prevData['sales'] ?? 0);

    // Appointment outcomes in the selected period
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS appointments,
                COALESCE(SUM(status = 'Complete'), 0) AS completed,
                COALESCE(SUM(status = 'Scheduled'), 0) AS scheduled,
                COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled
         FROM Appointments
         WHERE appointment_date >= ? AND appointment_date < DATE_ADD(?, INTERVAL 1 DAY)"
    );
    $statement->execute([$fromDate, $toDate]);
    $appointmentData = $statement->fetch() ?: [];
    $summary['appointments'] = (int) ($appointmentData['appointments'] ?? 0);
    $summary['completed'] = (int) ($appointmentData['completed'] ?? 0);
    $summary['scheduled'] = (int) ($appointmentData['scheduled'] ?? 0);
    $summary['cancelled'] = (int) ($appointmentData['cancelled'] ?? 0);

    // Appointments by status (all five statuses)
    $statement = $pdo->prepare(
        'SELECT status, COUNT(*) AS total
         FROM Appointments
         WHERE appointment_date >= ? AND appointment_date < DATE_ADD(?, INTERVAL 1 DAY)
         GROUP BY status'
    );
    $statement->execute([$fromDate, $toDate]);
    $statusRaw = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['Scheduled', 'Check-in', 'In Consultation', 'Complete', 'Cancelled'] as $label) {
        $statusCounts[$label] = (int) ($statusRaw[$label] ?? 0);
    }

    // Sales by payment method
    $statement = $pdo->prepare(
        "SELECT COALESCE(NULLIF(payment_method, ''), 'Unspecified') AS method,
                COUNT(*) AS total, SUM(amount_paid) AS amount
         FROM Payments
         WHERE payment_status = 'Recorded'
           AND payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY)
         GROUP BY method
         ORDER BY amount DESC"
    );
    $statement->execute([$fromDate, $toDate]);
    $methods = $statement->fetchAll();

    // Top 5 services by billed revenue (cancelled appointments excluded)
    $statement = $pdo->prepare(
        "SELECT s.service_name, COUNT(*) AS times_used, SUM(aps.charged_price) AS revenue
         FROM AppointmentServices aps
         INNER JOIN Appointments a ON a.appointment_id = aps.appointment_id
         INNER JOIN Services s ON s.service_id = aps.service_id
         WHERE a.status <> 'Cancelled'
           AND a.appointment_date >= ? AND a.appointment_date < DATE_ADD(?, INTERVAL 1 DAY)
         GROUP BY s.service_id, s.service_name
         ORDER BY revenue DESC, times_used DESC
         LIMIT 5"
    );
    $statement->execute([$fromDate, $toDate]);
    $topServices = $statement->fetchAll();

    // Sales trend: per day, or per month for long ranges
    $bucketFormat = $chartMonthly ? '%Y-%m' : '%Y-%m-%d';
    $statement = $pdo->prepare(
        "SELECT DATE_FORMAT(payment_date, '$bucketFormat') AS bucket, SUM(amount_paid) AS total
         FROM Payments
         WHERE payment_status = 'Recorded'
           AND payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY)
         GROUP BY bucket"
    );
    $statement->execute([$fromDate, $toDate]);
    $bucketTotals = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($chartMonthly) {
        $cursor = new DateTime($fromDt->format('Y-m-01'));
        while ($cursor <= $toDt) {
            $key = $cursor->format('Y-m');
            $chart[] = ['label' => $cursor->format('M Y'), 'total' => (float) ($bucketTotals[$key] ?? 0)];
            $cursor->modify('+1 month');
        }
    } else {
        $cursor = clone $fromDt;
        while ($cursor <= $toDt) {
            $key = $cursor->format('Y-m-d');
            $chart[] = ['label' => $cursor->format('M j'), 'total' => (float) ($bucketTotals[$key] ?? 0)];
            $cursor->modify('+1 day');
        }
    }

    // Unpaid balances: total charged for all services on a completed appointment minus recorded payments.
    $balanceSource = "SELECT a.appointment_id, o.full_name AS client_name, p.pet_name,
                             c.service_names, c.total_charged,
                             COALESCE(pay.total_paid, 0) AS amount_paid,
                             c.total_charged - COALESCE(pay.total_paid, 0) AS balance
                      FROM Appointments a
                      INNER JOIN Pets p ON p.pet_id = a.pet_id
                      INNER JOIN PetOwners o ON o.owner_id = p.owner_id
                      INNER JOIN (
                          SELECT aps.appointment_id,
                                 SUM(aps.charged_price) AS total_charged,
                                 GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ') AS service_names
                          FROM AppointmentServices aps
                          INNER JOIN Services s ON s.service_id = aps.service_id
                          GROUP BY aps.appointment_id
                      ) c ON c.appointment_id = a.appointment_id
                      LEFT JOIN (
                          SELECT appointment_id, SUM(amount_paid) AS total_paid
                          FROM Payments
                          WHERE payment_status = 'Recorded'
                          GROUP BY appointment_id
                      ) pay ON pay.appointment_id = a.appointment_id
                      WHERE a.status = 'Complete'
                        AND c.total_charged > COALESCE(pay.total_paid, 0)";
    $statement = $pdo->prepare('SELECT COUNT(*) AS balance_count, COALESCE(SUM(balance), 0) AS balance_total FROM (' . $balanceSource . ') AS due');
    $statement->execute();
    $balanceSummary = $statement->fetch() ?: $balanceSummary;
    $statement = $pdo->prepare($balanceSource . ' ORDER BY balance DESC, a.appointment_id DESC');
    $statement->execute();
    $balances = $statement->fetchAll();

    // Every transaction in the selected period
    $statement = $pdo->prepare(
        'SELECT pay.payment_id, pay.amount_paid, pay.payment_date, pay.payment_method, o.full_name AS client_name
         FROM Payments pay
         INNER JOIN Appointments a ON a.appointment_id = pay.appointment_id
         INNER JOIN Pets p ON p.pet_id = a.pet_id
         INNER JOIN PetOwners o ON o.owner_id = p.owner_id
         WHERE pay.payment_date >= ? AND pay.payment_date < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY pay.payment_date DESC, pay.payment_id DESC'
    );
    $statement->execute([$fromDate, $toDate]);
    $transactions = $statement->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $error = 'Report data is unavailable. Check the database connection and required table names.';
}

/* ------------------------------------------------------------------
   CSV download: one file, organised into sections for the chosen period
------------------------------------------------------------------ */
if (($_GET['download'] ?? '') === 'csv') {
    if ($error) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $error;
        exit;
    }
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $periodLabel), '-'));
    $filename = 'clinic-report-' . $slug . '-' . $fromDate . '_to_' . $toDate . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // keeps the peso sign readable in Excel
    $out = fopen('php://output', 'w');
    $safe = function ($v) {
        if (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) {
            return "'" . $v;
        }
        return $v;
    };
    $put = function (array $row) use ($out, $safe): void {
        fputcsv($out, array_map($safe, $row), ',', '"', '', "\r\n");
    };
    $money = function ($v): string {
        return number_format((float) $v, 2, '.', '');
    };

    $put(['ANIMAL CLINIC - CLINIC REPORT']);
    $put(['Period', $periodLabel]);
    $put(['Date range', $rangeLabel]);
    $put(['Generated', date('M j, Y g:i A')]);
    $put([]);

    $put(['SUMMARY']);
    $put(['Metric', 'Value']);
    $put(['Recorded sales (PHP)', $money($summary['sales'])]);
    $put(['Transactions', $summary['transactions']]);
    $put(['Average per transaction (PHP)', $money($summary['transactions'] > 0 ? $summary['sales'] / $summary['transactions'] : 0)]);
    $put(['Previous period sales (PHP)', $money($prevSales)]);
    $put(['Appointments', $summary['appointments']]);
    $put(['Completed appointments', $summary['completed']]);
    $put(['Scheduled appointments', $summary['scheduled']]);
    $put(['Cancelled appointments', $summary['cancelled']]);
    $put(['Outstanding balance (PHP)', $money($balanceSummary['balance_total'])]);
    $put(['Appointments with balance', (int) $balanceSummary['balance_count']]);
    $put([]);

    $put(['SALES BY PAYMENT METHOD']);
    $put(['Method', 'Payments', 'Amount (PHP)']);
    foreach ($methods as $r) {
        $put([$r['method'], (int) $r['total'], $money($r['amount'])]);
    }
    $put([]);

    $put(['APPOINTMENTS BY STATUS']);
    $put(['Status', 'Appointments']);
    foreach ($statusCounts as $label => $total) {
        $put([$label, $total]);
    }
    $put([]);

    $put(['TOP 5 SERVICES BY REVENUE']);
    $put(['Rank', 'Service', 'Times billed', 'Revenue (PHP)']);
    foreach ($topServices as $i => $r) {
        $put([$i + 1, $r['service_name'], (int) $r['times_used'], $money($r['revenue'])]);
    }
    $put([]);

    $put([$chartMonthly ? 'SALES BY MONTH' : 'SALES BY DAY']);
    $put([$chartMonthly ? 'Month' : 'Day', 'Sales (PHP)']);
    foreach ($chart as $point) {
        $put([$point['label'], $money($point['total'])]);
    }
    $put([]);

    $put(['TRANSACTIONS IN PERIOD']);
    $put(['Transaction ID', 'Client', 'Amount (PHP)', 'Date', 'Method']);
    foreach ($transactions as $r) {
        $put(['TRX-' . (int) $r['payment_id'], $r['client_name'], $money($r['amount_paid']), date('M j, Y g:i A', strtotime($r['payment_date'])), $r['payment_method'] ?: '-']);
    }
    $put([]);

    $put(['UNPAID CLIENT BALANCES (all completed appointments)']);
    $put(['Client', 'Pet', 'Appointment', 'Service', 'Total charged (PHP)', 'Paid (PHP)', 'Balance due (PHP)']);
    foreach ($balances as $r) {
        $put([$r['client_name'], $r['pet_name'], '#' . (int) $r['appointment_id'], $r['service_names'], $money($r['total_charged']), $money($r['amount_paid']), $money($r['balance'])]);
    }
    fclose($out);
    exit;
}

// Sales change versus the previous period
if ($prevSales > 0) {
    $change = (($summary['sales'] - $prevSales) / $prevSales) * 100;
    $salesTrendClass = $change >= 0 ? 'up' : 'down';
    $salesTrendText = ($change >= 0 ? '▲ ' : '▼ ') . number_format(abs($change), 1) . '% vs previous period';
} elseif ($summary['sales'] > 0) {
    $salesTrendClass = 'up';
    $salesTrendText = '▲ No sales in previous period';
} else {
    $salesTrendClass = '';
    $salesTrendText = 'No sales in either period';
}

$pct = function (float $value, float $max): float {
    return $max > 0 ? round(($value / $max) * 100, 1) : 0.0;
};
$statusClass = function ($value): string {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $value), '_'));
};
$chartMax = $chart ? max(array_column($chart, 'total')) : 0;
$chartPeak = null;
foreach ($chart as $point) {
    if ($point['total'] > 0 && ($chartPeak === null || $point['total'] > $chartPeak['total'])) {
        $chartPeak = $point;
    }
}
$barColors = ['#0284c7', '#23935b', '#7a4fc4', '#e59824', '#e0527a', '#14a3a3'];
$methodColors = [
    'cash' => '#3aa66f', 'gcash' => '#2f7de1', 'maya' => '#14a38b', 'paymaya' => '#14a38b',
    'credit_card' => '#7a4fc4', 'card' => '#7a4fc4', 'debit_card' => '#4a55c9',
    'bank_transfer' => '#e0792b', 'bank' => '#e0792b',
];
$statusColors = [
    'scheduled' => '#4aa3d8', 'check_in' => '#8a6bd1', 'in_consultation' => '#e0a030',
    'complete' => '#3aa66f', 'cancelled' => '#d25a5a',
];
$methodTotal = $methods ? (float) array_sum(array_column($methods, 'amount')) : 0;
$methodMax = $methods ? (float) max(array_column($methods, 'amount')) : 0;
$statusMax = $statusCounts ? max($statusCounts) : 0;
$serviceMax = $topServices ? (float) max(array_column($topServices, 'revenue')) : 0;
$serviceTotal = $topServices ? (float) array_sum(array_column($topServices, 'revenue')) : 0;
$downloadUrl = 'admin_reports.php?from=' . $fromDate . '&to=' . $toDate . '&download=csv';

require_once __DIR__ . '/../includes/admin_notifications.php';
$notifications = ['count' => 0, 'items' => [], 'error' => ''];
try {
    $noticeDb = db();
    $notifications = dashboard_notification_state(
        $noticeDb,
        (int) $_SESSION['user_id']
    );
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $notifications['error'] = 'Notification data is unavailable.';
}
$notificationBadge = $notifications['count'] > 99 ? '99+' : (string) $notifications['count'];
$pageTitle = 'Reports';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($pageTitle) ?> | Animal Clinic</title>
  <script src="../assets/JS/admin-sidebar-state.js"></script>
  <style>
    :root{--blue-50:#eaf3fa;--blue-100:#c2dcf1;--blue-300:#81b7e0;--blue-500:#0284c7;--blue-700:#015b8c;--blue-900:#002e4a;--ink:#092947;--muted:#42637e;--line:#d7e8f5;--grid-line:#d3e3ef;--grid-strong:#8fbadb}
    *{box-sizing:border-box}html,body{width:100%;height:100%;margin:0}
    body.admin-dashboard{min-width:320px;height:100vh;height:100dvh;overflow:hidden;color:var(--ink);background:#f4f9fd;font:14px/1.45 Arial,Helvetica,sans-serif}
    .admin-dashboard a{color:inherit}.app-shell{width:100%;height:100%;display:grid;grid-template-columns:198px minmax(0,1fr);overflow:hidden}

    /* ---------- Sidebar / top bar (same as dashboard) ---------- */
    .sidebar{position:relative;z-index:2;min-width:0;padding:20px 10px 16px;overflow:hidden auto;color:#fff;background:linear-gradient(180deg,var(--blue-700),var(--blue-900))}
    .sidebar:after{position:absolute;z-index:-1;right:-60px;bottom:0;left:-60px;height:210px;background:url("../assets/images/Abstract%20Blue%20Wave%20Gradient.png") center bottom/cover no-repeat;opacity:.34;content:"";pointer-events:none}
    .brand-lockup{margin:2px 0 28px;display:flex;align-items:center;flex-direction:column;gap:8px;color:#fff;text-align:center;text-decoration:none}
    .brand-logo{width:94px;height:94px;display:block;object-fit:cover;background:#fff;border:2px solid rgba(255,255,255,.55);border-radius:50%}
    .brand-copy{font-size:.92rem;font-weight:700;line-height:1.35}.brand-copy span{display:block;font-size:.82rem;font-weight:400}
    .side-nav{display:grid;gap:5px}.nav-item{min-width:0;min-height:46px;padding:10px 12px;display:grid;grid-template-columns:23px minmax(0,1fr);align-items:center;gap:12px;color:#eaf3fa;border-radius:10px;font-size:.92rem;text-decoration:none;transition:background-color .15s ease}
    .nav-item svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:2}.nav-item:first-child svg path{fill:currentColor;stroke:none}
    .nav-item:hover,.nav-item.active{color:#fff;background:var(--blue-500)}.logout-link{margin-top:16px}
    .main-area{min-width:0;min-height:0;display:grid;grid-template-rows:76px minmax(0,1fr);overflow:hidden}
    .topbar{position:relative;z-index:5;min-width:0;padding:10px clamp(18px,2.4vw,36px);display:flex;align-items:center;justify-content:space-between;gap:16px;background:linear-gradient(100deg,var(--blue-50),#fff 76%);border-bottom:1px solid var(--line);box-shadow:0 2px 9px rgba(0,46,74,.06)}
    .topbar-title h1{margin:0;color:var(--blue-900);font-size:1.48rem;line-height:1.2}.topbar-title p{margin:2px 0 0;color:var(--blue-700);font-size:.91rem}
    .profile-area{display:flex;align-items:center;gap:12px;color:var(--blue-900)}.notification-menu{position:relative}
    .notification{position:relative;width:40px;height:40px;padding:0;display:grid;place-items:center;color:var(--blue-700);background:#fff;border:1px solid var(--line);border-radius:11px;list-style:none;cursor:pointer;transition:background-color .15s,border-color .15s}.notification::-webkit-details-marker{display:none}
    .notification svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .notification:hover,.notification-menu[open]>.notification{background:var(--blue-50);border-color:#b8d2e5}.notification:focus-visible{outline:3px solid rgba(2,132,199,.28);outline-offset:3px}
    .notification-count{position:absolute;top:-3px;right:-5px;min-width:18px;height:18px;padding:0 4px;display:grid;place-items:center;color:#fff;background:#e33b43;border:2px solid #fff;border-radius:999px;font-size:.64rem;font-weight:700}
    .notification-panel{position:absolute;top:calc(100% + 13px);right:-18px;z-index:8;width:min(360px,calc(100vw - 24px));max-height:min(460px,calc(100dvh - 100px));padding:8px;overflow-y:auto;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:13px;box-shadow:0 14px 36px rgba(0,46,74,.2)}
    .notification-panel h2{margin:4px 8px 8px;color:var(--blue-900);font-size:.95rem}.notification-item{padding:9px 8px;display:grid;grid-template-columns:10px minmax(0,1fr) auto;align-items:center;gap:9px;border-top:1px solid #edf3f8;text-decoration:none}
    .notification-item:hover{background:var(--blue-50)}.notification-dot{width:9px;height:9px;border-radius:50%;background:var(--blue-500)}.notification-dot.inventory,.notification-dot.billing{background:#e59824}.notification-dot.transaction{background:#39a267}
    .notification-copy{min-width:0;display:grid;gap:2px}.notification-copy strong{color:var(--blue-900);font-size:.8rem}.notification-copy small,.notification-empty{color:var(--muted);font-size:.75rem}
    .notification-item-count{color:var(--blue-700);font-size:.78rem;font-weight:700}.notification-empty{margin:0;padding:14px 8px;text-align:center}.notification-empty.error{color:#8c3232}
    .profile-divider{width:1px;height:34px;margin:0 5px;background:var(--blue-300)}.profile-display{min-width:0;display:flex;align-items:center;gap:9px;color:inherit;text-decoration:none;transition:opacity .15s ease}
    .profile-display:hover{opacity:.82}.profile-display:focus-visible{outline:3px solid rgba(2,132,199,.28);outline-offset:4px;border-radius:8px}
    .profile-avatar{width:42px;height:42px;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-100);border-radius:50%}.profile-avatar svg{width:27px;height:27px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .profile-copy{min-width:0}.profile-name{max-width:180px;overflow:hidden;color:var(--blue-900);font-weight:700;text-overflow:ellipsis;white-space:nowrap}
    .page-content{width:100%;max-width:none;min-width:0;min-height:0;margin:0;padding:12px clamp(14px,1.8vw,26px) 24px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain}
    .notice{margin-bottom:14px;padding:11px 14px;border-radius:9px}.notice.error{color:#842626;background:#fdeaea}.notice.info{color:var(--blue-900);background:var(--blue-50)}

    /* ---------- Buttons, filters ---------- */
    .button,button.button{min-height:40px;padding:8px 14px;display:inline-flex;align-items:center;justify-content:center;gap:6px;color:#fff;background:var(--blue-500);border:1px solid var(--blue-500);border-radius:8px;font:inherit;font-size:.84rem;font-weight:700;text-decoration:none;cursor:pointer;transition:background-color .15s ease}
    .button:hover,button.button:hover{background:var(--blue-700);border-color:var(--blue-700)}.button.secondary{color:var(--blue-700);background:#fff;border-color:#b8d2e5}.button.secondary:hover{background:var(--blue-50)}
    .button:disabled{color:#9fb6c8;background:#eef4f9;border-color:#dce8f1;cursor:not-allowed}
    .toolbar{margin:0;display:flex;align-items:flex-end;flex-wrap:wrap;gap:9px}.toolbar label{min-width:150px;flex:1 1 170px;display:grid;gap:5px;color:var(--blue-900);font-size:.79rem;font-weight:700}
    .toolbar.search{margin:0 0 13px}
    .toolbar input{width:100%;min-width:0;min-height:41px;padding:9px 11px;color:var(--ink);background:#fff;border:1px solid #bed4e5;border-radius:8px;font:inherit}
    .toolbar input:focus{outline:3px solid rgba(2,132,199,.16);border-color:var(--blue-500)}

    /* ---------- Panels ---------- */
    .panel{min-width:0;margin:0 0 16px;overflow:visible;background:#fff;border:1px solid #cbdfee;border-radius:16px;box-shadow:0 6px 18px rgba(0,46,74,.11)}
    .panel-header{min-height:48px;padding:9px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:#fff;background:linear-gradient(105deg,#015b8c,#002e4a);border-radius:15px 15px 0 0}
    .panel-header h2{margin:0;color:inherit;font-size:.98rem}.panel-body{min-width:0;padding:16px}
    .data-count{color:#eaf3fa;font-size:.78rem;font-weight:700}.form-note{margin:0 0 13px;color:var(--muted);font-size:.83rem}
    .panel>.table-wrap,.panel>.panel-body:last-child{border-radius:0 0 15px 15px}

    /* ---------- Download menu (one button, two options) ---------- */
    .dl-menu{position:relative}
    .dl-menu>summary{min-height:34px;padding:6px 14px;display:inline-flex;align-items:center;gap:7px;color:#fff;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.5);border-radius:8px;font-size:.8rem;font-weight:700;list-style:none;cursor:pointer;transition:background-color .15s ease}
    .dl-menu>summary::-webkit-details-marker{display:none}.dl-menu>summary:hover,.dl-menu[open]>summary{background:rgba(255,255,255,.26)}
    .dl-menu svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:2.2}
    .dl-panel{position:absolute;top:calc(100% + 8px);right:0;z-index:9;width:260px;padding:6px;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 14px 36px rgba(0,46,74,.22)}
    .dl-item{width:100%;padding:10px 12px;display:grid;gap:2px;color:var(--blue-900);background:none;border:0;border-radius:8px;font:inherit;text-align:left;text-decoration:none;cursor:pointer}
    .dl-item:hover{background:var(--blue-50)}.dl-item strong{font-size:.84rem}.dl-item small{color:var(--muted);font-size:.74rem}

    /* ---------- Period picker: one trigger, one popover (presets + range calendar) ---------- */
    .period-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px}
    .period-wrap{position:relative}
    .period-trigger{min-height:50px;padding:7px 14px 7px 12px;display:inline-flex;align-items:center;gap:12px;color:var(--blue-900);background:#fff;border:1px solid #b8d2e5;border-radius:12px;font:inherit;text-align:left;cursor:pointer;transition:border-color .15s ease,box-shadow .15s ease}
    .period-trigger:hover{border-color:var(--blue-300)}
    .period-trigger:focus-visible,.period-trigger[aria-expanded="true"]{outline:0;border-color:var(--blue-500);box-shadow:0 0 0 3px rgba(2,132,199,.16)}
    .period-trigger svg{width:20px;height:20px;flex:none;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.9}
    .period-trigger .ico-cal{color:var(--blue-500)}.period-trigger .ico-chev{width:16px;height:16px;color:var(--muted);transition:transform .15s ease}
    .period-trigger[aria-expanded="true"] .ico-chev{transform:rotate(180deg)}
    .period-text{display:grid;gap:1px;min-width:0}
    .period-name{color:var(--muted);font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase}
    .period-range{color:var(--blue-900);font-size:.92rem;font-weight:700;white-space:nowrap}
    .period-meta{margin:0;color:var(--muted);font-size:.8rem}.period-meta b{color:var(--blue-900)}
    .period-reset{margin-left:auto;white-space:nowrap}

    .period-pop{position:absolute;top:calc(100% + 8px);left:0;z-index:50;width:max-content;max-width:calc(100vw - 32px);display:grid;grid-template-columns:168px max-content;overflow:hidden;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 44px rgba(0,46,74,.24)}
    .period-pop[hidden]{display:none}
    .pop-presets{padding:12px 8px;display:flex;flex-direction:column;gap:2px;border-right:1px solid #e6f0f8}
    .pop-title{margin:0 0 6px;padding:0 10px;color:var(--muted);font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase}
    .preset{width:100%;padding:9px 10px;color:var(--blue-900);background:none;border:0;border-radius:8px;font:inherit;font-size:.84rem;font-weight:600;text-align:left;cursor:pointer}
    .preset:hover{background:var(--blue-50)}.preset.active{color:var(--blue-700);background:var(--blue-50);font-weight:700}
    .pop-main{min-width:0;display:flex;flex-direction:column;background:#fff}
    .cal-months{padding:14px 18px 8px;display:flex;flex-wrap:nowrap;gap:26px}
    .cal-month{width:266px}
    .cal-label{margin:0 0 6px;color:var(--muted);font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;text-align:center}
    .cal-head{height:36px;margin-bottom:6px;display:grid;grid-template-columns:32px 1fr 32px;align-items:center;gap:4px}
    .cal-title{min-width:0;display:flex;justify-content:center;gap:2px}
    .cal-select{min-height:30px;padding:2px;color:var(--blue-900);background:#fff;border:1px solid transparent;border-radius:8px;font:inherit;font-size:.88rem;font-weight:700;cursor:pointer}
    .cal-select:hover{border-color:#b8d2e5;background:var(--blue-50)}
    .cal-select:focus-visible{outline:3px solid rgba(2,132,199,.25)}
    .cal-nav{width:30px;height:30px;display:grid;place-items:center;color:var(--blue-700);background:none;border:1px solid transparent;border-radius:8px;font:inherit;font-size:1.1rem;font-weight:700;cursor:pointer}
    .cal-nav:hover{background:var(--blue-50);border-color:#b8d2e5}.cal-nav:disabled{color:#c3d3df;background:none;border-color:transparent;cursor:default}
    .cal-grid{display:grid;grid-template-columns:repeat(7,1fr);row-gap:2px}
    .cal-dow{height:28px;display:grid;place-items:center;color:var(--muted);font-size:.7rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
    .cell{height:38px;display:grid;place-items:center}
    .cell.in-range{background:var(--blue-50)}
    .cell.range-start{background:linear-gradient(90deg,transparent 50%,var(--blue-50) 50%)}
    .cell.range-end{background:linear-gradient(90deg,var(--blue-50) 50%,transparent 50%)}
    .cell.range-start.range-end{background:none}
    .day{width:36px;height:36px;padding:0;display:grid;place-items:center;color:var(--blue-900);background:none;border:1px solid transparent;border-radius:50%;font:inherit;font-size:.84rem;font-variant-numeric:tabular-nums;cursor:pointer}
    .day:hover:not(:disabled){border-color:var(--blue-500)}
    .day.today{font-weight:700;color:var(--blue-500);box-shadow:inset 0 -3px 0 -1px var(--blue-300)}
    .day.selected,.day.selected.today{color:#fff;background:var(--blue-500);border-color:var(--blue-500);font-weight:700;box-shadow:none}
    .day:disabled{color:#b7c9d8;background:none;cursor:not-allowed}
    .day:focus-visible{outline:3px solid rgba(2,132,199,.3);outline-offset:1px}
    .cal-foot{margin-top:auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-top:1px solid #e6f0f8}
    .cal-summary{color:var(--blue-900);font-size:.84rem}.cal-summary small{display:block;color:var(--muted);font-size:.75rem}
    .cal-actions{display:flex;gap:8px}.cal-actions .button{min-height:36px;padding:6px 16px}

    /* ---------- Stat cards ---------- */
    .stat-grid{margin:0 0 16px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
    .stat-card{min-width:0;min-height:104px;padding:18px 22px;display:grid;align-content:center;gap:7px;background:#fff;border:1px solid #e0ebf4;border-left:4px solid var(--accent,#0284c7);border-radius:12px;box-shadow:0 1px 2px rgba(0,46,74,.05),0 4px 14px rgba(0,46,74,.05);transition:box-shadow .18s ease,transform .18s ease}
    .stat-card:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,46,74,.06),0 8px 20px rgba(0,46,74,.09)}
    .stat-label{display:block;color:var(--muted);font-size:.74rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;line-height:1.3}
    .stat-value{display:block;color:var(--blue-900);font-size:1.75rem;font-weight:700;letter-spacing:-.03em;line-height:1.1;font-variant-numeric:tabular-nums}
    .stat-value.balance-due{color:#9c3333}.stat-note{display:block;color:var(--muted);font-size:.76rem;line-height:1.3}
    .trend{display:block;margin-top:2px}.trend.up{color:#1c7a4c;font-weight:700}.trend.down{color:#b53333;font-weight:700}

    /* ---------- Sales chart (colored bars) ---------- */
    .chart{height:200px;padding:6px 2px 0;display:flex;align-items:flex-end;gap:4px;border-bottom:2px solid var(--grid-strong);background:repeating-linear-gradient(180deg,transparent 0,transparent 49px,#e8f1f8 49px,#e8f1f8 50px)}
    .bar-col{flex:1 1 0;min-width:2px;height:100%;display:flex;align-items:flex-end}
    .bar{width:100%;min-height:0;border-radius:5px 5px 0 0;opacity:.92;transition:opacity .15s ease,transform .15s ease;transform-origin:bottom}
    .bar-col:hover .bar{opacity:1;transform:scaleY(1.03)}
    .chart-axis{margin-top:8px;display:flex;justify-content:space-between;gap:10px;color:var(--muted);font-size:.76rem}
    .chart-empty{min-height:150px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:.86rem}

    /* ---------- Breakdown panels (clean, roomy) ---------- */
    .report-grid{margin:0 0 16px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.report-grid .panel{margin:0}
    .breakdown{margin:0;padding:0;display:grid;gap:20px;list-style:none}
    .bd-top{margin-bottom:7px;display:flex;align-items:center;justify-content:space-between;gap:10px}
    .bd-name{min-width:0;display:flex;align-items:center;gap:9px;overflow:hidden;color:var(--blue-900);font-size:.9rem;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
    .bd-name span.txt{overflow:hidden;text-overflow:ellipsis}
    .dot{width:10px;height:10px;flex:none;border-radius:50%}
    .rank{width:22px;height:22px;flex:none;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-50);border-radius:50%;font-size:.72rem;font-weight:700}
    .bd-amount{color:var(--blue-900);font-size:.92rem;font-variant-numeric:tabular-nums;white-space:nowrap}
    .bd-track{height:8px;overflow:hidden;background:#e9f1f8;border-radius:999px}.bd-fill{display:block;height:100%;border-radius:999px}
    .bd-sub{margin-top:6px;display:flex;justify-content:space-between;gap:10px;color:var(--muted);font-size:.75rem}
    .bd-item.zero .bd-name,.bd-item.zero .bd-amount{color:#8aa3b6}
    .bd-total{margin:18px 0 0;padding-top:14px;display:flex;justify-content:space-between;gap:10px;color:var(--muted);border-top:1px solid #e6f0f8;font-size:.8rem}.bd-total strong{color:var(--blue-900)}
    .bd-empty{margin:0;padding:24px 0;color:var(--muted);font-size:.84rem;text-align:center}

    /* ---------- Tables ---------- */
    .table-wrap{width:100%;min-width:0;overflow-x:auto;overflow-y:hidden;background:#fff;border:1px solid var(--grid-line);border-radius:12px}
    .records-table{width:100%;min-width:900px;table-layout:fixed;border-collapse:separate;border-spacing:0;text-align:left}
    .records-table.transactions-table{min-width:640px}
    .records-table th,.records-table td{padding:12px 14px;overflow:hidden;border-right:1px solid var(--grid-line);border-bottom:1px solid var(--grid-line);text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
    .records-table th:last-child,.records-table td:last-child{border-right:0}
    .records-table thead th{position:sticky;top:0;z-index:1;color:var(--blue-900);background:linear-gradient(180deg,#e4f1fb,#cfe5f5);border-bottom:2px solid var(--grid-strong);border-right-color:#b5d2e7;font-size:.77rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase}
    .records-table tbody td{color:#244661;font-size:.83rem;line-height:1.35;background:#fff}
    .records-table tbody tr.alt td{background:#f1f7fc}
    .records-table tbody tr:hover td{background:#e1effa}
    .records-table tbody tr:last-child td{border-bottom:0}
    .records-table tr[hidden]{display:none}
    .records-table .col-id{font-weight:700;color:var(--blue-700)}.records-table .col-center{text-align:center}.records-table .col-right{text-align:right}
    .records-table .col-money{font-weight:700;color:#0b6b57;font-variant-numeric:tabular-nums}
    .records-table .col-due{font-weight:700;color:#9c3333;font-variant-numeric:tabular-nums}
    .records-table tbody tr.empty-row td{padding:0;background:#fff;border-right:0;border-bottom:0}.records-table tbody tr.empty-row:hover td{background:#fff}
    .empty-state-box{min-height:150px;padding:16px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;color:var(--muted);text-align:center;white-space:normal}
    .empty-state-box span{font-size:.92rem;font-weight:700;color:#4d6f8a}.empty-state-box small{font-size:.76rem;color:#7b97ad}
    .status{padding:5px 11px;display:inline-flex;align-items:center;color:#3d5a73;background:#e8eef4;border-radius:999px;font-size:.72rem;font-weight:700;line-height:1.2;white-space:nowrap}
    .status.cash{color:#225d40;background:#e3f5e9}.status.gcash{color:#0b5cad;background:#e0edff}.status.maya,.status.paymaya{color:#0b6b57;background:#d9f5ee}
    .status.card,.status.credit_card{color:#55368f;background:#eee7fb}.status.debit_card{color:#3b3fa8;background:#e8e9ff}.status.bank_transfer,.status.bank{color:#a0481a;background:#ffe8d9}

    /* ---------- Responsive ---------- */
    @media(max-width:1050px){.app-shell{grid-template-columns:178px minmax(0,1fr)}.brand-logo{width:82px;height:82px}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.report-grid{grid-template-columns:minmax(0,1fr)}.cal-months{flex-direction:column;gap:14px}}
    @media(max-width:760px){.app-shell{grid-template-columns:68px minmax(0,1fr)}.sidebar{padding:12px 6px}.brand-lockup{margin:3px 0 24px}.brand-logo{width:48px;height:48px}.brand-copy,.nav-item span{display:none}.nav-item{min-height:45px;padding:10px;grid-template-columns:1fr;justify-items:center}.nav-item svg{width:24px;height:24px}.logout-link{margin-top:10px}
      .main-area{grid-template-rows:68px minmax(0,1fr)}.topbar{padding:8px 12px;gap:8px}.topbar-title h1{font-size:1.12rem}.topbar-title p{font-size:.76rem}.profile-area{gap:7px}.profile-divider,.profile-copy{display:none}.profile-avatar{width:36px;height:36px}.profile-avatar svg{width:22px;height:22px}
      .page-content{padding:12px 11px 18px}.notification-panel{right:-45px}
      .period-wrap{width:100%}.period-trigger{width:100%}.period-trigger .ico-chev{margin-left:auto}
      .period-pop{width:100%;max-width:none;grid-template-columns:minmax(0,1fr)}.pop-main{min-width:0}.cal-summary{white-space:normal}.pop-presets{padding:10px;flex-direction:row;flex-wrap:wrap;gap:6px;border-right:0;border-bottom:1px solid #e6f0f8}.pop-title{display:none}
      .preset{width:auto;padding:7px 12px;border:1px solid #b8d2e5;border-radius:999px;font-size:.78rem}.preset.active{border-color:var(--blue-500)}
      .cal-months{padding:12px 10px 6px;flex-direction:column;gap:14px}.cal-month{width:100%}.cal-foot{padding:10px 12px}
      .stat-grid{gap:9px}.stat-card{min-height:80px;padding:12px 14px}.stat-label{font-size:.68rem}.stat-value{font-size:1.4rem}}
    @media(max-width:480px){.topbar-title h1{font-size:1rem}.topbar-title p{font-size:.68rem}.profile-area{gap:5px}
      .stat-grid{grid-template-columns:minmax(0,1fr)}.panel-header{padding:8px 10px}.panel-header h2{font-size:.88rem}.panel-body{padding:11px}.notification-panel{right:-41px}}

    /* ---------- Print ---------- */
    @media print{
      html,body{height:auto}body.admin-dashboard{height:auto;overflow:visible;background:#fff}
      .app-shell,.main-area{display:block;height:auto;overflow:visible}
      .sidebar,.topbar,.no-print,.toolbar,.period-pop{display:none!important}
      .page-content{width:100%;padding:0;overflow:visible}
      .panel,.stat-card{box-shadow:none;break-inside:avoid}.stat-card:hover{transform:none}
      .period-trigger .ico-chev{display:none}
      .table-wrap{max-height:none;overflow:visible}.records-table{min-width:0}
      *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
  </style>
  <style>
    body.admin-dashboard{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5}
    body.admin-dashboard .topbar-title h1{font-size:1.4rem;line-height:1.2}
    body.admin-dashboard .topbar-title p,body.admin-dashboard .page-intro p{font-size:.875rem}
    body.admin-dashboard .page-intro h2{font-size:1.25rem}
    body.admin-dashboard .panel{border-radius:14px}
    body.admin-dashboard .panel-header{min-height:44px;padding:9px 14px}
    body.admin-dashboard .panel-header h2,body.admin-dashboard .panel-title h2{font-size:1rem}
    body.admin-dashboard .button,body.admin-dashboard button.button{min-height:40px;font-size:.85rem;line-height:1.25}
    body.admin-dashboard .button.small{min-height:34px;font-size:.8rem}
    body.admin-dashboard .records-table thead th{font-size:.75rem}
    body.admin-dashboard .records-table tbody td{font-size:.84rem;line-height:1.4}
    body.admin-dashboard .stat-label{font-size:.75rem}
    body.admin-dashboard .stat-value{font-size:1.75rem}
    body.admin-dashboard .status{font-size:.75rem}
    body.admin-dashboard .form-grid label,body.admin-dashboard label.field{font-size:.82rem}
    body.admin-dashboard input,body.admin-dashboard select,body.admin-dashboard textarea{font-family:inherit;font-size:.875rem}
    body.admin-dashboard .hint,body.admin-dashboard .form-note,body.admin-dashboard .data-count{font-size:.8rem}
    @media(max-width:760px){body.admin-dashboard .topbar-title h1{font-size:1.12rem}body.admin-dashboard .topbar-title p{font-size:.78rem}}
    @media(max-width:480px){body.admin-dashboard .topbar-title h1{font-size:1.05rem}body.admin-dashboard .page-intro h2{font-size:1.15rem}body.admin-dashboard .panel-header{padding:9px 11px}body.admin-dashboard .panel-header h2,body.admin-dashboard .panel-title h2{font-size:.95rem}body.admin-dashboard .panel-body{padding:12px}.notification{width:30px}.notification svg{width:24px;height:24px}}
  </style>
  <link rel="stylesheet" href="../assets/css/data-tables.css">
  <style>
    body.admin-dashboard .main-area{width:100%;min-width:0}
    body.admin-dashboard .page-content{width:100%;max-width:1600px;padding:clamp(16px,2vw,30px);overflow-x:hidden}
    body.admin-dashboard .panel{border:1px solid #d7e8f5;border-radius:18px;box-shadow:0 8px 24px rgba(0,46,74,.08)}
    body.admin-dashboard .panel-header{min-height:54px;padding:12px 18px}
    body.admin-dashboard .panel-body{padding:clamp(16px,2vw,24px)}
    body.admin-dashboard .stat-grid{gap:16px;margin-bottom:20px}
    body.admin-dashboard .stat-card{border-radius:16px;box-shadow:0 2px 6px rgba(0,46,74,.04),0 8px 20px rgba(0,46,74,.06)}
    body.admin-dashboard .toolbar{gap:12px;padding:14px;background:#f6fafe;border:1px solid #e1edf6;border-radius:14px}
    body.admin-dashboard .toolbar label{min-width:0;flex:1 1 220px}
    body.admin-dashboard .toolbar input,body.admin-dashboard .toolbar select{min-height:44px;border-radius:10px}
    body.admin-dashboard .toolbar .button{min-height:44px;border-radius:10px}
    body.admin-dashboard .data-table-card{border-color:#d1e2ee;border-radius:14px;box-shadow:0 3px 12px rgba(0,46,74,.04)}
    @media(max-width:760px){body.admin-dashboard .page-content{padding:14px}body.admin-dashboard .panel-header{padding:11px 14px}body.admin-dashboard .panel-body{padding:16px}body.admin-dashboard .toolbar{padding:12px}}
    @media(max-width:480px){body.admin-dashboard .page-content{padding:12px}body.admin-dashboard .stat-grid{gap:10px}}
  </style>
  <link rel="stylesheet" href="../assets/css/admin-professional.css">
</head>
<body class="admin-dashboard role-app">
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand-lockup" href="dashboard.php">
        <img class="brand-logo" src="../assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic">
        <span class="brand-copy">Animal Clinic<span>Management System</span></span>
      </a>
      <nav class="side-nav" aria-label="Admin navigation">
        <a class="nav-item" href="dashboard.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8v9a1.2 1.2 0 0 1-1.2 1.2h-5.3v-6.2h-5V21H4.2A1.2 1.2 0 0 1 3 19.8z"/></svg><span>Dashboard</span></a>
        <a class="nav-item" href="manage_users.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H5.4A3.4 3.4 0 0 0 2 18.4V20m7.5-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm12.5 9v-1.6a3.4 3.4 0 0 0-2.6-3.3M16.2 3.1a4 4 0 0 1 0 7.7"/></svg><span>Manage Users</span></a>
        <a class="nav-item" href="appointments.php"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M7 14h3m-3 3h3m4-3h3"/></svg><span>Appointments</span></a>
        <a class="nav-item" href="schedule_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg><span>Vet Schedules</span></a>
        <a class="nav-item" href="groomer_schedule_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg><span>Groomer Schedules</span></a>
        <a class="nav-item" href="inventory.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5zm-9 9 9 5 9-5m-18 5 9 5 9-5M12 12v9"/></svg><span>Inventory</span></a>
        <a class="nav-item active" href="admin_reports.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V11h4v9zm6 0V5h4v15zm6 0V8h4v12z"/></svg><span>Reports</span></a>
        <a class="nav-item" href="admin_settings.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/><path d="m19.4 15 .1.1 1.1 1.7-1.8 3.1-2-.5a8 8 0 0 1-1.5.9l-.4 2h-3.6l-.4-2a8 8 0 0 1-1.5-.9l-2-.5-1.8-3.1 1.1-1.7a7 7 0 0 1 0-1.8l-1.1-1.7 1.8-3.1 2 .5a8 8 0 0 1 1.5-.9l.4-2h3.6l.4 2a8 8 0 0 1 1.5.9l2-.5 1.8 3.1-1.1 1.7a7 7 0 0 1-.1 1.8z"/></svg><span>Settings</span></a>
        <a class="nav-item logout-link" href="../logout.php?session_context=admin"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg><span>Logout</span></a>
      </nav>
    </aside>
    <div class="main-area">
      <header class="topbar">
        <div class="topbar-title"><div><h1><?= e($pageTitle) ?></h1><p>Clinic performance and financial summaries</p></div></div>
        <div class="profile-area">
          <details class="notification-menu" id="notification-menu">
            <summary class="notification" id="notification-summary" aria-label="Open clinic notifications" title="Clinic notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"/></svg><?php if ($notifications['count'] > 0): ?><span class="notification-count"><?= e($notificationBadge) ?></span><?php endif; ?></summary>
            <div class="notification-panel"><h2>Clinic notifications</h2>
              <div id="notification-items" aria-live="polite">
                <?php if ($notifications['error']): ?><p class="notification-empty error" role="alert"><?= e($notifications['error']) ?></p><?php elseif ($notifications['items']): ?>
                  <?php foreach ($notifications['items'] as $item): ?><a class="notification-item" href="<?= e($item['href']) ?>"><span class="notification-dot <?= e($item['kind']) ?>" aria-hidden="true"></span><span class="notification-copy"><strong><?= e($item['label']) ?></strong><small><?= e($item['detail']) ?></small></span><?php if ($item['count'] > 0): ?><span class="notification-item-count"><?= number_format($item['count']) ?></span><?php endif; ?></a><?php endforeach; ?>
                <?php else: ?><p class="notification-empty">No notifications right now.</p><?php endif; ?>
              </div>
            </div>
          </details>
          <span class="profile-divider" aria-hidden="true"></span>
          <a class="profile-display" href="admin_settings.php" aria-label="Admin profile settings"><span class="profile-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><span class="profile-copy"><span class="profile-name"><?= e($_SESSION['full_name'] ?? 'Admin') ?></span></span></a>
        </div>
      </header>
      <main class="page-content">
        <?php if ($dateError): ?><div class="notice info" role="status"><?= e($dateError) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="panel" aria-label="Report period">
          <div class="panel-header">
            <h2>Report Period</h2>
            <details class="dl-menu no-print" id="dl-menu">
              <summary><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 19h16"/></svg>Download report</summary>
              <div class="dl-panel">
                <a class="dl-item" href="<?= e($downloadUrl) ?>"><strong>Excel / CSV file</strong><small><?= e($periodLabel) ?> · <?= e($rangeLabel) ?>, grouped by section</small></a>
                <button class="dl-item" type="button" id="print-report"><strong>Print / Save as PDF</strong><small>Prints this page for the same period</small></button>
              </div>
            </details>
          </div>
          <div class="panel-body">
            <div class="period-bar">
              <div class="period-wrap" id="period-wrap">
                <button type="button" class="period-trigger" id="period-trigger" aria-haspopup="dialog" aria-expanded="false" aria-controls="period-pop">
                  <svg class="ico-cal" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg>
                  <span class="period-text">
                    <span class="period-name"><?= e($periodLabel) ?></span>
                    <span class="period-range"><?= e($rangeLabel) ?></span>
                  </span>
                  <svg class="ico-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>

                <div class="period-pop" id="period-pop" role="dialog" aria-label="Choose report period" hidden>
                  <div class="pop-presets" id="pop-presets">
                    <p class="pop-title">Quick range</p>
                    <?php foreach ($presets as $label => $range): ?>
                      <button type="button" class="preset<?= $range === $activeRange ? ' active' : '' ?>" data-from="<?= e($range[0]) ?>" data-to="<?= e($range[1]) ?>"><?= e($label) ?></button>
                    <?php endforeach; ?>
                  </div>
                  <div class="pop-main">
                    <div class="cal-months" id="cal-months"></div>
                    <div class="cal-foot">
                      <div class="cal-summary" id="cal-summary" aria-live="polite"></div>
                      <div class="cal-actions">
                        <button type="button" class="button secondary" id="cal-cancel">Cancel</button>
                        <button type="button" class="button" id="cal-apply">Apply</button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <p class="period-meta"><b><?= number_format($periodDays) ?> day<?= $periodDays === 1 ? '' : 's' ?></b></p>
              <a class="button secondary period-reset" href="admin_reports.php?from=<?= e($fromDefault) ?>&amp;to=<?= e($today) ?>">Reset to this month</a>
            </div>
          </div>
        </section>

        <section class="stat-grid" aria-label="Clinic performance summaries">
          <article class="stat-card" style="--accent:#008b78"><span class="stat-label">Recorded Sales</span><strong class="stat-value">₱<?= number_format((float) $summary['sales'], 2) ?></strong><span class="stat-note"><?= number_format($summary['transactions']) ?> transactions<span class="trend <?= e($salesTrendClass) ?>"><?= e($salesTrendText) ?></span></span></article>
          <article class="stat-card" style="--accent:#4164c9"><span class="stat-label">Appointments</span><strong class="stat-value"><?= number_format($summary['appointments']) ?></strong><span class="stat-note"><?= number_format($summary['completed']) ?> completed · <?= number_format($summary['cancelled']) ?> cancelled</span></article>
          <article class="stat-card" style="--accent:#b67815"><span class="stat-label">Scheduled Appointments</span><strong class="stat-value"><?= number_format($summary['scheduled']) ?></strong><span class="stat-note">Within selected period</span></article>
          <article class="stat-card" style="--accent:#c4453d"><span class="stat-label">Outstanding Balance</span><strong class="stat-value balance-due">₱<?= number_format((float) $balanceSummary['balance_total'], 2) ?></strong><span class="stat-note"><?= number_format((int) $balanceSummary['balance_count']) ?> completed appointments</span></article>
        </section>

        <section class="panel">
          <div class="panel-header"><h2>Sales Trend</h2><span class="data-count"><?= $chartMonthly ? 'Per month' : 'Per day' ?></span></div>
          <div class="panel-body">
            <?php if ($chartMax > 0): ?>
              <div class="chart" role="img" aria-label="Sales per <?= $chartMonthly ? 'month' : 'day' ?>">
                <?php foreach ($chart as $i => $point):
                    $height = $point['total'] > 0 ? max($pct($point['total'], (float) $chartMax), 2) : 0; ?>
                  <div class="bar-col" title="<?= e($point['label']) ?>: ₱<?= e(number_format($point['total'], 2)) ?>"><span class="bar" style="height:<?= e((string) $height) ?>%;background:<?= e($barColors[$i % count($barColors)]) ?>"></span></div>
                <?php endforeach; ?>
              </div>
              <div class="chart-axis">
                <span><?= e($chart[0]['label']) ?></span>
                <?php if ($chartPeak): ?><span>Best <?= $chartMonthly ? 'month' : 'day' ?>: <strong><?= e($chartPeak['label']) ?></strong> (₱<?= number_format($chartPeak['total'], 2) ?>)</span><?php endif; ?>
                <span><?= e($chart[count($chart) - 1]['label']) ?></span>
              </div>
            <?php else: ?>
              <div class="chart-empty">No sales were recorded in the selected period.</div>
            <?php endif; ?>
          </div>
        </section>

        <section class="report-grid" aria-label="Period breakdowns">
          <article class="panel">
            <div class="panel-header"><h2>Sales by Payment Method</h2></div>
            <div class="panel-body">
              <?php if ($methods): ?>
                <ul class="breakdown"><?php foreach ($methods as $method):
                    $key = $statusClass($method['method']);
                    $color = $methodColors[$key] ?? '#5b86a6'; ?>
                  <li class="bd-item">
                    <div class="bd-top"><span class="bd-name"><i class="dot" style="background:<?= e($color) ?>"></i><span class="txt"><?= e($method['method']) ?></span></span><strong class="bd-amount">₱<?= number_format((float) $method['amount'], 2) ?></strong></div>
                    <div class="bd-track"><span class="bd-fill" style="width:<?= e((string) $pct((float) $method['amount'], $methodMax)) ?>%;background:<?= e($color) ?>"></span></div>
                    <div class="bd-sub"><span><?= number_format((int) $method['total']) ?> payment<?= (int) $method['total'] === 1 ? '' : 's' ?></span><span><?= number_format($pct((float) $method['amount'], $methodTotal), 0) ?>% of sales</span></div>
                  </li>
                <?php endforeach; ?></ul>
                <p class="bd-total"><span>Total</span><strong>₱<?= number_format($methodTotal, 2) ?></strong></p>
              <?php else: ?><p class="bd-empty">No payments in the selected period.</p><?php endif; ?>
            </div>
          </article>
          <article class="panel">
            <div class="panel-header"><h2>Appointments by Status</h2></div>
            <div class="panel-body">
              <?php if ($summary['appointments'] > 0): ?>
                <ul class="breakdown"><?php foreach ($statusCounts as $label => $total):
                    $color = $statusColors[$statusClass($label)] ?? '#5b86a6'; ?>
                  <li class="bd-item<?= $total === 0 ? ' zero' : '' ?>">
                    <div class="bd-top"><span class="bd-name"><i class="dot" style="background:<?= e($color) ?>"></i><span class="txt"><?= e($label) ?></span></span><strong class="bd-amount"><?= number_format($total) ?></strong></div>
                    <div class="bd-track"><span class="bd-fill" style="width:<?= e((string) $pct((float) $total, (float) $statusMax)) ?>%;background:<?= e($color) ?>"></span></div>
                    <div class="bd-sub"><span><?= number_format($pct((float) $total, (float) $summary['appointments']), 0) ?>% of appointments</span></div>
                  </li>
                <?php endforeach; ?></ul>
                <p class="bd-total"><span>Total</span><strong><?= number_format($summary['appointments']) ?></strong></p>
              <?php else: ?><p class="bd-empty">No appointments in the selected period.</p><?php endif; ?>
            </div>
          </article>
          <article class="panel">
            <div class="panel-header"><h2>Top 5 Services by Revenue</h2></div>
            <div class="panel-body">
              <?php if ($topServices): ?>
                <ul class="breakdown"><?php foreach ($topServices as $i => $service): ?>
                  <li class="bd-item">
                    <div class="bd-top"><span class="bd-name" title="<?= e($service['service_name']) ?>"><span class="rank"><?= $i + 1 ?></span><span class="txt"><?= e($service['service_name']) ?></span></span><strong class="bd-amount">₱<?= number_format((float) $service['revenue'], 2) ?></strong></div>
                    <div class="bd-track"><span class="bd-fill" style="width:<?= e((string) $pct((float) $service['revenue'], $serviceMax)) ?>%;background:<?= e($barColors[$i % count($barColors)]) ?>"></span></div>
                    <div class="bd-sub"><span>Billed <?= number_format((int) $service['times_used']) ?> time<?= (int) $service['times_used'] === 1 ? '' : 's' ?></span><span><?= number_format($pct((float) $service['revenue'], $serviceTotal), 0) ?>% of top 5</span></div>
                  </li>
                <?php endforeach; ?></ul>
              <?php else: ?><p class="bd-empty">No services billed in the selected period.</p><?php endif; ?>
            </div>
          </article>
        </section>

        <section class="panel" id="unpaid-balances">
          <div class="panel-header"><h2>Unpaid Client Balances</h2><span class="data-count" id="bal-count" aria-live="polite" aria-atomic="true"><?= number_format(count($balances)) ?> shown</span></div>
          <div class="panel-body">
            <p class="form-note">Total charged for all services on the appointment minus all recorded payments. Covers every completed appointment, not just the selected period.</p>
            <form class="toolbar search no-print" onsubmit="return false">
              <label>Search client, pet, service, or appointment ID<input type="search" id="bal-search" placeholder="Start typing…" maxlength="100" autocomplete="off"></label>
              <button class="button secondary" type="button" id="bal-clear">Clear</button>
            </form>
            <div class="table-wrap data-table-card">
              <table class="records-table balances-table" id="bal-table">
                <colgroup><col style="width:18%"><col style="width:14%"><col style="width:7rem"><col style="width:22%"><col style="width:9rem"><col style="width:9rem"><col style="width:10rem"></colgroup>
                <thead><tr><th>Client</th><th>Pet</th><th class="col-center">Appointment</th><th>Service</th><th class="col-right">Total Charged</th><th class="col-right">Paid</th><th class="col-right">Balance Due</th></tr></thead>
                <tbody id="bal-body">
                  <?php foreach ($balances as $balance): ?>
                    <tr class="filter-row" data-text="<?= e(mb_strtolower($balance['client_name'] . ' ' . $balance['pet_name'] . ' ' . $balance['service_names'] . ' ' . $balance['appointment_id'])) ?>">
                      <td title="<?= e($balance['client_name']) ?>" data-label="Client"><?= e($balance['client_name']) ?></td>
                      <td title="<?= e($balance['pet_name']) ?>" data-label="Pet"><?= e($balance['pet_name']) ?></td>
                      <td class="col-center col-id" data-label="Appointment">#<?= (int) $balance['appointment_id'] ?></td>
                      <td title="<?= e($balance['service_names']) ?>" data-label="Service"><?= e($balance['service_names']) ?></td>
                      <td class="col-right" data-label="Total Charged">₱<?= number_format((float) $balance['total_charged'], 2) ?></td>
                      <td class="col-right col-money" data-label="Paid">₱<?= number_format((float) $balance['amount_paid'], 2) ?></td>
                      <td class="col-right col-due" data-label="Balance Due">₱<?= number_format((float) $balance['balance'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="empty-row" id="bal-empty"<?= $balances ? ' hidden' : '' ?>><td colspan="7"><div class="empty-state-box"><span>No records found</span><small><?= $balances ? 'No balances match your search.' : 'No unpaid balances are recorded for completed services.' ?></small></div></td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section class="panel" id="transactions">
          <div class="panel-header"><h2>Recent Transactions</h2><span class="data-count" id="trx-count" aria-live="polite" aria-atomic="true"><?= number_format(count($transactions)) ?> in selected period</span></div>
          <div class="panel-body">
            <form class="toolbar search no-print" onsubmit="return false">
              <label>Search client, transaction ID, or method<input type="search" id="trx-search" placeholder="Start typing…" maxlength="100" autocomplete="off"></label>
              <button class="button secondary" type="button" id="trx-clear">Clear</button>
            </form>
            <div class="table-wrap data-table-card">
              <table class="records-table transactions-table" id="trx-table">
                <colgroup><col style="width:8rem"><col style="width:30%"><col style="width:9rem"><col style="width:10rem"><col style="width:10rem"></colgroup>
                <thead><tr><th>Transaction ID</th><th>Client</th><th class="col-right">Amount</th><th class="col-center">Date</th><th class="col-center">Method</th></tr></thead>
                <tbody id="trx-body">
                  <?php foreach ($transactions as $transaction): ?>
                    <tr class="filter-row" data-text="<?= e(mb_strtolower('trx ' . $transaction['payment_id'] . ' ' . $transaction['client_name'] . ' ' . $transaction['payment_method'])) ?>">
                      <td class="col-id" data-label="Transaction ID">#TRX-<?= (int) $transaction['payment_id'] ?></td>
                      <td title="<?= e($transaction['client_name']) ?>" data-label="Client"><?= e($transaction['client_name']) ?></td>
                      <td class="col-right col-money" data-label="Amount">₱<?= number_format((float) $transaction['amount_paid'], 2) ?></td>
                      <td class="col-center" data-label="Date"><?= e(date('M j, Y g:i A', strtotime($transaction['payment_date']))) ?></td>
                      <td class="col-center" data-label="Method"><span class="status <?= e($statusClass($transaction['payment_method'])) ?>"><?= e($transaction['payment_method'] ?: '—') ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="empty-row" id="trx-empty"<?= $transactions ? ' hidden' : '' ?>><td colspan="5"><div class="empty-state-box"><span>No records found</span><small><?= $transactions ? 'No transactions match your search.' : 'No transactions are recorded in the selected period.' ?></small></div></td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </main>
    </div>
  </div>
  <script>
    (() => {
      const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

      /* ---------- Live search + Clear for a table ---------- */
      function setupFilter(searchId, clearId, bodyId, emptyId, countId, countSuffix) {
        const search = document.getElementById(searchId);
        const clearBtn = document.getElementById(clearId);
        const rows = Array.from(document.querySelectorAll('#' + bodyId + ' tr.filter-row'));
        const indexedRows = rows.map((row) => ({ row, searchText: normalize(row.dataset.text) }));
        const emptyRow = document.getElementById(emptyId);
        const count = document.getElementById(countId);

        function apply() {
          const query = normalize(search.value).split(/\s+/).filter(Boolean);
          let shown = 0;
          indexedRows.forEach(({ row, searchText }) => {
            const matches = query.every((term) => searchText.includes(term));
            row.hidden = !matches;
            row.classList.toggle('alt', matches && shown % 2 === 1);
            if (matches) shown++;
          });
          emptyRow.hidden = shown > 0;
          count.textContent = shown.toLocaleString() + ' ' + countSuffix;
        }

        search.addEventListener('input', apply);
        clearBtn.addEventListener('click', () => { search.value = ''; apply(); search.focus(); });
        apply();
      }
      setupFilter('bal-search', 'bal-clear', 'bal-body', 'bal-empty', 'bal-count', 'shown');
      setupFilter('trx-search', 'trx-clear', 'trx-body', 'trx-empty', 'trx-count', 'shown');

      /* ---------- Download menu: print option + close on outside click ---------- */
      const dl = document.getElementById('dl-menu');
      document.getElementById('print-report').addEventListener('click', () => { dl.open = false; window.print(); });
      document.addEventListener('mousedown', (e) => { if (dl.open && !dl.contains(e.target)) dl.open = false; });

      /* ---------- Period picker: independent Start / End calendars ---------- */
      const cfg = <?= json_encode($pickerConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      const SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      const DOW = ['Mo','Tu','We','Th','Fr','Sa','Su'];
      const MIN_YEAR = 2015;
      const pad = (n) => String(n).padStart(2, '0');
      const parse = (s) => { const p = s.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); };
      const iso = (y, m, d) => y + '-' + pad(m + 1) + '-' + pad(d);          // m is 0-based
      const human = (s) => { const d = parse(s); return SHORT[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); };
      const dayCount = (a, b) => Math.round((parse(b) - parse(a)) / 86400000) + 1;

      const wrap = document.getElementById('period-wrap');
      const periodPanel = wrap.closest('.panel');
      const trigger = document.getElementById('period-trigger');
      const pop = document.getElementById('period-pop');
      const monthsEl = document.getElementById('cal-months');
      const summaryEl = document.getElementById('cal-summary');
      const applyBtn = document.getElementById('cal-apply');
      const cancelBtn = document.getElementById('cal-cancel');
      const today = parse(cfg.today);
      const MIN_IDX = MIN_YEAR * 12;
      const MAX_IDX = today.getFullYear() * 12 + today.getMonth();

      let start = cfg.from, end = cfg.to;
      const view = { L: { y: 0, m: 0 }, R: { y: 0, m: 0 } };

      function setView(side, y, m) {
        const idx = Math.min(Math.max(y * 12 + m, MIN_IDX), MAX_IDX);
        view[side].y = Math.floor(idx / 12);
        view[side].m = idx % 12;
      }

      function syncViewsToSelection() {
        const s = parse(start), e = parse(end);
        setView('L', s.getFullYear(), s.getMonth());
        setView('R', e.getFullYear(), e.getMonth());
      }

      function go(from, to) {
        window.location.href = 'admin_reports.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
      }

      function monthHtml(side, label) {
        const { y, m } = view[side];
        const daysIn = new Date(y, m + 1, 0).getDate();
        const lead = (new Date(y, m, 1).getDay() + 6) % 7;
        const idx = y * 12 + m;
        const nowY = today.getFullYear(), nowM = today.getMonth();
        let monthOpts = '';
        for (let i = 0; i < 12; i++) {
          const future = y === nowY && i > nowM;
          monthOpts += '<option value="' + i + '"' + (i === m ? ' selected' : '') + (future ? ' disabled' : '') + '>' + MONTHS[i] + '</option>';
        }
        let yearOpts = '';
        for (let yr = nowY; yr >= MIN_YEAR; yr--) {
          yearOpts += '<option value="' + yr + '"' + (yr === y ? ' selected' : '') + '>' + yr + '</option>';
        }

        let html = '<div class="cal-month" data-side="' + side + '"><p class="cal-label">' + label + '</p><div class="cal-head">';
        html += '<button type="button" class="cal-nav" data-nav="-1" data-side="' + side + '" aria-label="Previous month"' + (idx <= MIN_IDX ? ' disabled' : '') + '>‹</button>';
        html += '<div class="cal-title">'
          + '<select class="cal-select" data-sel="month" aria-label="' + label + ' month">' + monthOpts + '</select>'
          + '<select class="cal-select" data-sel="year" aria-label="' + label + ' year">' + yearOpts + '</select></div>';
        html += '<button type="button" class="cal-nav" data-nav="1" data-side="' + side + '" aria-label="Next month"' + (idx >= MAX_IDX ? ' disabled' : '') + '>›</button>';
        html += '</div><div class="cal-grid">';
        DOW.forEach((d) => { html += '<span class="cal-dow">' + d + '</span>'; });
        for (let b = 0; b < lead; b++) html += '<span class="cell"></span>';

        for (let d = 1; d <= daysIn; d++) {
          const key = iso(y, m, d);
          const future = key > cfg.today;
          const isS = key === start, isE = key === end;
          let cell = 'cell';
          if (end > start) {
            if (key > start && key < end) cell += ' in-range';
            else if (isS) cell += ' range-start';
            else if (isE) cell += ' range-end';
          }
          let day = 'day';
          if (isS || isE) day += ' selected';
          if (key === cfg.today) day += ' today';
          html += '<span class="' + cell + '"><button type="button" class="' + day + '" data-day="' + key + '"' + (future ? ' disabled' : '') + ' aria-label="' + human(key) + '"' + (isS || isE ? ' aria-pressed="true"' : '') + '>' + d + '</button></span>';
        }
        return html + '</div></div>';
      }

      function render() {
        monthsEl.innerHTML = monthHtml('L', 'Start date') + monthHtml('R', 'End date');
        const days = dayCount(start, end);
        summaryEl.innerHTML = human(start) + ' – ' + human(end) + '<small>' + days + ' day' + (days === 1 ? '' : 's') + '</small>';
        applyBtn.disabled = !(start && end && start <= end);

        pop.querySelectorAll('.preset').forEach((btn) => {
          btn.classList.toggle('active', btn.dataset.from === start && btn.dataset.to === end);
        });
      }

      function openPop() {
        start = cfg.from; end = cfg.to;
        syncViewsToSelection();
        render();
        pop.hidden = false;
        periodPanel.classList.add('period-picker-open');
        trigger.setAttribute('aria-expanded', 'true');
      }
      function closePop(returnFocus) {
        pop.hidden = true;
        periodPanel.classList.remove('period-picker-open');
        trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) trigger.focus();
      }

      trigger.addEventListener('click', () => { pop.hidden ? openPop() : closePop(false); });
      cancelBtn.addEventListener('click', () => closePop(true));
      applyBtn.addEventListener('click', () => { if (start && end && start <= end) go(start, end); });

      pop.addEventListener('click', (e) => {
        const preset = e.target.closest('.preset');
        if (preset) { go(preset.dataset.from, preset.dataset.to); return; }
        const navBtn = e.target.closest('[data-nav]');
        if (navBtn && !navBtn.disabled) {
          const side = navBtn.dataset.side;
          setView(side, view[side].y, view[side].m + Number(navBtn.dataset.nav));
          render();
          return;
        }
        const dayBtn = e.target.closest('[data-day]');
        if (dayBtn && !dayBtn.disabled) {
          const side = dayBtn.closest('.cal-month').dataset.side;
          const key = dayBtn.dataset.day;
          if (side === 'L') {
            start = key;
            if (end < start) { end = start; setView('R', view.L.y, view.L.m); }
          } else {
            end = key;
            if (start > end) { start = end; setView('L', view.R.y, view.R.m); }
          }
          render();
        }
      });

      pop.addEventListener('change', (e) => {
        const select = e.target.closest('[data-sel]');
        if (!select) return;
        const calendar = select.closest('.cal-month');
        const side = calendar.dataset.side;
        const month = Number(calendar.querySelector('[data-sel="month"]').value);
        const year = Number(calendar.querySelector('[data-sel="year"]').value);
        setView(side, year, month);
        render();
      });

      // Close on outside click and Escape
      document.addEventListener('mousedown', (e) => { if (!pop.hidden && !wrap.contains(e.target)) closePop(false); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !pop.hidden) closePop(true); });

    })();
  </script>
  <script src="../assets/JS/admin_notifications.js" defer></script>
  <script src="../assets/JS/admin-shell.js" defer></script>
</body>
</html>