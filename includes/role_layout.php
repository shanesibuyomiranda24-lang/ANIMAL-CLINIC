<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notifications.php';

date_default_timezone_set('Asia/Manila');

function role_status_class(string $status): string
{
    return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $status), '_'));
}

function role_log(PDO $pdo, int $userId, string $action, string $entity, ?int $entityId, string $details): void
{
    $statement = $pdo->prepare(
        'INSERT INTO ActivityLog (user_id, action, entity, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $userId,
        mb_substr($action, 0, 60),
        mb_substr($entity, 0, 40),
        $entityId,
        mb_substr($details, 0, 255),
        mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);
}

function role_notify_role(PDO $pdo, string $role, string $category, string $title, string $message, ?string $link, string $eventKey): int
{
    return notification_send_users(
        $pdo,
        notification_role_users($pdo, $role),
        $category,
        $title,
        $message,
        $link,
        $eventKey
    );
}

function role_notify_owner(PDO $pdo, int $ownerId, string $category, string $title, string $message, ?string $link, string $eventKey): int
{
    return notification_send_users(
        $pdo,
        notification_owner_users($pdo, $ownerId),
        $category,
        $title,
        $message,
        $link,
        $eventKey
    );
}

function role_notify_vet(PDO $pdo, ?int $vetId, string $category, string $title, string $message, ?string $link, string $eventKey): int
{
    return notification_send_users(
        $pdo,
        notification_vet_users($pdo, $vetId),
        $category,
        $title,
        $message,
        $link,
        $eventKey
    );
}

function role_nav_icon(string $key, string $role = ''): string
{
    if ($role === 'admin') {
        $adminIcons = [
            'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8v9a1.2 1.2 0 0 1-1.2 1.2h-5.3v-6.2h-5V21H4.2A1.2 1.2 0 0 1 3 19.8z"/></svg>',
            'owners' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H5.4A3.4 3.4 0 0 0 2 18.4V20m7.5-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm12.5 9v-1.6a3.4 3.4 0 0 0-2.6-3.3M16.2 3.1a4 4 0 0 1 0 7.7"/></svg>',
            'appointments' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M7 14h3m-3 3h3m4-3h3"/></svg>',
            'schedules' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg>',
            'groomer_schedules' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg>',
            'services' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5zm-9 9 9 5 9-5m-18 5 9 5 9-5M12 12v9"/></svg>',
            'medical' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V11h4v9zm6 0V5h4v15zm6 0V8h4v12z"/></svg>',
            'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/><path d="m19.4 15 .1.1 1.1 1.7-1.8 3.1-2-.5a8 8 0 0 1-1.5.9l-.4 2h-3.6l-.4-2a8 8 0 0 1-1.5-.9l-2 .5-1.8-3.1 1.1-1.7a7 7 0 0 1 0-1.8l-1.1-1.7 1.8-3.1 2 .5a8 8 0 0 1 1.5-.9l.4-2h3.6l.4 2a8 8 0 0 1 1.5.9l2-.5 1.8 3.1-1.1 1.7a7 7 0 0 1-.1 1.8z"/></svg>',
        ];

        return $adminIcons[$key] ?? $adminIcons['dashboard'];
    }

    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="13" y="10" width="8" height="11" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/></svg>',
        'appointments' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M8 14h3M8 17h6"/></svg>',
        'schedules' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg>',
        'queue' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M5 4l2 2M19 4l-2 2"/></svg>',
        'pets' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 11c-2 0-3-2-2-4s3-1 4 1M16 11c2 0 3-2 2-4s-3-1-4 1M5 14c-2 1-2 4 0 5 1 1 3 0 4 0h6c1 0 3 1 4 0 2-1 2-4 0-5-2-2-4-3-7-3s-5 1-7 3Z"/></svg>',
        'owners' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M17 5a4 4 0 0 1 0 7M18 15a6 6 0 0 1 4 6"/></svg>',
        'services' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M3 12h18"/><circle cx="12" cy="12" r="9"/></svg>',
        'grooming' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6 4 4M4 20l8.5-8.5M7 17l-3-3m13-3 3-3a2.1 2.1 0 0 0-3-3l-3 3m-6 6 3 3"/><circle cx="6" cy="6" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
        'billing' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Z"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>',
        'medical' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v16H4zM8 3v4M16 3v4M12 9v8M8 13h8"/></svg>',
        'vaccinations' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 18 12-12M14 4l6 6M4 14l6 6M8 8l8 8M3 21l3-3M18 3l3-3"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.1 1.7-1.8 3.1-2-.5a8 8 0 0 1-1.5.9l-.4 2h-3.6l-.4-2a8 8 0 0 1-1.5-.9l-2 .5-1.8-3.1 1.1-1.7a7 7 0 0 1 0-1.8l-1.1-1.7 1.8-3.1 2 .5a8 8 0 0 1 1.5-.9l.4-2h3.6l.4 2a8 8 0 0 1 1.5.9l2-.5 1.8 3.1-1.1 1.7a7 7 0 0 1-.1 1.8z"/></svg>',
    ];

    return $icons[$key] ?? $icons['dashboard'];
}

function role_render(string $role, array $active, string $title, string $subtitle, callable $render): void
{
    if (!in_array($role, ['admin', 'staff', 'vet'], true)) {
        throw new InvalidArgumentException('An administrative, staff or veterinarian role is required for this layout.');
    }

    $menus = [
        'admin' => [
            ['dashboard', 'Dashboard', 'dashboard.php'],
            ['owners', 'Manage Users', 'manage_users.php'],
            ['appointments', 'Appointments', 'appointments.php'],
            ['schedules', 'Vet Schedules', 'schedule_requests.php'],
            ['groomer_schedules', 'Groomer Schedules', 'groomer_schedule_requests.php'],
            ['services', 'Inventory', 'inventory.php'],
            ['medical', 'Reports', 'admin_reports.php'],
            ['settings', 'Settings', 'admin_settings.php'],
        ],
        'staff' => [
            ['dashboard', 'Dashboard', 'staff_dashboard.php'],
            ['appointments', 'Appointments', 'appointments.php'],
            ['schedules', 'Vet Schedules', 'schedules.php'],
            ['queue', 'Patient Queue', 'queue.php'],
            ['grooming', 'Grooming', 'groomer.php'],
            ['owners', 'Clients & Pets', 'owners.php'],
            ['services', 'Services', 'services.php'],
            ['billing', 'Billing', 'billing.php'],
            ['settings', 'Settings', 'settings.php'],
        ],
        'vet' => [
            ['dashboard', 'Dashboard', 'vet_dashboard.php'],
            ['appointments', 'Appointments', 'appointments.php'],
            ['schedules', 'My Schedule', 'schedules.php'],
            ['medical', 'Medical Records', 'medical.php'],
            ['settings', 'Settings', 'settings.php'],
        ],
    ];

    $pdo = db();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $notificationError = '';
    $notificationItems = [];
    $unreadCount = 0;

    try {
        $markedId = filter_var($_GET['mark_notification_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($markedId !== false && $markedId !== null) {
            notification_mark_read($pdo, $userId, (int) $markedId);
        }
        $unreadCount = notification_unread_count($pdo, $userId);
        $notificationItems = notification_recent($pdo, $userId);
    } catch (Throwable $ex) {
        error_log('Role notification panel: ' . $ex->getMessage());
        $notificationError = 'Notifications are temporarily unavailable.';
    }

    $notificationBadge = $unreadCount > 99 ? '99+' : (string) $unreadCount;
    $fullName = (string) ($_SESSION['full_name'] ?? role_label($role));
    $profileRoleLabel = role_label($role);
    $staffPosition = '';
    if ($role === 'staff') {
        $staffRoleStatement = $pdo->prepare(
            "SELECT COALESCE(NULLIF(s.`role`, ''), 'Clinic Staff')
             FROM Users u
             JOIN Staff s ON s.staff_id = u.staff_id
             WHERE u.user_id = ? AND u.`role` = 'staff'
             LIMIT 1"
        );
        $staffRoleStatement->execute([$userId]);
        $staffPosition = $staffRoleStatement->fetchColumn();
        if (is_string($staffPosition) && trim($staffPosition) !== '') {
            $profileRoleLabel = $staffPosition;
        }
    }
    if ($role === 'staff' && $staffPosition === 'Groomer') {
        $menus['staff'] = [
            ['dashboard', 'Dashboard', 'staff_dashboard.php'],
            ['appointments', 'Appointments', 'groomer.php'],
            ['schedules', 'My Schedule', 'groomer_schedule.php'],
            ['pets', 'Pet Records', 'pets.php'],
            ['settings', 'Settings', 'settings.php'],
        ];
    }
    $initial = mb_strtoupper(mb_substr($fullName, 0, 1));
    $context = ['pdo' => $pdo, 'userId' => $userId, 'role' => $role];

    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($title) ?> | Animal Clinic</title>
  <?php if (in_array($role, ['admin', 'vet'], true)): ?><script src="../assets/JS/admin-sidebar-state.js" data-role="<?= e($role) ?>"></script><?php endif; ?>
  <style>
    :root{--blue-50:#eaf3fa;--blue-100:#c2dcf1;--blue-300:#81b7e0;--blue-500:#0284c7;--blue-700:#015b8c;--blue-900:#002e4a;--ink:#092947;--muted:#42637e;--line:#d7e8f5;--grid-line:#d3e3ef;--grid-strong:#8fbadb}
    *{box-sizing:border-box}html,body{width:100%;height:100%;margin:0}
    body.role-app{min-width:320px;height:100vh;height:100dvh;overflow:hidden;color:var(--ink);background:#f4f9fd;font:14px/1.5 Arial,Helvetica,sans-serif}
    a{color:inherit}.app-shell{width:100%;height:100%;display:grid;grid-template-columns:198px minmax(0,1fr);overflow:hidden;transition:grid-template-columns .22s ease}
    body.sidebar-collapsed .app-shell{grid-template-columns:76px minmax(0,1fr)}
    body.sidebar-collapsed .sidebar{padding-inline:8px}
    body.sidebar-collapsed .brand-lockup{margin-bottom:20px}
    body.sidebar-collapsed .brand-logo{width:52px;height:52px}
    body.sidebar-collapsed .brand-copy,body.sidebar-collapsed .nav-item span{display:none}
    body.sidebar-collapsed .nav-item{grid-template-columns:1fr;justify-items:center;padding-inline:8px}
    body.sidebar-collapsed .side-nav{gap:7px}
    .sidebar{position:relative;z-index:2;min-width:0;padding:20px 10px 16px;overflow:hidden auto;color:#fff;background:linear-gradient(180deg,var(--blue-700),var(--blue-900))}
    .sidebar:after{position:absolute;z-index:-1;right:-60px;bottom:0;left:-60px;height:210px;background:url("../assets/images/Abstract%20Blue%20Wave%20Gradient.png") center bottom/cover no-repeat;opacity:.34;content:"";pointer-events:none}
    .brand-lockup{margin:2px 0 28px;display:flex;align-items:center;flex-direction:column;gap:8px;color:#fff;text-align:center;text-decoration:none}
    .brand-logo{width:94px;height:94px;display:block;object-fit:cover;background:#fff;border:2px solid rgba(255,255,255,.55);border-radius:50%}
    .brand-copy{font-size:.9rem;font-weight:700;line-height:1.35}.brand-copy span{display:block;font-size:.8rem;font-weight:400}
    .side-nav{display:grid;gap:5px}.nav-item{min-width:0;min-height:46px;padding:10px 12px;display:grid;grid-template-columns:23px minmax(0,1fr);align-items:center;gap:12px;color:#eaf3fa;border-radius:10px;font-size:.9rem;text-decoration:none;transition:background-color .15s ease}
    .nav-item svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .nav-item:hover,.nav-item.active{color:#fff;background:var(--blue-500)}.logout-link{margin-top:16px}
    .main-area{min-width:0;min-height:0;display:grid;grid-template-rows:76px minmax(0,1fr);overflow:hidden}
    .topbar{position:relative;z-index:5;min-width:0;padding:10px clamp(18px,2.4vw,36px);display:flex;align-items:center;justify-content:space-between;gap:16px;background:linear-gradient(100deg,var(--blue-50),#fff 76%);border-bottom:1px solid var(--line);box-shadow:0 2px 9px rgba(0,46,74,.06)}
    .topbar-leading{min-width:0;display:flex;align-items:center;gap:12px}
    .sidebar-toggle{width:40px;height:40px;padding:0;display:grid;place-items:center;flex:none;color:var(--blue-700);background:#fff;border:1px solid var(--line);border-radius:11px;cursor:pointer}
    .sidebar-toggle svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-width:2}
    .sidebar-toggle:hover{background:var(--blue-50);border-color:#b8d2e5}
    .sidebar-toggle:focus-visible{outline:3px solid rgba(2,132,199,.28);outline-offset:3px}
    .topbar-title h1{margin:0;color:var(--blue-900);font-size:1.4rem;line-height:1.2}.topbar-title p{margin:2px 0 0;color:var(--blue-700);font-size:.875rem}
    .profile-area{display:flex;align-items:center;gap:12px;color:var(--blue-900)}.notification-menu{position:relative}
    .notification{position:relative;width:40px;height:40px;padding:0;display:grid;place-items:center;color:var(--blue-700);background:#fff;border:1px solid var(--line);border-radius:11px;list-style:none;cursor:pointer;transition:background-color .15s,border-color .15s}
    .notification::-webkit-details-marker{display:none}.notification svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .notification:hover,.notification-menu[open]>.notification{background:var(--blue-50);border-color:#b8d2e5}.notification:focus-visible{outline:3px solid rgba(2,132,199,.28);outline-offset:3px}
    .notification-count{position:absolute;top:-3px;right:-5px;min-width:18px;height:18px;padding:0 4px;display:grid;place-items:center;color:#fff;background:#e33b43;border:2px solid #fff;border-radius:999px;font-size:.64rem;font-weight:700}
    .notification-panel{position:absolute;top:calc(100% + 13px);right:-18px;z-index:8;width:min(360px,calc(100vw - 24px));max-height:min(460px,calc(100dvh - 100px));padding:8px;overflow-y:auto;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:13px;box-shadow:0 14px 36px rgba(0,46,74,.2)}
    .notification-panel h2{margin:4px 8px 8px;color:var(--blue-900);font-size:.95rem}.notification-item{padding:9px 8px;display:grid;grid-template-columns:10px minmax(0,1fr);align-items:start;gap:9px;border-top:1px solid #edf3f8;text-decoration:none}
    .notification-item:hover{background:var(--blue-50)}.notification-dot{width:9px;height:9px;margin-top:5px;border-radius:50%;background:var(--blue-500)}.notification-dot.read{background:#bdcbd6}
    .notification-copy{min-width:0;display:grid;gap:2px}.notification-copy strong{color:var(--blue-900);font-size:.8rem}.notification-copy small,.notification-empty{color:var(--muted);font-size:.75rem;line-height:1.4}
    .notification-empty{margin:0;padding:14px 8px;text-align:center}.notification-empty.error{color:#8c3232}
    .profile-divider{width:1px;height:34px;background:var(--blue-300)}.profile-display{min-width:0;display:flex;align-items:center;gap:9px;color:inherit;text-decoration:none}
    a.profile-display{border-radius:10px;transition:opacity .16s ease,background .16s ease}a.profile-display:hover{opacity:.82}a.profile-display:focus-visible{outline:3px solid var(--blue-500);outline-offset:4px}
    .profile-avatar{width:42px;height:42px;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-100);border-radius:50%;font-weight:700}
    .profile-copy{min-width:0;display:grid;gap:1px}.profile-name{max-width:180px;overflow:hidden;color:var(--blue-900);font-weight:700;text-overflow:ellipsis;white-space:nowrap}.profile-copy small{color:var(--muted);font-size:.72rem}
    .page-content{width:100%;max-width:none;min-width:0;min-height:0;margin:0;padding:16px clamp(14px,1.8vw,26px) 24px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable;scrollbar-width:thin;scrollbar-color:#9fc5df #eef6fc}
    .stat-grid{margin:0 0 16px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
    .stat-card{min-width:0;min-height:98px;padding:17px 19px;display:grid;align-content:center;gap:7px;background:#fff;border:1px solid #e0ebf4;border-left:4px solid var(--accent,var(--blue-500));border-radius:12px;box-shadow:0 3px 12px rgba(0,46,74,.06)}
    .stat-label{color:var(--muted);font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;line-height:1.3}
    .stat-value{color:var(--blue-900);font-size:1.75rem;font-weight:700;letter-spacing:-.03em;line-height:1.1;font-variant-numeric:tabular-nums}
    .toolbar{margin:0 0 13px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px}.toolbar .field{flex:1 1 220px}
    .panel-actions{display:flex;align-items:center;gap:10px}
    .record-tabs{width:max-content;max-width:100%;margin:0 0 14px;padding:4px;display:flex;gap:4px;overflow-x:auto;background:#e8f1f8;border:1px solid #d5e7f4;border-radius:10px}
    .record-tab{min-height:38px;padding:8px 14px;display:inline-flex;align-items:center;justify-content:center;color:#315d7c;border-radius:7px;font-size:.84rem;font-weight:700;text-decoration:none;white-space:nowrap}
    .record-tab:hover{background:#dcebf6}.record-tab[aria-current=page]{color:#fff;background:var(--blue-500)}
    .panel{min-width:0;margin:0 0 14px;overflow:hidden;background:#fff;border:1px solid #d5e7f4;border-radius:14px;box-shadow:0 3px 12px rgba(0,46,74,.07)}
    .panel-header{min-height:44px;padding:9px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:#fff;background:linear-gradient(100deg,var(--blue-700),var(--blue-900))}
    .panel-header h2{margin:0;color:inherit;font-size:1rem}.panel-body{min-width:0;padding:16px}.data-count{color:#eaf3fa;font-size:.8rem;font-weight:700}
    .table-wrap{width:100%;min-width:0;overflow-x:auto;overflow-y:hidden;background:#fff;border:1px solid var(--grid-strong);border-radius:10px}
    .records-table{width:100%;min-width:640px;table-layout:fixed;border-collapse:separate;border-spacing:0;text-align:left}
    .records-table th,.records-table td{padding:12px 14px;overflow:hidden;border-right:1px solid var(--grid-line);border-bottom:1px solid var(--grid-line);text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
    .records-table th:last-child,.records-table td:last-child{border-right:0}
    .records-table thead th{position:sticky;top:0;z-index:1;color:var(--blue-900);background:linear-gradient(180deg,#e4f1fb,#cfe5f5);border-bottom:2px solid var(--grid-strong);border-right-color:#b5d2e7;font-size:.77rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase}
    .records-table tbody td{color:#244661;background:#fff;font-size:.84rem;line-height:1.4}
    .records-table tbody tr:nth-child(even) td,.records-table tbody tr.alt td{background:#f1f7fc}
    .records-table tbody tr:hover td{background:#e1effa}
    .button,button.button{min-height:40px;padding:8px 15px;display:inline-flex;align-items:center;justify-content:center;gap:6px;color:#fff;background:var(--blue-500);border:1px solid var(--blue-500);border-radius:8px;font:inherit;font-size:.85rem;font-weight:700;text-decoration:none;cursor:pointer;transition:background-color .15s,border-color .15s}
    .button:hover,button.button:hover{background:var(--blue-700);border-color:var(--blue-700)}
    .button.secondary{color:var(--blue-700);background:#fff;border-color:#b8d2e5}.button.secondary:hover{background:var(--blue-50);border-color:var(--blue-500)}
    .button.light{color:var(--blue-900);background:#fff;border-color:#fff}.button.light:hover{background:var(--blue-50);border-color:var(--blue-50)}
    .button.ghost{color:#fff;background:transparent;border-color:rgba(255,255,255,.7)}.button.ghost:hover{background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.7)}
    .button.danger{color:#fff;background:#b93d45;border-color:#b93d45}.button.small{min-height:34px;padding:6px 12px;font-size:.8rem}
    .button:disabled{color:#9fb6c8;background:#eef4f9;border-color:#dce8f1;cursor:not-allowed}
    .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.form-grid .wide{grid-column:1/-1}.form-actions{display:flex;justify-content:flex-end;gap:9px}
    .service-picker{min-width:0;margin:0;padding:0;border:0}.service-picker legend{margin-bottom:8px;color:var(--blue-900);font-size:.82rem;font-weight:700}
    .service-picker-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .service-option{min-width:0;padding:12px;display:flex;align-items:flex-start;gap:10px;background:#fff;border:1px solid var(--grid-line);border-radius:11px;cursor:pointer}
    .service-option:hover{background:var(--blue-50);border-color:var(--blue-300)}.service-option input{width:18px;height:18px;flex:none;margin:2px 0 0;accent-color:var(--blue-500)}
    .service-option span{min-width:0;display:grid;gap:4px}.service-option strong{color:var(--blue-900);font-size:.86rem}.service-option small{color:var(--muted);font-size:.76rem;font-weight:400;line-height:1.35}
    .field,.form-grid label{min-width:0;display:grid;gap:5px;color:var(--blue-900);font-size:.82rem;font-weight:700}
    input,select,textarea{max-width:100%;font-family:inherit;font-size:.875rem}
    .field input,.field select,.field textarea,.form-grid input,.form-grid select,.form-grid textarea{width:100%;min-width:0;min-height:41px;padding:9px 11px;color:var(--ink);background:#fff;border:1px solid #bed4e5;border-radius:8px}
    .field textarea,.form-grid textarea{min-height:88px;resize:vertical}.field input:focus,.field select:focus,.field textarea:focus,.form-grid input:focus,.form-grid select:focus,.form-grid textarea:focus{outline:3px solid rgba(2,132,199,.16);border-color:var(--blue-500)}
    .field input[type=checkbox],.field input[type=radio],.form-grid input[type=checkbox],.form-grid input[type=radio]{width:auto;min-height:0}
    .hint{margin:0;color:var(--muted);font-size:.8rem;line-height:1.45}.notice{margin:0 0 14px;padding:11px 14px;border-radius:9px;font-size:.875rem}
    .notice.error{color:#842626;background:#fdeaea}.notice.success{color:#17623b;background:#e3f5e9}.notice.info{color:var(--blue-900);background:var(--blue-50)}
    .status{padding:5px 11px;display:inline-flex;align-items:center;color:#3d5a73;background:#e8eef4;border-radius:999px;font-size:.75rem;font-weight:700;line-height:1.2;white-space:nowrap}
    .status.pending,.status.waiting{color:#7a5410;background:#fff2d6}.status.scheduled,.status.active,.status.complete{color:#225d40;background:#e3f5e9}
    .status.cancelled,.status.rejected,.status.expired{color:#842626;background:#fdeaea}.status.check_in,.status.in_consultation{color:#55368f;background:#eee7fb}
    .empty{padding:34px 18px;color:var(--muted);font-size:.875rem;text-align:center}.empty strong{display:block;margin-bottom:4px;color:#4d6f8a;font-size:.95rem}
    .actions{display:flex;flex-wrap:wrap;align-items:center;gap:7px}.actions form{margin:0}
    .dialog-form{padding:18px 20px 20px;display:grid;gap:13px}
    dialog{width:min(560px,calc(100vw - 24px));max-height:calc(100vh - 24px);padding:0;color:var(--ink);background:#fff;border:0;border-radius:16px;box-shadow:0 24px 60px rgba(0,9,20,.4)}
    dialog::backdrop{background:rgba(0,28,46,.62)}.dialog-head{padding:16px 20px;color:#fff;background:linear-gradient(100deg,var(--blue-700),var(--blue-900))}
    .dialog-head h2{margin:0;font-size:1rem}
    .schedule-week{display:grid;gap:12px}.schedule-day{min-width:0;margin:0;padding:14px;border:1px solid var(--grid-line);border-radius:12px;background:#fff}
    .schedule-day.is-off{background:#f7fafc}.schedule-day-head{margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .schedule-day-head label{display:flex;align-items:center;gap:9px;color:var(--blue-900);font-size:.95rem;font-weight:700}
    .schedule-day-head input[type=checkbox]{width:18px;height:18px;accent-color:var(--blue-500)}
    .schedule-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.schedule-breaks{margin-top:12px;padding-top:12px;border-top:1px solid #e6eff6}
    .schedule-breaks h3{margin:0 0 8px;color:var(--blue-900);font-size:.82rem}.break-list{display:grid;gap:8px}.break-row{display:flex;align-items:flex-end;gap:9px}.break-row .field{flex:1 1 150px}
    .schedule-actions{margin-top:16px;display:flex;justify-content:flex-end;gap:9px}
    @media(max-width:1050px){.app-shell{grid-template-columns:178px minmax(0,1fr)}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:760px){.app-shell,body.sidebar-collapsed .app-shell{grid-template-columns:minmax(0,1fr)}.sidebar{position:fixed;inset:0 auto 0 0;width:198px;padding:20px 10px 16px;transform:translateX(-100%);box-shadow:12px 0 34px rgba(0,46,74,.24);transition:transform .22s ease}.brand-logo{width:78px;height:78px}.brand-copy{display:block}.nav-item{padding:10px 12px;grid-template-columns:23px minmax(0,1fr);justify-items:start}.nav-item span{display:inline}.sidebar-toggle{width:36px;height:36px}body.sidebar-open .sidebar{transform:translateX(0)}body.sidebar-open:after{position:fixed;inset:0;z-index:1;content:"";background:rgba(0,28,46,.38)}body.sidebar-collapsed .sidebar{padding:20px 10px 16px}.main-area{grid-template-rows:68px minmax(0,1fr)}
      .topbar{padding:8px 12px;gap:8px}.topbar-title h1{font-size:1.12rem}.topbar-title p{font-size:.78rem}.profile-copy,.profile-divider{display:none}.profile-avatar{width:36px;height:36px}.page-content{padding:12px 11px 20px}
      .stat-grid{gap:9px}.stat-card{min-height:80px;padding:12px 14px}.form-grid,.schedule-fields,.service-picker-grid{grid-template-columns:1fr}.notification-panel{right:-45px}}
    @media(max-width:480px){.topbar-title h1{font-size:1.05rem}.topbar-title p{font-size:.72rem}.notification{width:30px}.notification svg{width:24px;height:24px}.panel-header{padding:9px 11px}.panel-body{padding:12px}.panel-header h2{font-size:.95rem}.stat-grid{grid-template-columns:1fr}.profile-area{gap:6px}.notification-panel{right:-41px}}
    @media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;transition:none!important}}
  </style>
  <link rel="stylesheet" href="../assets/css/data-tables.css">
  <link rel="stylesheet" href="../assets/css/admin-professional.css">
  <link rel="stylesheet" href="../assets/css/dashboard-banner.css">
</head>
<body class="role-app <?= e($role) ?>">
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand-lockup" href="<?= e($menus[$role][0][2]) ?>">
        <img class="brand-logo" src="../assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic">
        <span class="brand-copy">Animal Clinic<span>Management System</span></span>
      </a>
      <nav class="side-nav" aria-label="Main navigation">
        <?php foreach ($menus[$role] as [$key, $label, $href]): ?>
          <a class="nav-item<?= in_array($key, $active, true) ? ' active' : '' ?>" href="<?= e($href) ?>"<?= in_array($key, $active, true) ? ' aria-current="page"' : '' ?>>
            <?= role_nav_icon($key, $role) ?><span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
        <a class="nav-item logout-link" href="../logout.php?session_context=<?= e($role) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg><span>Log out</span></a>
      </nav>
    </aside>
    <div class="main-area">
      <header class="topbar">
        <div class="topbar-leading">
          <button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Toggle navigation" aria-expanded="true" title="Toggle navigation">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <div class="topbar-title"><h1><?= e($title) ?></h1><p><?= e($subtitle) ?></p></div>
        </div>
        <div class="profile-area">
          <details class="notification-menu">
            <summary class="notification" aria-label="Open clinic notifications" title="Clinic notifications">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"/></svg>
              <?php if ($unreadCount > 0): ?><span class="notification-count"><?= e($notificationBadge) ?></span><?php endif; ?>
            </summary>
            <div class="notification-panel">
              <h2>Notifications</h2>
              <?php if ($notificationError): ?>
                <p class="notification-empty error"><?= e($notificationError) ?></p>
              <?php elseif ($notificationItems): ?>
                <?php foreach ($notificationItems as $item): ?>
                  <?php
                    $link = (string) ($item['link'] ?? '');
                    if ($role === 'staff' && $staffPosition === 'Groomer'
                        && basename((string) parse_url($link, PHP_URL_PATH)) === 'appointments.php') {
                        $link = 'staff/groomer.php';
                    }
                    if ($link !== '') {
                        $hash = '';
                        $hashPosition = strpos($link, '#');
                        if ($hashPosition !== false) {
                            $hash = substr($link, $hashPosition);
                            $link = substr($link, 0, $hashPosition);
                        }
                        $separator = strpos($link, '?') === false ? '?' : '&';
                        $link .= $separator . 'mark_notification_id=' . (int) $item['notification_id'] . $hash;
                    }
                  ?>
                  <a class="notification-item" href="<?= e($link !== '' ? app_path($link) : '#') ?>">
                    <span class="notification-dot<?= !empty($item['is_read']) ? ' read' : '' ?>" aria-hidden="true"></span>
                    <span class="notification-copy"><strong><?= e($item['title']) ?></strong><small><?= e($item['message']) ?> · <?= e(date('M j, g:i A', strtotime($item['created_at']))) ?></small></span>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="notification-empty">You’re all caught up.</p>
              <?php endif; ?>
            </div>
          </details>
          <span class="profile-divider" aria-hidden="true"></span>
          <?php if (in_array($role, ['staff', 'vet'], true)): ?>
            <a class="profile-display" href="settings.php" aria-label="Open account settings">
              <span class="profile-avatar" aria-hidden="true"><?= e($initial) ?></span>
              <span class="profile-copy"><span class="profile-name"><?= e($fullName) ?></span><small><?= e($profileRoleLabel) ?></small></span>
            </a>
          <?php else: ?>
            <div class="profile-display">
              <span class="profile-avatar" aria-hidden="true"><?= e($initial) ?></span>
              <span class="profile-copy"><span class="profile-name"><?= e($fullName) ?></span><small><?= e($profileRoleLabel) ?></small></span>
            </div>
          <?php endif; ?>
        </div>
      </header>
      <main class="page-content">
        <?php $render($context); ?>
      </main>
    </div>
  </div>
      <script src="../assets/JS/admin-shell.js" defer></script>
  <script>
    (() => {
      if (document.body.classList.contains('admin-dashboard') ||
          document.body.classList.contains('admin') ||
          (document.body.classList.contains('role-app') &&
           (document.body.classList.contains('admin') || document.body.classList.contains('vet'))) ||
          document.body.classList.contains('role-app-admin')) return;
      const button = document.getElementById('sidebar-toggle');
      if (!button) return;
      const mobile = window.matchMedia('(max-width:760px)');
      const stored = localStorage.getItem('animalClinicSidebarCollapsed');
      if (!mobile.matches && stored === '1') document.body.classList.add('sidebar-collapsed');
      function sync() {
        const collapsed = document.body.classList.contains('sidebar-collapsed');
        const open = document.body.classList.contains('sidebar-open');
        button.setAttribute('aria-expanded', mobile.matches ? String(open) : String(!collapsed));
      }
      button.addEventListener('click', () => {
        if (mobile.matches) {
          document.body.classList.toggle('sidebar-open');
        } else {
          document.body.classList.toggle('sidebar-collapsed');
          localStorage.setItem('animalClinicSidebarCollapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
        }
        sync();
      });
      mobile.addEventListener('change', () => {
        document.body.classList.remove('sidebar-open');
        sync();
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobile.matches) {
          document.body.classList.remove('sidebar-open');
          sync();
        }
      });
      sync();
    })();
  </script>
  <script>
    (() => {
      if (!document.body.classList.contains('vet')) return;
      document.querySelectorAll('input[data-table-search]').forEach((input) => {
        const panel = input.closest('.panel');
        const table = panel && panel.querySelector('.data-table-card table');
        const tbody = table && table.tBodies[0];
        const count = panel && panel.querySelector('[data-table-search-count]');
        if (!table || !tbody || !count) return;

        const rows = Array.prototype.filter.call(tbody.rows, (row) => !row.querySelector('td[colspan]'))
          .map((row) => ({ element: row, text: row.textContent.toLocaleLowerCase() }));
        let emptyRow = null;
        if (rows.length > 0) {
          emptyRow = document.createElement('tr');
          emptyRow.className = 'empty-row';
          emptyRow.hidden = true;
          const emptyCell = document.createElement('td');
          emptyCell.colSpan = table.tHead ? table.tHead.rows[0].cells.length : 1;
          const emptyState = document.createElement('div');
          emptyState.className = 'empty-state-box';
          const emptyTitle = document.createElement('strong');
          emptyTitle.textContent = 'No matching records';
          const emptyHelp = document.createElement('small');
          emptyHelp.textContent = 'Try a different search term.';
          emptyState.append(emptyTitle, emptyHelp);
          emptyCell.appendChild(emptyState);
          emptyRow.appendChild(emptyCell);
          tbody.appendChild(emptyRow);
        }

        const updateResults = () => {
          const query = input.value.trim().toLocaleLowerCase();
          let visible = 0;
          rows.forEach((item) => {
            const matches = item.text.includes(query);
            item.element.hidden = !matches;
            if (matches) visible++;
          });
          if (emptyRow) emptyRow.hidden = visible > 0;
          count.textContent = `${visible} of ${rows.length} shown`;
        };
        input.addEventListener('input', updateResults);
        updateResults();
      });
    })();
  </script>
</body>
</html>
    <?php
}
