<?php
final class NotificationService
{
    public static function send(PDO $pdo, int $userId, string $category, string $title, string $message, ?string $link, string $eventKey): void
    {
        $pdo->prepare('INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key) VALUES(?,?,?,?,?,?)')
            ->execute([$userId, mb_substr($category, 0, 30), mb_substr($title, 0, 120), mb_substr($message, 0, 255), $link, mb_substr($eventKey, 0, 120)]);
    }
}
