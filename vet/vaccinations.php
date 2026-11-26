<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require __DIR__ . '/medical.php';
    exit;
}

$query = ['tab' => 'vaccines'];
$pet = $_GET['pet'] ?? null;
if (is_scalar($pet)) {
    $petId = filter_var((string) $pet, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($petId !== false) {
        $query['pet'] = (int) $petId;
    }
}

header('Location: medical.php?' . http_build_query($query), true, 302);
exit;
