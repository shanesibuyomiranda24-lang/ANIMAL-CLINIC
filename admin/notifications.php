<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/admin_notifications.php';
require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = db();
    $userId = (int) $_SESSION['user_id'];

    if ($method === 'POST') {
        if (!csrf_valid($_POST['csrf'] ?? null)) {
            http_response_code(403);
            echo json_encode(['error' => 'Request expired. Refresh the page.']);
            exit;
        }

        $action = posted_string('action');
        if ($action === 'read_all') {
            notification_mark_all_read($pdo, $userId);
        } elseif ($action === 'read') {
            $notificationId = filter_var(posted_string('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($notificationId === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Choose a valid notification.']);
                exit;
            }
            notification_mark_read($pdo, $userId, (int) $notificationId);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported notification action.']);
            exit;
        }
    }

    $state = dashboard_notification_state($pdo, $userId);
    echo json_encode(
        ['unread_count' => $state['count'], 'items' => $state['items']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Notification data is unavailable.']);
}
