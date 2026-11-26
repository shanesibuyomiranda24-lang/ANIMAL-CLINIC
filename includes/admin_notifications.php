<?php
require_once __DIR__ . '/notifications.php';

function admin_notification_href(?string $link): string
{
    $routes = [
        'admin/dashboard.php' => 'dashboard.php',
        'admin/appointments.php' => 'appointments.php',
        'admin/schedule_requests.php' => 'schedule_requests.php',
        'admin/groomer_schedule_requests.php' => 'groomer_schedule_requests.php',
        'admin/inventory.php' => 'inventory.php',
        'admin/admin_reports.php' => 'admin_reports.php',
        'admin/manage_users.php' => 'manage_users.php',
        'admin/admin_settings.php' => 'admin_settings.php',
        'staff/appointments.php' => 'appointments.php',
        'staff/billing.php' => 'admin_reports.php',
        'vet/appointments.php' => 'appointments.php',
        'client/appointments.php' => 'appointments.php',
        'client/billing.php' => 'admin_reports.php',
        'client/vaccinations.php' => 'appointments.php',
        'client/medical_records.php' => 'appointments.php',
    ];
    if ($link === null || $link === '') {
        return 'dashboard.php';
    }

    $path = (string) parse_url($link, PHP_URL_PATH);
    $route = $routes[$path] ?? 'dashboard.php';
    $fragment = (string) parse_url($link, PHP_URL_FRAGMENT);
    if ($fragment !== '' && preg_match('/\A[a-z0-9_-]+\z/i', $fragment)) {
        $route .= '#' . $fragment;
    }

    return $route;
}

function dashboard_notification_state(PDO $pdo, int $userId): array
{
    $items = [];
    foreach (notification_recent($pdo, $userId, 8) as $row) {
        $category = (string) $row['category'];
        $kind = preg_match('/\A[a-z0-9_-]{1,30}\z/i', $category) ? strtolower($category) : 'general';
        if ($kind === 'appointment') {
            $kind = 'appointments';
        }
        $items[] = [
            'id' => (int) $row['notification_id'],
            'label' => (string) $row['title'],
            'detail' => (string) $row['message'] . ' · ' . date('M j, g:i A', strtotime((string) $row['created_at'])),
            'href' => admin_notification_href($row['link'] !== null ? (string) $row['link'] : null),
            'kind' => $kind,
            'count' => 0,
            'unread' => (int) $row['is_read'] === 0,
        ];
    }

    return [
        'count' => notification_unread_count($pdo, $userId),
        'items' => $items,
        'error' => '',
    ];
}
