<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function notification_client_link(?string $link): string
{
    $routes = [
        'client/client_dashboard.php' => '../client/client_dashboard.php',
        'client/book_appointment.php' => '../client/book_appointment.php',
        'client/appointments.php' => '../client/appointments.php',
        'client/my_pets.php' => '../client/my_pets.php',
        'client/vaccinations.php' => '../client/vaccinations.php',
        'client/medical_records.php' => '../client/medical_records.php',
        'client/billing.php' => '../client/billing.php',
        'client/client_settings.php' => '../client/client_settings.php',
    ];
    return $routes[$link ?? ''] ?? '../client/client_dashboard.php';
}

function notification_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    notification_json(['error' => 'Sign in to view notifications.'], 401);
}
if (current_role() !== 'client') {
    notification_json(['error' => 'Client notifications are not available for this account.'], 403);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    notification_json(['error' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $userId = (int) $_SESSION['user_id'];

    if ($method === 'POST') {
        if (!csrf_valid(posted_string('csrf'))) {
            notification_json(['error' => 'Request expired. Refresh the page and try again.'], 403);
        }

        $action = posted_string('action');
        if ($action === 'read_all') {
            notification_mark_all_read($pdo, $userId);
        } elseif ($action === 'read') {
            $notificationId = filter_var(posted_string('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$notificationId) {
                notification_json(['error' => 'Choose a valid notification.'], 400);
            }
            notification_mark_read($pdo, $userId, (int) $notificationId);
        } else {
            notification_json(['error' => 'Unsupported notification action.'], 400);
        }
    }

    $items = [];
    foreach (notification_recent($pdo, $userId, 8) as $row) {
        $category = (string) $row['category'];
        $items[] = [
            'id' => (int) $row['notification_id'],
            'label' => (string) $row['title'],
            'detail' => (string) $row['message'],
            'href' => notification_client_link($row['link'] !== null ? (string) $row['link'] : null),
            'kind' => preg_match('/\A[a-z0-9_-]{1,30}\z/i', $category) ? strtolower($category) : 'general',
            'unread' => (int) $row['is_read'] === 0,
        ];
    }

    notification_json([
        'unread_count' => notification_unread_count($pdo, $userId),
        'items' => $items,
    ]);
} catch (PDOException $ex) {
    error_log('Notification API failed: ' . $ex->getMessage());
    notification_json(['error' => 'Notifications are temporarily unavailable.'], 503);
}
