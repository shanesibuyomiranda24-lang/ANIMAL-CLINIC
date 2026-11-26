<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/database.php';

$petId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($petId === false || $petId === null) {
    http_response_code(404);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$accessSql = "FROM Pets p
              JOIN Users u ON u.user_id = ?
              WHERE p.pet_id = ? AND p.photo_data IS NOT NULL
                AND (
                    u.`role` IN ('admin', 'staff')
                    OR (u.`role` = 'client' AND u.owner_id = p.owner_id)
                    OR (
                        u.`role` = 'vet'
                        AND EXISTS (
                            SELECT 1
                            FROM Appointments a
                            WHERE a.pet_id = p.pet_id AND a.vet_id = u.vet_id
                        )
                    )
                )";

try {
    $pdo = db();
    $metadataStatement = $pdo->prepare(
        'SELECT p.photo_mime, p.photo_updated_at, OCTET_LENGTH(p.photo_data) AS photo_size ' . $accessSql
    );
    $metadataStatement->execute([$userId, (int) $petId]);
    $metadata = $metadataStatement->fetch();
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!$metadata || !in_array($metadata['photo_mime'], $allowedTypes, true)) {
        http_response_code(404);
        exit;
    }

    $updatedAt = strtotime((string) $metadata['photo_updated_at']);
    $etag = '"' . hash(
        'sha256',
        (int) $petId . '|' . (string) $metadata['photo_updated_at'] . '|' . (int) $metadata['photo_size']
    ) . '"';
    header('Content-Type: ' . $metadata['photo_mime']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300, must-revalidate');
    header('ETag: ' . $etag);
    if ($updatedAt !== false) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $updatedAt) . ' GMT');
    }

    $clientEtags = array_map('trim', explode(',', (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
    $notModified = in_array($etag, $clientEtags, true);
    if (!$notModified && !isset($_SERVER['HTTP_IF_NONE_MATCH']) && $updatedAt !== false) {
        $modifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        $notModified = $modifiedSince !== false && $updatedAt <= $modifiedSince;
    }
    if ($notModified) {
        http_response_code(304);
        exit;
    }

    $photoStatement = $pdo->prepare('SELECT p.photo_data ' . $accessSql);
    $photoStatement->execute([$userId, (int) $petId]);
    $photoData = $photoStatement->fetchColumn();
    if (is_resource($photoData)) {
        $photoData = stream_get_contents($photoData);
    }
    if ($photoData === false) {
        http_response_code(404);
        exit;
    }
    if (!is_string($photoData)) {
        error_log('Pet photo response failed: stored image data was not readable.');
        http_response_code(500);
        header('Cache-Control: no-store');
        exit;
    }

    header('Content-Length: ' . strlen($photoData));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $photoData;
    }
} catch (PDOException $ex) {
    error_log('Pet photo response failed: ' . $ex->getMessage());
    http_response_code(503);
    header('Cache-Control: no-store');
}
