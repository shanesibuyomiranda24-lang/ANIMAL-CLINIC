<?php
require_once __DIR__ . '/database.php';

/** Record an important action without allowing logging to break the main workflow. */
function audit_log_record(PDO $pdo, ?int $userId, string $action, string $entity, ?int $entityId = null, string $details = ''): void
{
    try {
        $pdo->prepare(
            'INSERT INTO ActivityLog (user_id, action, entity, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            mb_substr($action, 0, 60),
            mb_substr($entity, 0, 40),
            $entityId,
            mb_substr($details, 0, 255),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $ex) {
        error_log('Activity log failed: ' . $ex->getMessage());
    }
}

function audit_log_recent(PDO $pdo, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $st = $pdo->prepare(
        'SELECT log_id, user_id, action, entity, entity_id, details, ip_address, created_at
         FROM ActivityLog ORDER BY created_at DESC, log_id DESC LIMIT ' . (int) $limit
    );
    $st->execute();
    return $st->fetchAll();
}
