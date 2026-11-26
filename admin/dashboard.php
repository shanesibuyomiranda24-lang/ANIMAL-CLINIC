<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_role(['admin']);

$stats = [
    'owners' => 0,
    'pets' => 0,
    'today_appointments' => 0,
    'veterinarians' => 0,
    'staff' => 0,
    'today_sales' => 0.0,
];
$appointments = [];
$transactions = [];
$inventoryAlerts = [];
$databaseError = '';
$sectionErrors = [];

// Run one dashboard section; if it fails, only that section is empty.
$guard = function (string $label, callable $fn) use (&$sectionErrors): void {
    try {
        $fn();
    } catch (Throwable $ex) {
        error_log($label . ': ' . $ex->getMessage());
        $sectionErrors[] = $label;
    }
};

$pdo = null;
try {
    $pdo = db();
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $databaseError = 'Database unavailable. Check that MySQL is running and the connection settings are correct.';
}

if ($pdo) {
    $scalar = function (string $sql) use ($pdo) {
        $statement = $pdo->prepare($sql);
        $statement->execute();
        return $statement->fetchColumn();
    };

    $guard('statistics', function () use (&$stats, $scalar) {
        $stats['owners'] = (int) $scalar('SELECT COUNT(*) FROM PetOwners');
        $stats['pets'] = (int) $scalar('SELECT COUNT(*) FROM Pets');
        $stats['today_appointments'] = (int) $scalar(
            'SELECT COUNT(*) FROM Appointments WHERE appointment_date >= CURRENT_DATE AND appointment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)'
        );
        $stats['veterinarians'] = (int) $scalar('SELECT COUNT(*) FROM Veterinarians');
        $stats['staff'] = (int) $scalar('SELECT COUNT(*) FROM Staff');
        $stats['today_sales'] = (float) $scalar(
            'SELECT COALESCE(SUM(amount_paid), 0) FROM Payments WHERE payment_date >= CURRENT_DATE AND payment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)'
        );
    });

    $guard("today's appointments", function () use ($pdo, &$appointments) {
        $statement = $pdo->prepare(
            'SELECT a.appointment_id, a.appointment_date, a.status, p.pet_name,
                    o.full_name AS owner_name, v.full_name AS vet_name
             FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id
             JOIN PetOwners o ON o.owner_id = p.owner_id
             LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
             WHERE a.appointment_date >= CURRENT_DATE
               AND a.appointment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
             ORDER BY a.appointment_date
             LIMIT 10'
        );
        $statement->execute();
        $appointments = $statement->fetchAll();
    });

    $guard('recent transactions', function () use ($pdo, &$transactions) {
        $statement = $pdo->prepare(
            'SELECT pay.payment_id, pay.amount_paid, pay.payment_date, pay.payment_method,
                    o.full_name AS client_name
             FROM Payments pay
             JOIN Appointments a ON a.appointment_id = pay.appointment_id
             JOIN Pets p ON p.pet_id = a.pet_id
             JOIN PetOwners o ON o.owner_id = p.owner_id
             ORDER BY pay.payment_date DESC, pay.payment_id DESC
             LIMIT 8'
        );
        $statement->execute();
        $transactions = $statement->fetchAll();
    });

    $guard('inventory alerts', function () use ($pdo, &$inventoryAlerts) {
        $statement = $pdo->prepare(
            'SELECT item_name, quantity, expiration_date
             FROM Inventory
             ORDER BY CASE
                        WHEN expiration_date IS NOT NULL AND expiration_date < CURRENT_DATE THEN 0
                        WHEN quantity <= 0 THEN 1
                        WHEN quantity <= 5 THEN 2
                        WHEN expiration_date IS NOT NULL AND expiration_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN 3
                        ELSE 4
                      END,
                      expiration_date ASC, quantity ASC
             LIMIT 8'
        );
        $statement->execute();
        $inventoryAlerts = $statement->fetchAll();
    });

    if ($sectionErrors) {
        $databaseError = 'Some dashboard data could not be loaded (' . implode(', ', $sectionErrors) . '). Check that the required tables exist.';
    }
}

$formatTime = function ($value): string {
    return $value ? date('g:i A', strtotime($value)) : '—';
};
$formatDate = function ($value): string {
    return $value ? date('M j, Y', strtotime($value)) : '—';
};
$statusClass = function ($value): string {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $value), '_'));
};
?>
<?php
require_once __DIR__ . '/../includes/admin_notifications.php';
$pageTitle = 'Admin Dashboard';
$notifications = ['count' => 0, 'items' => [], 'error' => ''];
try {
    $noticeDb = db();
    $notifications = dashboard_notification_state(
        $noticeDb,
        (int) $_SESSION['user_id']
    );
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $notifications['error'] = 'Notification data is unavailable. Check the database connection and notification table.';
}
$notificationBadge = $notifications['count'] > 99 ? '99+' : (string) $notifications['count'];
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
    :root{--blue-50:#eaf3fa;--blue-100:#c2dcf1;--blue-300:#81b7e0;--blue-500:#0284c7;--blue-700:#015b8c;--blue-900:#002e4a;--black-blue:#000914;--ink:#092947;--muted:#42637e;--line:#d7e8f5;--grid-line:#d3e3ef;--grid-strong:#8fbadb}
    *{box-sizing:border-box}html,body{width:100%;height:100%;margin:0}
    body.admin-dashboard{min-width:320px;height:100vh;height:100dvh;overflow:hidden;color:var(--ink);background:#f4f9fd;font:14px/1.45 Arial,Helvetica,sans-serif}
    .admin-dashboard a{color:inherit}.app-shell{width:100%;height:100%;display:grid;grid-template-columns:198px minmax(0,1fr);overflow:hidden}
    .sidebar{position:relative;z-index:2;min-width:0;padding:20px 10px 16px;overflow:hidden auto;color:#fff;background:linear-gradient(180deg,var(--blue-700),var(--blue-900))}
    .sidebar:after{position:absolute;z-index:-1;right:-60px;bottom:0;left:-60px;height:210px;background:url("../assets/Abstract%20Blue%20Wave%20Gradient.png") center bottom/cover no-repeat;opacity:.34;content:"";pointer-events:none}
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
    .notification{position:relative;width:34px;height:36px;display:grid;place-items:center;color:var(--blue-700);list-style:none;cursor:pointer}.notification::-webkit-details-marker{display:none}
    .notification svg{width:27px;height:27px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .notification-count{position:absolute;top:-3px;right:-5px;min-width:18px;height:18px;padding:0 4px;display:grid;place-items:center;color:#fff;background:#e33b43;border:2px solid #fff;border-radius:999px;font-size:.64rem;font-weight:700}
    .notification-panel{position:absolute;top:calc(100% + 13px);right:-18px;z-index:8;width:min(360px,calc(100vw - 24px));max-height:min(460px,calc(100dvh - 100px));padding:8px;overflow-y:auto;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:13px;box-shadow:0 14px 36px rgba(0,46,74,.2)}
    .notification-panel h2{margin:4px 8px 8px;color:var(--blue-900);font-size:.95rem}.notification-item{padding:9px 8px;display:grid;grid-template-columns:10px minmax(0,1fr) auto;align-items:center;gap:9px;border-top:1px solid #edf3f8;text-decoration:none}
    .notification-item:hover{background:var(--blue-50)}.notification-dot{width:9px;height:9px;border-radius:50%;background:var(--blue-500)}.notification-dot.inventory,.notification-dot.billing{background:#e59824}.notification-dot.transaction{background:#39a267}
    .notification-copy{min-width:0;display:grid;gap:2px}.notification-copy strong{color:var(--blue-900);font-size:.8rem}.notification-copy small,.notification-empty{color:var(--muted);font-size:.75rem}
    .notification-item-count{color:var(--blue-700);font-size:.78rem;font-weight:700}.notification-empty{margin:0;padding:14px 8px;color:var(--muted);font-size:.78rem;text-align:center}.notification-empty.error{color:#8c3232}
    .profile-divider{width:1px;height:34px;margin:0 5px;background:var(--blue-300)}.profile-display{min-width:0;display:flex;align-items:center;gap:9px;color:inherit;text-decoration:none;transition:opacity .15s ease}
    .profile-display:hover{opacity:.82}.profile-display:focus-visible{outline:3px solid rgba(2,132,199,.28);outline-offset:4px;border-radius:8px}
    .profile-avatar{width:42px;height:42px;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-100);border-radius:50%}.profile-avatar svg{width:27px;height:27px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .profile-copy{min-width:0}.profile-name{max-width:180px;overflow:hidden;color:var(--blue-900);font-weight:700;text-overflow:ellipsis;white-space:nowrap}
    .page-content{width:100%;max-width:none;min-width:0;min-height:0;margin:0;padding:12px clamp(14px,1.8vw,26px) 24px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain}
    .notice{margin-bottom:14px;padding:11px 14px;border-radius:9px}.notice.error{color:#842626;background:#fdeaea}.notice.success{color:#17623b;background:#e3f5e9}.notice.info{color:var(--blue-900);background:var(--blue-50)}
    .page-intro{margin:0 0 14px;display:flex;align-items:flex-end;justify-content:space-between;gap:12px}.page-intro h2{margin:0;color:var(--blue-900);font-size:1.35rem}.page-intro p{margin:3px 0 0;color:var(--muted);font-size:.86rem}
    .panel{min-width:0;margin:0;overflow:hidden;background:#fff;border:1px solid #d5e7f4;border-radius:14px;box-shadow:0 3px 10px rgba(0,46,74,.09)}
    .panel-header{min-height:42px;padding:8px 13px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:#fff;background:linear-gradient(100deg,var(--blue-700),var(--blue-900))}
    .panel-header h2,.panel-title h2{margin:0;color:inherit;font-size:.98rem}.panel-title{min-width:0;display:flex;align-items:center;gap:9px}.panel-body{min-width:0;padding:15px}.panel-header .data-count{color:#eaf3fa}
    .view-all{color:#fff;font-size:.82rem;font-weight:700;text-decoration:none;white-space:nowrap}.view-all:hover{text-decoration:underline}

    /* ---------- Clean stat cards (no icons) ---------- */
    .stat-grid{margin:0 0 16px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .stat-card{min-width:0;min-height:104px;padding:18px 22px;display:grid;align-content:center;gap:8px;background:#fff;border:1px solid #e0ebf4;border-left:4px solid var(--accent,#0284c7);border-radius:12px;box-shadow:0 1px 2px rgba(0,46,74,.05),0 4px 14px rgba(0,46,74,.05);transition:box-shadow .18s ease,transform .18s ease}
    .stat-card:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,46,74,.06),0 8px 20px rgba(0,46,74,.09)}
    .stat-label{display:block;color:var(--muted);font-size:.74rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;line-height:1.3}
    .stat-value{display:block;color:var(--blue-900);font-size:2rem;font-weight:700;letter-spacing:-.03em;line-height:1.1;font-variant-numeric:tabular-nums}

    /* ---------- Dashboard panels ---------- */
    .dashboard-grid{min-width:0;margin-bottom:14px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:stretch;gap:14px}.dashboard-column{min-width:0;display:contents}
    #todays-appointments,#recent-transactions,#inventory-alerts{display:flex;flex-direction:column;border-color:#d7e8f5;border-radius:20px;box-shadow:0 10px 28px rgba(0,46,74,.08)}
    #todays-appointments,#recent-transactions{grid-row:1;height:auto}
    #inventory-alerts{grid-column:1/-1;grid-row:2;height:auto}
    #todays-appointments .panel-header,#recent-transactions .panel-header,#inventory-alerts .panel-header{flex:0 0 auto;min-height:64px;padding:18px 24px;background:linear-gradient(100deg,#015b8c,#00395c 55%,#002339)}
    #todays-appointments .table-wrap,#recent-transactions .table-wrap,#inventory-alerts .table-wrap{flex:none}

    /* ---------- Tables: aligned columns, grid lines, colors ---------- */
    .table-wrap{width:100%;min-width:0;overflow-x:auto;overflow-y:hidden;background:#fff}
    .records-table{width:100%;min-width:640px;table-layout:fixed;border-collapse:separate;border-spacing:0;text-align:left}
    .records-table th,.records-table td{padding:12px 14px;overflow:hidden;border-right:1px solid var(--grid-line);border-bottom:1px solid var(--grid-line);text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
    .records-table th:last-child,.records-table td:last-child{border-right:0}
    .records-table thead th{position:sticky;top:0;z-index:1;color:var(--blue-900);background:linear-gradient(180deg,#e4f1fb,#cfe5f5);border-bottom:2px solid var(--grid-strong);border-right-color:#b5d2e7;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
    .records-table tbody td{color:#244661;font-size:.9rem;line-height:1.4}
    .records-table tbody tr:nth-child(odd) td{background:#fff}
    .records-table tbody tr:nth-child(even) td{background:#f1f7fc}
    .records-table tbody tr:hover td{background:#e1effa}
    .records-table tbody tr:last-child td{border-bottom:0}
    .records-table .col-id{font-weight:700;color:var(--blue-700)}
    .records-table .col-center{text-align:center}
    .records-table .col-right{text-align:right}
    .records-table .col-money{font-weight:700;color:#0b6b57;font-variant-numeric:tabular-nums}
    .records-table tbody tr.empty-row td{padding:0;background:#fff;border-right:0;border-bottom:0}
    .records-table tbody tr.empty-row:hover td{background:#fff}
    .empty-state-box{min-height:170px;padding:16px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;color:var(--muted);text-align:center;white-space:normal}
    .empty-state-box span{font-size:.92rem;font-weight:700;color:#4d6f8a}.empty-state-box small{font-size:.76rem;color:#7b97ad}
    .empty-icon{color:#71b9e2;font-size:1.8rem;line-height:1}

    .status{padding:5px 11px;display:inline-flex;align-items:center;color:#225d40;background:#e3f5e9;border-radius:999px;font-size:.72rem;font-weight:700;line-height:1.2;white-space:nowrap}
    /* Appointment status */
    .status.pending{color:#7a5410;background:#fff2d6}
    .status.scheduled{color:#155c86;background:#e2f2fc}
    .status.confirmed{color:#0b6b57;background:#d9f5ee}
    .status.check_in{color:#55368f;background:#eee7fb}
    .status.in_progress{color:#a0481a;background:#ffe8d9}
    .status.in_consultation{color:#8a2f6d;background:#fbe6f3}
    .status.completed,.status.complete{color:#225d40;background:#e3f5e9}
    .status.cancelled{color:#8c3232;background:#fdeaea}
    .status.no_show,.status.inactive{color:#59636e;background:#edf0f3}
    .status.active{color:#225d40;background:#e3f5e9}
    /* Inventory status */
    .status.in_stock{color:#225d40;background:#e3f5e9}
    .status.low{color:#7a5410;background:#fff2d6}
    .status.expiring{color:#a0481a;background:#ffe8d9}
    .status.out_of_stock{color:#8c3232;background:#fdeaea}
    .status.expired{color:#fff;background:#8a2f3b}
    /* Payment method */
    .status.method{color:#3d5a73;background:#e8eef4}
    .status.method.cash{color:#225d40;background:#e3f5e9}
    .status.method.gcash{color:#0b5cad;background:#e0edff}
    .status.method.maya,.status.method.paymaya{color:#0b6b57;background:#d9f5ee}
    .status.method.card,.status.method.credit_card{color:#55368f;background:#eee7fb}
    .status.method.debit_card{color:#3b3fa8;background:#e8e9ff}
    .status.method.bank_transfer,.status.method.bank{color:#a0481a;background:#ffe8d9}
    .status.method.check,.status.method.cheque{color:#59636e;background:#edf0f3}

    .form-note{margin:0 0 13px;color:var(--muted);font-size:.83rem}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form-grid .wide{grid-column:1/-1}
    .form-grid label{min-width:0;display:grid;gap:5px;color:var(--blue-900);font-size:.82rem;font-weight:700}
    .form-grid input,.form-grid select,.toolbar input,.toolbar select{width:100%;min-width:0;min-height:41px;padding:9px 11px;color:var(--ink);background:#fff;border:1px solid #bed4e5;border-radius:8px;font:inherit}
    .form-grid input:focus,.form-grid select:focus,.toolbar input:focus,.toolbar select:focus{outline:3px solid rgba(2,132,199,.16);border-color:var(--blue-500)}
    .form-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.toolbar{margin:0 0 13px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:9px}.toolbar label{min-width:150px;flex:1 1 170px;display:grid;gap:5px;color:var(--blue-900);font-size:.79rem;font-weight:700}
    .button,button.button{min-height:40px;padding:8px 13px;display:inline-flex;align-items:center;justify-content:center;gap:6px;color:#fff;background:var(--blue-500);border:1px solid var(--blue-500);border-radius:8px;font:inherit;font-size:.84rem;font-weight:700;text-decoration:none;cursor:pointer}
    .button:hover,button.button:hover{background:var(--blue-700);border-color:var(--blue-700)}.button.secondary{color:var(--blue-700);background:#fff;border-color:#b8d2e5}.button.secondary:hover{background:var(--blue-50)}.button.danger{background:#b93838;border-color:#b93838}
    .button:disabled{opacity:.55;cursor:not-allowed}.data-count,.muted{color:var(--muted);font-size:.78rem}.summary-grid{margin:0 0 14px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    .summary-card{min-width:0;min-height:92px;padding:13px 15px;display:grid;align-content:center;gap:3px;background:#fff;border:1px solid #d9e8f4;border-radius:13px;box-shadow:0 2px 8px rgba(0,46,74,.07)}
    .summary-card>span,.summary-card small{color:var(--muted);font-size:.79rem}.summary-card strong{color:var(--blue-900);font-size:1.45rem;line-height:1.2}.balance-due{color:#9c3333!important}
    .inline-form{display:inline-flex}.row-editor{position:relative}.row-editor>summary{cursor:pointer;list-style:none}.row-editor>summary::-webkit-details-marker{display:none}
    .row-edit-form{min-width:240px;padding:12px;display:grid;gap:9px;background:#fff}.row-edit-form label{display:grid;gap:4px;color:var(--blue-900);font-size:.77rem;font-weight:700}.row-edit-form input{width:100%;min-height:36px;padding:7px 9px;border:1px solid #bed4e5;border-radius:7px;font:inherit}
    .settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.settings-grid .panel-header{color:var(--blue-900);background:linear-gradient(100deg,var(--blue-50),#fff);border-bottom:1px solid var(--line)}
    .profile-facts{margin:18px 0 0;display:grid;gap:0;border-top:1px solid var(--line)}.profile-facts>div{padding:9px 0;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line)}
    .profile-facts dt{color:var(--muted);font-size:.8rem}.profile-facts dd{margin:0;color:var(--blue-900);font-size:.82rem;font-weight:700;text-align:right}.report-range label{flex:0 1 220px}
    @media(max-width:1050px){.app-shell{grid-template-columns:178px minmax(0,1fr)}.brand-logo{width:82px;height:82px}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:760px){.app-shell{grid-template-columns:68px minmax(0,1fr)}.sidebar{padding:12px 6px}.brand-lockup{margin:3px 0 24px}.brand-logo{width:48px;height:48px}.brand-copy,.nav-item span{display:none}.nav-item{min-height:45px;padding:10px;grid-template-columns:1fr;justify-items:center}.nav-item svg{width:24px;height:24px}.logout-link{margin-top:10px}
      .main-area{grid-template-rows:68px minmax(0,1fr)}.topbar{padding:8px 12px;gap:8px}.topbar-title h1{font-size:1.12rem}.topbar-title p{font-size:.76rem}.profile-area{gap:7px}.profile-divider,.profile-copy{display:none}.profile-avatar{width:36px;height:36px}.profile-avatar svg{width:22px;height:22px}
      .page-content{padding:12px 11px 18px}
      .stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.stat-card{min-height:80px;padding:12px 14px}.stat-label{font-size:.68rem}.stat-value{font-size:1.5rem}
      .dashboard-grid{grid-template-columns:minmax(0,1fr);gap:12px}#todays-appointments,#recent-transactions,#inventory-alerts{grid-column:1}#todays-appointments{grid-row:1}#recent-transactions{grid-row:2}#inventory-alerts{grid-row:3}
      .settings-grid{grid-template-columns:minmax(0,1fr)}.form-grid{grid-template-columns:minmax(0,1fr)}.form-grid .wide{grid-column:1}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:480px){.topbar-title h1{font-size:1rem}.topbar-title p{font-size:.68rem}.notification{width:30px}.notification svg{width:24px;height:24px}.profile-area{gap:5px}
      .page-intro{align-items:flex-start}.page-intro h2{font-size:1.15rem}.page-intro p{font-size:.78rem}.summary-grid{grid-template-columns:minmax(0,1fr);gap:8px}.summary-card{min-height:76px}.stat-grid{grid-template-columns:minmax(0,1fr)}.stat-card{min-height:72px}
      .panel-header{padding:8px 10px}.panel-header h2,.panel-title h2{font-size:.88rem}.panel-body{padding:11px}.profile-facts>div{align-items:flex-start}.notification-panel{right:-41px}}
  </style>
  <link rel="stylesheet" href="../assets/css/data-tables.css">
  <link rel="stylesheet" href="../assets/css/admin-professional.css">
  <link rel="stylesheet" href="../assets/css/dashboard-banner.css">
</head>
<body class="admin-dashboard">
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand-lockup" href="dashboard.php">
        <img class="brand-logo" src="../assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic">
        <span class="brand-copy">Animal Clinic<span>Management System</span></span>
      </a>
      <nav class="side-nav" aria-label="Admin navigation">
        <a class="nav-item active" href="dashboard.php" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8v9a1.2 1.2 0 0 1-1.2 1.2h-5.3v-6.2h-5V21H4.2A1.2 1.2 0 0 1 3 19.8z"/></svg><span>Dashboard</span></a>
        <a class="nav-item" href="manage_users.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H5.4A3.4 3.4 0 0 0 2 18.4V20m7.5-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm12.5 9v-1.6a3.4 3.4 0 0 0-2.6-3.3M16.2 3.1a4 4 0 0 1 0 7.7"/></svg><span>Manage Users</span></a>
        <a class="nav-item" href="appointments.php"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M7 14h3m-3 3h3m4-3h3"/></svg><span>Appointments</span></a>
        <a class="nav-item" href="schedule_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg><span>Vet Schedules</span></a>
        <a class="nav-item" href="groomer_schedule_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg><span>Groomer Schedules</span></a>
        <a class="nav-item" href="inventory.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5zm-9 9 9 5 9-5m-18 5 9 5 9-5M12 12v9"/></svg><span>Inventory</span></a>
        <a class="nav-item" href="admin_reports.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V11h4v9zm6 0V5h4v15zm6 0V8h4v12z"/></svg><span>Reports</span></a>
        <a class="nav-item" href="admin_settings.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/><path d="m19.4 15 .1.1 1.1 1.7-1.8 3.1-2-.5a8 8 0 0 1-1.5.9l-.4 2h-3.6l-.4-2a8 8 0 0 1-1.5-.9l-2 .5-1.8-3.1 1.1-1.7a7 7 0 0 1 0-1.8l-1.1-1.7 1.8-3.1 2 .5a8 8 0 0 1 1.5-.9l.4-2h3.6l.4 2a8 8 0 0 1 1.5.9l2-.5 1.8 3.1-1.1 1.7a7 7 0 0 1-.1 1.8z"/></svg><span>Settings</span></a>
        <a class="nav-item logout-link" href="../logout.php?session_context=admin"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg><span>Logout</span></a>
      </nav>
    </aside>
    <div class="main-area">
      <header class="topbar">
        <div class="topbar-title"><div><h1><?= e($pageTitle) ?></h1><p>Animal Clinic Owner / Manager</p></div></div>
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
<?php if ($databaseError): ?><div class="notice error" role="alert"><?= e($databaseError) ?></div><?php endif; ?>

<section class="clinic-banner" aria-label="Welcome">
  <div class="clinic-banner-copy">
    <p class="clinic-banner-eyebrow">A healthier clinic for every pet</p>
    <h2>Welcome back, <?= e($_SESSION['full_name'] ?? 'Admin') ?>!</h2>
    <p>Manage your clinic efficiently and provide the best care for our furry friends.</p>
    <div class="clinic-banner-actions">
      <a class="button light" href="appointments.php">View appointments</a>
      <a class="button ghost" href="manage_users.php">Manage users</a>
    </div>
  </div>
</section>

<section class="stat-grid" aria-label="Clinic statistics">
  <article class="stat-card" style="--accent:#0284c7"><span class="stat-label">Total Pet Owners</span><strong class="stat-value"><?= number_format($stats['owners']) ?></strong></article>
  <article class="stat-card" style="--accent:#23935b"><span class="stat-label">Total Registered Pets</span><strong class="stat-value"><?= number_format($stats['pets']) ?></strong></article>
  <article class="stat-card" style="--accent:#4164c9"><span class="stat-label">Today's Appointments</span><strong class="stat-value"><?= number_format($stats['today_appointments']) ?></strong></article>
  <article class="stat-card" style="--accent:#b67815"><span class="stat-label">Total Staff</span><strong class="stat-value"><?= number_format($stats['staff']) ?></strong></article>
  <article class="stat-card" style="--accent:#008b78"><span class="stat-label">Today's Sales</span><strong class="stat-value">₱<?= number_format($stats['today_sales'], 2) ?></strong></article>
  <article class="stat-card" style="--accent:#7a4fc4"><span class="stat-label">Total Veterinarians</span><strong class="stat-value"><?= number_format($stats['veterinarians']) ?></strong></article>
</section>

<div class="dashboard-grid">
  <div class="dashboard-column">
    <section class="panel" id="todays-appointments">
      <div class="panel-header">
        <div class="panel-title"><h2>Today's Appointments</h2></div>
        <a class="view-all" href="appointments.php">View All <span aria-hidden="true">→</span></a>
      </div>
      <div class="panel-body">
      <div class="table-wrap data-table-card">
        <table class="records-table appointments-table">
          <colgroup><col style="width:9%"><col style="width:17%"><col style="width:22%"><col style="width:22%"><col style="width:12%"><col style="width:18%"></colgroup>
          <thead><tr><th>ID</th><th>Pet Name</th><th>Owner</th><th>Veterinarian</th><th class="col-center">Time</th><th class="col-center">Status</th></tr></thead>
          <tbody>
            <?php if ($appointments): foreach ($appointments as $appointment): ?>
              <tr>
                <td class="col-id" data-label="ID">#<?= (int) $appointment['appointment_id'] ?></td>
                <td data-label="Pet name" title="<?= e($appointment['pet_name']) ?>"><?= e($appointment['pet_name']) ?></td>
                <td data-label="Owner" title="<?= e($appointment['owner_name']) ?>"><?= e($appointment['owner_name']) ?></td>
                <td data-label="Veterinarian" title="<?= e($appointment['vet_name'] ?: 'Unassigned') ?>"><?= e($appointment['vet_name'] ?: 'Unassigned') ?></td>
                <td class="col-center" data-label="Time"><?= e($formatTime($appointment['appointment_date'])) ?></td>
                <td class="col-center" data-label="Status"><span class="status <?= e($statusClass($appointment['status'])) ?>"><?= e($appointment['status']) ?></span></td>
              </tr>
            <?php endforeach; else: ?>
              <tr class="empty-row"><td colspan="6"><div class="empty-state-box"><span>No records found</span><small>No appointments are scheduled for today.</small></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      </div>
    </section>
  </div>

  <div class="dashboard-column">
    <section class="panel" id="recent-transactions">
      <div class="panel-header">
        <div class="panel-title"><h2>Recent Transactions</h2></div>
        <a class="view-all" href="admin_reports.php#transactions">View All <span aria-hidden="true">→</span></a>
      </div>
      <div class="panel-body">
      <div class="table-wrap data-table-card">
        <table class="records-table transactions-table">
          <colgroup><col style="width:17%"><col style="width:29%"><col style="width:18%"><col style="width:18%"><col style="width:18%"></colgroup>
          <thead><tr><th>ID</th><th>Client</th><th class="col-right">Amount</th><th class="col-center">Date</th><th class="col-center">Method</th></tr></thead>
          <tbody>
            <?php if ($transactions): foreach ($transactions as $transaction): ?>
              <tr>
                <td class="col-id" data-label="ID">#TRX-<?= (int) $transaction['payment_id'] ?></td>
                <td data-label="Client" title="<?= e($transaction['client_name']) ?>"><?= e($transaction['client_name']) ?></td>
                <td class="col-right col-money" data-label="Amount">₱<?= number_format((float) $transaction['amount_paid'], 2) ?></td>
                <td class="col-center" data-label="Date"><?= e($formatDate($transaction['payment_date'])) ?></td>
                <td class="col-center" data-label="Method"><span class="status method <?= e($statusClass($transaction['payment_method'])) ?>"><?= e($transaction['payment_method'] ?: '—') ?></span></td>
              </tr>
            <?php endforeach; else: ?>
              <tr class="empty-row"><td colspan="5"><div class="empty-state-box"><span>No records found</span><small>No payments have been recorded yet.</small></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      </div>
    </section>

    <section class="panel" id="inventory-alerts">
      <div class="panel-header">
        <div class="panel-title"><h2>Inventory Alerts</h2></div>
        <a class="view-all" href="inventory.php">View All <span aria-hidden="true">→</span></a>
      </div>
      <div class="panel-body">
      <div class="table-wrap data-table-card">
        <table class="records-table inventory-table">
          <colgroup><col style="width:40%"><col style="width:15%"><col style="width:22%"><col style="width:23%"></colgroup>
          <thead><tr><th>Medicine</th><th class="col-center">Quantity</th><th class="col-center">Expiration Date</th><th class="col-center">Status</th></tr></thead>
          <tbody>
            <?php if ($inventoryAlerts): foreach ($inventoryAlerts as $item):
                $quantity = (int) $item['quantity'];
                $expired = $item['expiration_date'] && $item['expiration_date'] < date('Y-m-d');
                $expiring = $item['expiration_date'] && !$expired && $item['expiration_date'] <= date('Y-m-d', strtotime('+30 days'));
                if ($expired) {
                    $status = 'Expired';
                    $inventoryClass = 'expired';
                } elseif ($quantity <= 0) {
                    $status = 'Out of Stock';
                    $inventoryClass = 'out_of_stock';
                } elseif ($quantity <= 5) {
                    $status = 'Low Stock';
                    $inventoryClass = 'low';
                } elseif ($expiring) {
                    $status = 'Expiring Soon';
                    $inventoryClass = 'expiring';
                } else {
                    $status = 'In Stock';
                    $inventoryClass = 'in_stock';
                }
            ?>
              <tr>
                <td data-label="Medicine" title="<?= e($item['item_name']) ?>"><?= e($item['item_name']) ?></td>
                <td class="col-center" data-label="Quantity"><?= number_format($quantity) ?></td>
                <td class="col-center" data-label="Expiration date"><?= e($formatDate($item['expiration_date'])) ?></td>
                <td class="col-center" data-label="Status"><span class="status <?= e($inventoryClass) ?>"><?= e($status) ?></span></td>
              </tr>
            <?php endforeach; else: ?>
              <tr class="empty-row"><td colspan="4"><div class="empty-state-box"><span>No records found</span><small>No inventory items need attention.</small></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      </div>
    </section>
  </div>
</div>
      </main>
    </div>
  </div>
  <script src="../assets/JS/admin-shell.js" defer></script>
  <script src="../assets/JS/admin_notifications.js" defer></script>
</body>
</html>