<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_role(['admin']);

$allowedStatuses = ['Scheduled', 'Check-in', 'In Consultation', 'Complete', 'Cancelled'];
$appointments = [];
$counts = ['total' => 0, 'today' => 0, 'scheduled' => 0, 'open_cases' => 0];
$error = '';
try {
    $pdo = db();
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(a.appointment_date >= CURRENT_DATE AND a.appointment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)), 0) AS today,
                COALESCE(SUM(a.status = 'Scheduled'), 0) AS scheduled,
                COALESCE(SUM(a.status = 'In Consultation' AND mr.record_id IS NULL), 0) AS open_cases
         FROM Appointments a
         JOIN Pets p ON p.pet_id = a.pet_id AND p.archived_at IS NULL
         LEFT JOIN MedicalRecords mr ON mr.appointment_id = a.appointment_id
         WHERE a.archived_at IS NULL AND p.archived_at IS NULL"
    );
    $statement->execute();
    $counts = $statement->fetch() ?: $counts;

    // Load every appointment; search, status and date filters run live in the browser.
    $statement = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.status, p.pet_name,
                o.full_name AS owner_name, v.full_name AS vet_name,
                (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
                   FROM AppointmentServices aps
                   JOIN Services s ON s.service_id = aps.service_id
                  WHERE aps.appointment_id = a.appointment_id) AS service_names,
                mr.record_id, mr.diagnosis
         FROM Appointments a
         INNER JOIN Pets p ON p.pet_id = a.pet_id AND p.archived_at IS NULL
         INNER JOIN PetOwners o ON o.owner_id = p.owner_id
         LEFT JOIN Veterinarians v ON v.vet_id = a.vet_id
         LEFT JOIN MedicalRecords mr ON mr.appointment_id = a.appointment_id
         WHERE a.archived_at IS NULL AND p.archived_at IS NULL
         ORDER BY a.appointment_date DESC, a.appointment_id DESC"
    );
    $statement->execute();
    $appointments = $statement->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $error = 'Appointment data is unavailable. Check the database connection and required table names.';
}

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
$pageTitle = 'Appointments';

$statusClass = function ($value): string {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $value), '_'));
};
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
    .notice{margin-bottom:14px;padding:11px 14px;border-radius:9px}.notice.error{color:#842626;background:#fdeaea}

    /* ---------- Clean stat cards (no icons) ---------- */
    .stat-grid{margin:0 0 16px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
    .stat-card{min-width:0;min-height:104px;padding:18px 22px;display:grid;align-content:center;gap:8px;background:#fff;border:1px solid #e0ebf4;border-left:4px solid var(--accent,#0284c7);border-radius:12px;box-shadow:0 1px 2px rgba(0,46,74,.05),0 4px 14px rgba(0,46,74,.05);transition:box-shadow .18s ease,transform .18s ease}
    .stat-card:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,46,74,.06),0 8px 20px rgba(0,46,74,.09)}
    .stat-label{display:block;color:var(--muted);font-size:.74rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;line-height:1.3}
    .stat-value{display:block;color:var(--blue-900);font-size:2rem;font-weight:700;letter-spacing:-.03em;line-height:1.1;font-variant-numeric:tabular-nums}

    /* ---------- Panel ---------- */
    .panel{min-width:0;margin:0;overflow:hidden;background:#fff;border:1px solid #cbdfee;border-radius:16px;box-shadow:0 6px 18px rgba(0,46,74,.11)}
    .panel-header{min-height:48px;padding:9px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:#fff;background:linear-gradient(105deg,#015b8c,#002e4a)}
    .panel-title{min-width:0;display:flex;align-items:center;gap:9px}.panel-title h2,.panel-header h2{margin:0;color:inherit;font-size:.98rem}.panel-body{min-width:0;padding:15px}

    /* ---------- Filters ---------- */
    .toolbar{margin:0 0 13px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:9px}.toolbar label{min-width:150px;flex:1 1 170px;display:grid;gap:5px;color:var(--blue-900);font-size:.79rem;font-weight:700}
    .toolbar input,.toolbar select{width:100%;min-width:0;min-height:41px;padding:9px 11px;color:var(--ink);background:#fff;border:1px solid #bed4e5;border-radius:8px;font:inherit}
    .toolbar input:focus,.toolbar select:focus{outline:3px solid rgba(2,132,199,.16);border-color:var(--blue-500)}
    /* Dropdown arrow: custom, aligned with the field padding */
    .toolbar select{-webkit-appearance:none;-moz-appearance:none;appearance:none;padding-right:42px;cursor:pointer;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23002e4a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 16px center/16px 16px}
    .button,button.button{min-height:40px;padding:8px 13px;display:inline-flex;align-items:center;justify-content:center;gap:6px;color:#fff;background:var(--blue-500);border:1px solid var(--blue-500);border-radius:8px;font:inherit;font-size:.84rem;font-weight:700;text-decoration:none;cursor:pointer}
    .button:hover,button.button:hover{background:var(--blue-700);border-color:var(--blue-700)}.button.secondary{color:var(--blue-700);background:#fff;border-color:#b8d2e5}.button.secondary:hover{background:var(--blue-50)}

    /* ---------- Table: aligned columns, grid lines, alternating rows, hover ---------- */
    .table-wrap{width:100%;min-width:0;overflow-x:auto;overflow-y:hidden;background:#fff;border:1px solid var(--grid-line);border-radius:12px}
    .records-table{width:100%;min-width:1000px;table-layout:fixed;border-collapse:separate;border-spacing:0;text-align:left}
    .records-table th,.records-table td{padding:12px 14px;overflow:hidden;border-right:1px solid var(--grid-line);border-bottom:1px solid var(--grid-line);text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
    .records-table th:last-child,.records-table td:last-child{border-right:0}
    .records-table thead th{position:sticky;top:0;z-index:1;color:var(--blue-900);background:linear-gradient(180deg,#e4f1fb,#cfe5f5);border-bottom:2px solid var(--grid-strong);border-right-color:#b5d2e7;font-size:.77rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase}
    .records-table tbody td{color:#244661;font-size:.83rem;line-height:1.35;background:#fff}
    .records-table tbody tr.alt td{background:#f1f7fc}
    .records-table tbody tr:hover td{background:#e1effa}
    .records-table tbody tr:last-child td{border-bottom:0}
    .records-table tr[hidden]{display:none}
    .records-table .col-id{font-weight:700;color:var(--blue-700)}
    .records-table .col-center{text-align:center}
    .records-table tbody tr.empty-row td{padding:0;background:#fff;border-right:0;border-bottom:0}
    .records-table tbody tr.empty-row:hover td{background:#fff}
    .empty-state-box{min-height:170px;padding:16px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;color:var(--muted);text-align:center;white-space:normal}
    .empty-state-box span{font-size:.92rem;font-weight:700;color:#4d6f8a}.empty-state-box small{font-size:.76rem;color:#7b97ad}

    /* ---------- Status pills (same colors as the dashboard) ---------- */
    .status{padding:5px 11px;display:inline-flex;align-items:center;color:#225d40;background:#e3f5e9;border-radius:999px;font-size:.72rem;font-weight:700;line-height:1.2;white-space:nowrap}
    .status.scheduled{color:#155c86;background:#e2f2fc}
    .status.check_in{color:#55368f;background:#eee7fb}
    .status.in_consultation{color:#8a2f6d;background:#fbe6f3}
    .status.complete,.status.completed{color:#225d40;background:#e3f5e9}
    .status.cancelled{color:#8c3232;background:#fdeaea}

    @media(max-width:1050px){.app-shell{grid-template-columns:178px minmax(0,1fr)}.brand-logo{width:82px;height:82px}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:760px){.app-shell{grid-template-columns:68px minmax(0,1fr)}.sidebar{padding:12px 6px}.brand-lockup{margin:3px 0 24px}.brand-logo{width:48px;height:48px}.brand-copy,.nav-item span{display:none}.nav-item{min-height:45px;padding:10px;grid-template-columns:1fr;justify-items:center}.nav-item svg{width:24px;height:24px}.logout-link{margin-top:10px}
      .main-area{grid-template-rows:68px minmax(0,1fr)}.topbar{padding:8px 12px;gap:8px}.topbar-title h1{font-size:1.12rem}.topbar-title p{font-size:.76rem}.profile-area{gap:7px}.profile-divider,.profile-copy{display:none}.profile-avatar{width:36px;height:36px}.profile-avatar svg{width:22px;height:22px}
      .page-content{padding:12px 11px 18px}
      .stat-grid{gap:9px}.stat-card{min-height:80px;padding:12px 14px}.stat-label{font-size:.68rem}.stat-value{font-size:1.5rem}}
    @media(max-width:480px){.topbar-title h1{font-size:1rem}.topbar-title p{font-size:.68rem}.notification{width:30px}.notification svg{width:24px;height:24px}.profile-area{gap:5px}
      .stat-grid{grid-template-columns:minmax(0,1fr)}.stat-card{min-height:72px}.panel-header{padding:8px 10px}.panel-header h2,.panel-title h2{font-size:.88rem}.panel-body{padding:11px}.notification-panel{right:-41px}}
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
    @media(max-width:480px){body.admin-dashboard .topbar-title h1{font-size:1.05rem}body.admin-dashboard .page-intro h2{font-size:1.15rem}body.admin-dashboard .panel-header{padding:9px 11px}body.admin-dashboard .panel-header h2,body.admin-dashboard .panel-title h2{font-size:.95rem}body.admin-dashboard .panel-body{padding:12px}}
  </style>
  <link rel="stylesheet" href="../assets/css/data-tables.css">
  <link rel="stylesheet" href="../assets/css/admin-professional.css">
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
        <a class="nav-item active" href="appointments.php" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M7 14h3m-3 3h3m4-3h3"/></svg><span>Appointments</span></a>
        <a class="nav-item" href="schedule_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/><circle cx="16" cy="16" r="3"/><path d="M16 14.5V16l1 1"/></svg><span>Vet Schedules</span></a>
        <a class="nav-item" href="groomer_schedule_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6 4 4M4 20l8.5-8.5M7 17l-3-3m13-3 3-3a2.1 2.1 0 0 0-3-3l-3 3m-6 6 3 3"/><circle cx="6" cy="6" r="2"/><circle cx="18" cy="18" r="2"/></svg><span>Groomer Schedules</span></a>
        <a class="nav-item" href="inventory.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5zm-9 9 9 5 9-5m-18 5 9 5 9-5M12 12v9"/></svg><span>Inventory</span></a>
        <a class="nav-item" href="admin_reports.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V11h4v9zm6 0V5h4v15zm6 0V8h4v12z"/></svg><span>Reports</span></a>
        <a class="nav-item" href="admin_settings.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/><path d="m19.4 15 .1.1 1.1 1.7-1.8 3.1-2-.5a8 8 0 0 1-1.5.9l-.4 2h-3.6l-.4-2a8 8 0 0 1-1.5-.9l-2 .5-1.8-3.1 1.1-1.7a7 7 0 0 1 0-1.8l-1.1-1.7 1.8-3.1 2 .5a8 8 0 0 1 1.5-.9l.4-2h3.6l.4 2a8 8 0 0 1 1.5.9l2-.5 1.8 3.1-1.1 1.7a7 7 0 0 1-.1 1.8z"/></svg><span>Settings</span></a>
        <a class="nav-item logout-link" href="../logout.php?session_context=admin"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg><span>Logout</span></a>
      </nav>
    </aside>
    <div class="main-area">
      <header class="topbar">
        <div class="topbar-title"><div><h1><?= e($pageTitle) ?></h1><p>Review schedules and appointment records</p></div></div>
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
        <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="stat-grid" aria-label="Appointment summaries">
          <article class="stat-card" style="--accent:#0284c7"><span class="stat-label">All Appointments</span><strong class="stat-value"><?= number_format((int) $counts['total']) ?></strong></article>
          <article class="stat-card" style="--accent:#4164c9"><span class="stat-label">Scheduled Today</span><strong class="stat-value"><?= number_format((int) $counts['today']) ?></strong></article>
          <article class="stat-card" style="--accent:#b67815"><span class="stat-label">Scheduled Appointments</span><strong class="stat-value"><?= number_format((int) $counts['scheduled']) ?></strong></article>
          <article class="stat-card" style="--accent:#c4453d"><span class="stat-label">In-Consultation Without Records</span><strong class="stat-value"><?= number_format((int) $counts['open_cases']) ?></strong></article>
        </section>

        <section class="panel">
          <div class="panel-header"><div class="panel-title"><h2>Appointment Register</h2></div><span class="data-count" id="appt-count" aria-live="polite" aria-atomic="true"><?= number_format(count($appointments)) ?> shown</span></div>
          <div class="panel-body">
            <form class="toolbar" id="appointment-filters" onsubmit="return false">
              <label>Search pet, owner, veterinarian, service, or ID<input type="search" id="appt-search" placeholder="Search any part of a name, service, or ID…" maxlength="100" autocomplete="off"></label>
              <label>Status<select id="appt-status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $status): ?><option value="<?= e($status) ?>"><?= e($status) ?></option><?php endforeach; ?></select></label>
              <label>Date<input type="date" id="appt-date"></label>
              <button class="button secondary" type="button" id="appt-clear">Clear</button>
            </form>
            <div class="table-wrap data-table-card">
              <table class="records-table appointments-table">
                <colgroup><col style="width:5rem"><col style="width:10rem"><col style="width:13%"><col style="width:14%"><col style="width:14%"><col style="width:16%"><col style="width:18%"><col style="width:9rem"></colgroup>
                <thead><tr><th>ID</th><th class="col-center">Date &amp; Time</th><th>Pet</th><th>Owner</th><th>Veterinarian</th><th>Service</th><th>Diagnosis</th><th class="col-center">Status</th></tr></thead>
                <tbody id="appt-body">
                  <?php foreach ($appointments as $appointment):
                      $vet = $appointment['vet_name'] ?: 'Unassigned';
                      $vetPlain = preg_replace('/^dr\.?\s+/i', '', (string) $appointment['vet_name']);
                      $service = $appointment['service_names'] ?: 'Not assigned';
                      $diagnosis = $appointment['diagnosis'] ?: ($appointment['record_id'] ? 'Record available' : 'Not recorded');
                        $searchText = mb_strtolower(implode(' ', array_filter([
                            (string) $appointment['appointment_id'],
                          $appointment['pet_name'],
                          $appointment['owner_name'],
                          $appointment['vet_name'],
                          $vetPlain,
                            $service,
                            $diagnosis,
                      ])));
                  ?>
                    <tr class="appt-row"
                        data-id="<?= (int) $appointment['appointment_id'] ?>"
                          data-search="<?= e($searchText) ?>"
                        data-status="<?= e($appointment['status']) ?>"
                        data-date="<?= e(date('Y-m-d', strtotime($appointment['appointment_date']))) ?>">
                      <td class="col-id" data-label="ID">#<?= (int) $appointment['appointment_id'] ?></td>
                      <td class="col-center" data-label="Date &amp; Time"><?= e(date('M j, Y g:i A', strtotime($appointment['appointment_date']))) ?></td>
                      <td title="<?= e($appointment['pet_name']) ?>" data-label="Pet"><?= e($appointment['pet_name']) ?></td>
                      <td title="<?= e($appointment['owner_name']) ?>" data-label="Owner"><?= e($appointment['owner_name']) ?></td>
                      <td title="<?= e($vet) ?>" data-label="Veterinarian"><?= e($vet) ?></td>
                      <td title="<?= e($service) ?>" data-label="Service"><?= e($service) ?></td>
                      <td title="<?= e($diagnosis) ?>" data-label="Diagnosis"><?= e($diagnosis) ?></td>
                      <td class="col-center" data-label="Status"><span class="status <?= e($statusClass($appointment['status'])) ?>"><?= e($appointment['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="empty-row" id="appt-empty"<?= $appointments ? ' hidden' : '' ?>>
                    <td colspan="8"><div class="empty-state-box"><span>No records found</span><small><?= $appointments ? 'No appointments match your search or filters.' : 'No appointments have been recorded yet.' ?></small></div></td>
                  </tr>
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
      /* ---------- Live search + status + date filters ---------- */
      const search = document.getElementById('appt-search');
      const statusSel = document.getElementById('appt-status');
      const dateInput = document.getElementById('appt-date');
      const clearBtn = document.getElementById('appt-clear');
      const rows = Array.from(document.querySelectorAll('#appt-body tr.appt-row'));
      const emptyRow = document.getElementById('appt-empty');
      const count = document.getElementById('appt-count');
      const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
      const indexedRows = rows.map((row) => ({ row, searchText: normalize(row.dataset.search) }));

      function applyFilters() {
        const query = normalize(search.value).split(/\s+/).filter(Boolean);
        const status = statusSel.value;
        const date = dateInput.value;
        let shown = 0;

        indexedRows.forEach(({ row, searchText }) => {
          const textMatch = query.every((term) => searchText.includes(term));
          const matches = textMatch
            && (!status || row.dataset.status === status)
            && (!date || row.dataset.date === date);

          row.hidden = !matches;
          row.classList.toggle('alt', matches && shown % 2 === 1); // keep alternating colors correct
          if (matches) shown++;
        });

        emptyRow.hidden = shown !== 0;
        count.textContent = shown.toLocaleString() + ' shown';
      }

      search.addEventListener('input', applyFilters);
      statusSel.addEventListener('change', applyFilters);
      dateInput.addEventListener('input', applyFilters);
      dateInput.addEventListener('change', applyFilters);
      clearBtn.addEventListener('click', () => {
        search.value = '';
        statusSel.value = '';
        dateInput.value = '';
        applyFilters();
        search.focus();
      });
      applyFilters();

    })();
  </script>
  <script src="../assets/JS/admin_notifications.js" defer></script>
  <script src="../assets/JS/admin-shell.js" defer></script>
</body>
</html>