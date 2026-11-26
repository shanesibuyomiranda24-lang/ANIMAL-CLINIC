<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';

/** Create one deduplicated notification for a specific user. */
function notification_send(PDO $pdo, int $userId, string $category, string $title, string $message, ?string $link, string $eventKey): bool
{
    try {
        $st = $pdo->prepare(
            'INSERT IGNORE INTO Notifications (user_id, category, title, message, link, event_key)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $userId,
            mb_substr($category, 0, 30),
            mb_substr($title, 0, 120),
            mb_substr($message, 0, 255),
            $link,
            mb_substr($eventKey, 0, 120),
        ]);
        return $st->rowCount() > 0;
    } catch (Throwable $ex) {
        error_log('Notification send failed: ' . $ex->getMessage());
        return false;
    }
}

function notification_send_users(PDO $pdo, array $userIds, string $category, string $title, string $message, ?string $link, string $eventKey): int
{
    $sent = 0;
    foreach (array_values(array_unique(array_map('intval', $userIds))) as $userId) {
        if ($userId > 0 && notification_send($pdo, $userId, $category, $title, $message, $link, $eventKey)) {
            $sent++;
        }
    }
    return $sent;
}

function notification_role_users(PDO $pdo, string $role): array
{
    $st = $pdo->prepare("SELECT user_id FROM Users WHERE `role` = ? AND status = 'active'");
    $st->execute([$role]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function notification_owner_users(PDO $pdo, int $ownerId): array
{
    $st = $pdo->prepare("SELECT user_id FROM Users WHERE `role` = 'client' AND owner_id = ? AND status = 'active'");
    $st->execute([$ownerId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function notification_vet_users(PDO $pdo, ?int $vetId): array
{
    if (!$vetId) {
        return [];
    }
    $st = $pdo->prepare("SELECT user_id FROM Users WHERE `role` = 'vet' AND vet_id = ? AND status = 'active'");
    $st->execute([$vetId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function notification_unread_count(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM Notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

function notification_recent(PDO $pdo, int $userId, int $limit = 8): array
{
    $limit = max(1, min(50, $limit));
    $st = $pdo->prepare(
        'SELECT notification_id, category, title, message, link, is_read, created_at
         FROM Notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT ' . (int) $limit
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

function notification_mark_read(PDO $pdo, int $userId, int $notificationId): void
{
    $pdo->prepare(
        'UPDATE Notifications SET is_read = 1, read_at = NOW()
         WHERE notification_id = ? AND user_id = ?'
    )->execute([$notificationId, $userId]);
}

function notification_mark_all_read(PDO $pdo, int $userId): void
{
    $pdo->prepare(
        'UPDATE Notifications SET is_read = 1, read_at = NOW()
         WHERE user_id = ? AND is_read = 0'
    )->execute([$userId]);
}
