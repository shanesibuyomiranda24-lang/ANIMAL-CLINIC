<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_role(['admin']);

$error = '';
$success = '';
$userId = (int) $_SESSION['user_id'];
$profile = [];
$form = ['full_name' => null, 'email' => null];   // keeps typed values when validation fails

function admin_settings_password_is_strong(string $password): bool
{
    return strlen($password) >= 7
        && strlen($password) <= 72
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

// One-time message after a successful save (Post/Redirect/Get: refreshing won't resubmit the form)
if (!empty($_SESSION['settings_flash'])) {
    $success = (string) $_SESSION['settings_flash'];
    unset($_SESSION['settings_flash']);
}

try {
    $pdo = db();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!csrf_valid(posted_string('csrf'))) {
            $error = 'Your session expired. Reload the page and try again.';
        } elseif (posted_string('action') === 'update_profile') {
            $fullName = trim(posted_string('full_name'));
            $email = normalize_email(posted_string('email'));
            $form = ['full_name' => $fullName, 'email' => $email];

            if (!valid_full_name($fullName)) {
                $error = 'Enter a valid full name (up to 150 characters).';
            } elseif (!valid_email($email)) {
                $error = 'Enter a valid email address.';
            } else {
                $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
                $check->execute([$email, $userId]);
                if ($check->fetch()) {
                    $error = 'That email address is already associated with another account.';
                } else {
                    $update = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE user_id = ? AND `role` = ?');
                    $update->execute([$fullName, $email, $userId, 'admin']);
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;
                    $_SESSION['settings_flash'] = 'Profile details updated.';
                    header('Location: admin_settings.php');
                    exit;
                }
            }
        } elseif (posted_string('action') === 'change_password') {
            $currentPassword = posted_string('current_password');
            $newPassword = posted_string('new_password');
            $confirmPassword = posted_string('confirm_password');

            $statement = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? AND `role` = ? LIMIT 1');
            $statement->execute([$userId, 'admin']);
            $currentHash = $statement->fetchColumn();

            if (!is_string($currentHash) || !password_verify($currentPassword, $currentHash)) {
                $error = 'The current password is incorrect.';
            } elseif (!admin_settings_password_is_strong($newPassword)) {
                $error = 'Use 7–72 characters with uppercase, lowercase, a number, and a symbol.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'The new password and confirmation do not match.';
            } elseif (password_verify($newPassword, $currentHash)) {
                $error = 'Choose a password different from your current password.';
            } else {
                $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ? AND `role` = ?');
                $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId, 'admin']);
                session_regenerate_id(true);
                $_SESSION['settings_flash'] = 'Password changed successfully.';
                header('Location: admin_settings.php');
                exit;
            }
        } else {
            $error = 'Unsupported settings request.';
        }
    }

    $statement = $pdo->prepare('SELECT full_name, email, status, created_at FROM users WHERE user_id = ? AND `role` = ? LIMIT 1');
    $statement->execute([$userId, 'admin']);
    $profile = $statement->fetch() ?: [];
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $error = 'Settings could not be saved or loaded. Check the database connection and try again.';
}

$statusClass = function ($value): string {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $value), '_'));
};

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
$pageTitle = 'Admin Settings';
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
    :root{--blue-50:#eaf3fa;--blue-100:#c2dcf1;--blue-300:#81b7e0;--blue-500:#0284c7;--blue-700:#015b8c;--blue-900:#002e4a;--ink:#092947;--muted:#42637e;--line:#d7e8f5}
    *{box-sizing:border-box}html,body{width:100%;height:100%;margin:0}
    body.admin-dashboard{min-width:320px;height:100vh;height:100dvh;overflow:hidden;color:var(--ink);background:#f4f9fd;font:14px/1.45 Arial,Helvetica,sans-serif}
    .admin-dashboard a{color:inherit}.app-shell{width:100%;height:100%;display:grid;grid-template-columns:198px minmax(0,1fr);overflow:hidden}

    /* ---------- Sidebar / top bar (identical to dashboard) ---------- */
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
    .notification-panel h2{margin:4px 0 8px;padding:4px 8px 10px;color:var(--blue-900);font-size:.95rem;border-bottom:1px solid #e8f0f6}.notification-item{padding:11px 8px;display:grid;grid-template-columns:10px minmax(0,1fr) auto;align-items:center;gap:9px;border-top:1px solid #edf3f8;border-radius:7px;text-decoration:none;transition:background-color .15s ease}
    .notification-item:hover,.notification-item:focus-visible{background:var(--blue-50)}.notification-item:focus-visible{outline:2px solid rgba(2,132,199,.35);outline-offset:1px}.notification-dot{width:9px;height:9px;border-radius:50%;background:var(--blue-500)}.notification-dot.inventory,.notification-dot.billing{background:#e59824}.notification-dot.transaction{background:#39a267}
    .notification-copy{min-width:0;display:grid;gap:2px}.notification-copy strong{color:var(--blue-900);font-size:.8rem}.notification-copy small,.notification-empty{color:var(--muted);font-size:.75rem}
    .notification-item-count{color:var(--blue-700);font-size:.78rem;font-weight:700}.notification-empty{margin:0;padding:14px 8px;text-align:center}.notification-empty.error{color:#8c3232}
    .profile-divider{width:1px;height:34px;margin:0 5px;background:var(--blue-300)}.profile-display{min-width:0;display:flex;align-items:center;gap:9px;color:inherit;text-decoration:none;transition:opacity .15s ease}
    .profile-display:hover{opacity:.82}.profile-display:focus-visible{outline:3px solid rgba(2,132,199,.28);outline-offset:4px;border-radius:8px}
    .profile-avatar{width:42px;height:42px;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-100);border-radius:50%}.profile-avatar svg{width:27px;height:27px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .profile-copy{min-width:0}.profile-name{max-width:180px;overflow:hidden;color:var(--blue-900);font-weight:700;text-overflow:ellipsis;white-space:nowrap}
    .page-content{width:100%;max-width:none;min-width:0;min-height:0;margin:0;padding:12px clamp(14px,1.8vw,26px) 24px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain}
    .notice{margin-bottom:14px;padding:11px 14px;border-radius:9px}.notice.error{color:#842626;background:#fdeaea}.notice.success{color:#17623b;background:#e3f5e9}

    /* ---------- Panels (dashboard look) ---------- */
    .settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:stretch;gap:18px}
    .settings-grid>.panel{min-width:0;margin:0;display:flex;flex-direction:column;overflow:hidden;background:#fff;border:1px solid #cbdfee;border-radius:16px;box-shadow:0 6px 18px rgba(0,46,74,.09)}
    .panel-header{min-height:50px;padding:10px 17px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:#fff;background:linear-gradient(105deg,#015b8c,#002e4a)}
    .panel-header h2{margin:0;color:inherit;font-size:.98rem}.settings-grid .panel-body{min-width:0;padding:20px;flex:1}

    /* ---------- Forms ---------- */
    .form-note{margin:0 0 17px;padding:11px 13px;color:var(--muted);background:#f4f9fd;border-left:3px solid #65a8d3;border-radius:0 8px 8px 0;font-size:.83rem}
    .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.form-grid .wide{grid-column:1/-1}
    .form-grid label{min-width:0;display:grid;gap:6px;color:var(--blue-900);font-size:.82rem;font-weight:700}
    .form-grid input{width:100%;min-width:0;min-height:44px;padding:10px 12px;color:var(--ink);background:#fff;border:1px solid #bed4e5;border-radius:9px;font:inherit;transition:border-color .15s ease,box-shadow .15s ease}
    .form-grid input:focus{outline:0;border-color:var(--blue-500);box-shadow:0 0 0 4px rgba(2,132,199,.13)}
    .pw-field{position:relative;display:block}.pw-field input{padding-right:50px}
    .pw-field input::-ms-reveal,.pw-field input::-ms-clear{display:none}
    .pw-toggle{position:absolute;top:50%;right:8px;width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;color:#647d9f;background:transparent;border:0;border-radius:50%;transform:translateY(-50%);cursor:pointer;transition:color .2s,background .2s}
    .pw-toggle:hover{color:var(--blue-900);background:rgba(2,132,199,.08)}.pw-toggle:focus-visible{outline:2px solid var(--blue-500);outline-offset:1px}
    .pw-toggle svg{width:22px;height:22px}.pw-toggle .eye-open{display:none}.pw-toggle .eye-closed{display:block}
    .pw-toggle[aria-pressed="true"] .eye-open{display:block}.pw-toggle[aria-pressed="true"] .eye-closed{display:none}
    .strength{height:7px;margin-top:3px;overflow:hidden;background:#e9f1f8;border:1px solid #deebf5;border-radius:999px}.strength span{display:block;width:0;height:100%;border-radius:999px;transition:width .2s ease,background-color .2s ease}
    .hint{min-height:1.2em;display:block;color:var(--muted);font-size:.74rem;font-weight:400;line-height:1.4}.hint.bad{color:#b53333}.hint.good{color:#1c7a4c}
    .form-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding-top:2px}
    .button,button.button{min-height:40px;padding:8px 16px;display:inline-flex;align-items:center;justify-content:center;gap:6px;color:#fff;background:var(--blue-500);border:1px solid var(--blue-500);border-radius:8px;font:inherit;font-size:.84rem;font-weight:700;text-decoration:none;cursor:pointer;transition:background-color .15s ease}
    .button:hover,button.button:hover{background:var(--blue-700);border-color:var(--blue-700)}

    .profile-facts{margin:20px 0 0;display:grid;gap:0;border-top:1px solid var(--line)}.profile-facts>div{padding:12px 0;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line)}
    .profile-facts dt{color:var(--muted);font-size:.8rem}.profile-facts dd{margin:0;color:var(--blue-900);font-size:.82rem;font-weight:700;text-align:right}
    .status{padding:5px 11px;display:inline-flex;align-items:center;color:#59636e;background:#edf0f3;border-radius:999px;font-size:.72rem;font-weight:700;line-height:1.2;white-space:nowrap}.status.active{color:#225d40;background:#e3f5e9}.status.inactive{color:#59636e;background:#edf0f3}

    @media(max-width:1050px){.app-shell{grid-template-columns:178px minmax(0,1fr)}.brand-logo{width:82px;height:82px}.settings-grid{grid-template-columns:minmax(0,1fr)}}
    @media(max-width:760px){.app-shell{grid-template-columns:68px minmax(0,1fr)}.sidebar{padding:12px 6px}.brand-lockup{margin:3px 0 24px}.brand-logo{width:48px;height:48px}.brand-copy,.nav-item span{display:none}.nav-item{min-height:45px;padding:10px;grid-template-columns:1fr;justify-items:center}.nav-item svg{width:24px;height:24px}.logout-link{margin-top:10px}
      .main-area{grid-template-rows:68px minmax(0,1fr)}.topbar{padding:8px 12px;gap:8px}.topbar-title h1{font-size:1.12rem}.topbar-title p{font-size:.76rem}.profile-area{gap:7px}.profile-divider,.profile-copy{display:none}.profile-avatar{width:36px;height:36px}.profile-avatar svg{width:22px;height:22px}
      .page-content{padding:12px 11px 18px}
      .form-grid{grid-template-columns:minmax(0,1fr)}.form-grid .wide{grid-column:1}}
    @media(max-width:480px){.topbar-title h1{font-size:1rem}.topbar-title p{font-size:.68rem}.notification{width:30px}.notification svg{width:24px;height:24px}.profile-area{gap:5px}.panel-header{padding:8px 10px}.panel-header h2{font-size:.88rem}.panel-body{padding:12px}.profile-facts>div{align-items:flex-start}.notification-panel{right:-41px}}
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
  <link rel="stylesheet" href="../assets/css/admin-professional.css">
  <link rel="stylesheet" href="../assets/css/data-tables.css">
</head>
<body class="admin-dashboard">
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
        <a class="nav-item" href="admin_reports.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V11h4v9zm6 0V5h4v15zm6 0V8h4v12z"/></svg><span>Reports</span></a>
        <a class="nav-item active" href="admin_settings.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/><path d="m19.4 15 .1.1 1.1 1.7-1.8 3.1-2-.5a8 8 0 0 1-1.5.9l-.4 2h-3.6l-.4-2a8 8 0 0 1-1.5-.9l-2 .5-1.8-3.1 1.1-1.7a7 7 0 0 1 0-1.8l-1.1-1.7 1.8-3.1 2 .5a8 8 0 0 1 1.5-.9l.4-2h3.6l.4 2a8 8 0 0 1 1.5.9l2-.5 1.8 3.1-1.1 1.7a7 7 0 0 1-.1 1.8z"/></svg><span>Settings</span></a>
        <a class="nav-item logout-link" href="../logout.php?session_context=admin"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg><span>Logout</span></a>
      </nav>
    </aside>
    <div class="main-area">
      <header class="topbar">
        <div class="topbar-title"><div><h1><?= e($pageTitle) ?></h1><p>Admin profile and security preferences</p></div></div>
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
        <?php if ($success): ?><div class="notice success" role="status"><?= e($success) ?></div><?php endif; ?>

        <?php if ($profile): ?>

          <div class="settings-grid">
            <section class="panel profile-settings-panel">
              <div class="panel-header"><h2>Profile Information</h2></div>
              <div class="panel-body">
                <p class="form-note">Your display name and email are used across the clinic system.</p>
                <form method="post" class="form-grid settings-form">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="update_profile">
                  <label>Full name<input type="text" name="full_name" value="<?= e($form['full_name'] ?? $profile['full_name']) ?>" required maxlength="150" autocomplete="name"></label>
                  <label>Email address<input type="email" name="email" value="<?= e($form['email'] ?? $profile['email']) ?>" required maxlength="254" autocomplete="email"></label>
                  <div class="wide form-actions"><button class="button" type="submit">Save profile</button></div>
                </form>
                <dl class="profile-facts">
                  <div><dt>Role</dt><dd>Admin / Owner or Manager</dd></div>
                  <div><dt>Account status</dt><dd><span class="status <?= e($statusClass($profile['status'])) ?>"><?= e(ucfirst($profile['status'])) ?></span></dd></div>
                  <div><dt>Created</dt><dd><?= e(date('M j, Y', strtotime($profile['created_at']))) ?></dd></div>
                </dl>
              </div>
            </section>

            <section class="panel password-settings-panel">
              <div class="panel-header"><h2>Change Password</h2></div>
              <div class="panel-body">
                <p class="form-note">For security, enter your current password. Use 7–72 characters with uppercase, lowercase, a number, and a symbol.</p>
                <form method="post" class="form-grid settings-form" id="pw-form">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="change_password">
                  <label class="wide">Current password
                    <span class="pw-field"><input type="password" name="current_password" required autocomplete="current-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5ZM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5Zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3Z"/></svg><svg class="eye-closed" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7ZM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27ZM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2Zm4.31-.78 3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01Z"/></svg></button></span>
                  </label>
                  <label>New password
                    <span class="pw-field"><input type="password" name="new_password" id="new-pw" required minlength="7" maxlength="72" autocomplete="new-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5ZM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5Zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3Z"/></svg><svg class="eye-closed" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7ZM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27ZM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2Zm4.31-.78 3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01Z"/></svg></button></span>
                    <span class="strength" aria-hidden="true"><span id="pw-bar"></span></span>
                    <span class="hint" id="pw-hint" aria-live="polite">Use 7+ characters with uppercase, lowercase, a number, and a symbol.</span>
                  </label>
                  <label>Confirm new password
                    <span class="pw-field"><input type="password" name="confirm_password" id="confirm-pw" required minlength="7" maxlength="72" autocomplete="new-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5ZM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5Zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3Z"/></svg><svg class="eye-closed" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7ZM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27ZM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2Zm4.31-.78 3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01Z"/></svg></button></span>
                    <span class="hint" id="match-hint"></span>
                  </label>
                  <div class="wide form-actions"><button class="button" type="submit">Update password</button></div>
                </form>
              </div>
            </section>
          </div>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <script>
  (function () {
    /* Show / hide password (eye icon, same as login) */
    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
      var input = btn.parentNode.querySelector('input');
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      btn.addEventListener('click', function () {
        var show = input.type === 'password';
        var a = input.selectionStart, b = input.selectionEnd;
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', String(show));
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        input.focus({ preventScroll: true });
        try { input.setSelectionRange(a, b); } catch (err) {}
      });
    });

    /* Strength meter + match check */
    var pw = document.getElementById('new-pw'), conf = document.getElementById('confirm-pw');
    if (!pw) return;
    var bar = document.getElementById('pw-bar'), hint = document.getElementById('pw-hint'), match = document.getElementById('match-hint');
    var levels = [
      { w: '8%',   c: '#d25a5a', t: 'Too weak' },
      { w: '30%',  c: '#d25a5a', t: 'Weak' },
      { w: '55%',  c: '#e0a030', t: 'Fair' },
      { w: '80%',  c: '#3aa66f', t: 'Strong' },
      { w: '100%', c: '#1c7a4c', t: 'Very strong' }
    ];
    function missingRules(v) {
      var missing = [];
      if (v.length < 7) missing.push('7+ characters');
      if (!/[a-z]/.test(v)) missing.push('a lowercase letter');
      if (!/[A-Z]/.test(v)) missing.push('an uppercase letter');
      if (!/[0-9]/.test(v)) missing.push('a number');
      if (!/[^A-Za-z0-9]/.test(v)) missing.push('a symbol');
      return missing;
    }
    function score(v) {
      if (!v) return -1;
      var rulesMissing = missingRules(v).length;
      if (rulesMissing > 0) return Math.min(2, 4 - rulesMissing);
      return v.length >= 12 ? 4 : 3;
    }
    function update() {
      var s = score(pw.value);
      if (s < 0) {
        bar.style.width = '0';
        hint.textContent = 'Use 7+ characters with uppercase, lowercase, a number, and a symbol.';
        hint.className = 'hint';
      } else {
        var l = levels[s];
        var missing = missingRules(pw.value);
        bar.style.width = l.w;
        bar.style.backgroundColor = l.c;
        hint.textContent = missing.length ? 'Add ' + missing.join(', ') + '.' : l.t;
        hint.className = 'hint ' + (missing.length ? (s <= 1 ? 'bad' : '') : 'good');
      }
      if (!conf.value) { match.textContent = ''; match.className = 'hint'; }
      else if (conf.value === pw.value) { match.textContent = 'Passwords match'; match.className = 'hint good'; }
      else { match.textContent = 'Passwords do not match'; match.className = 'hint bad'; }
    }
    pw.addEventListener('input', update); conf.addEventListener('input', update);
    document.getElementById('pw-form').addEventListener('submit', function (event) {
      if (missingRules(pw.value).length) {
        event.preventDefault();
        update();
        pw.focus();
      } else if (conf.value !== pw.value) {
        event.preventDefault();
        update();
        conf.focus();
      }
    });
  })();
  </script>
  <script src="../assets/JS/admin_notifications.js" defer></script>
  <script src="../assets/JS/admin-shell.js" defer></script>
</body>
</html>