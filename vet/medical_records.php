<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require __DIR__ . '/medical.php';
    exit;
}

$query = [];
foreach (['pet', 'appointment'] as $key) {
    $value = $_GET[$key] ?? null;
    if (is_scalar($value)) {
        $id = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false) {
            $query[$key] = (int) $id;
        }
    }
}
if (isset($_GET['tab']) && $_GET['tab'] === 'vaccines') {
    $query['tab'] = 'vaccines';
}

$target = 'medical.php' . ($query ? '?' . http_build_query($query) : '');
header('Location: ' . $target, true, 302);
exit;
