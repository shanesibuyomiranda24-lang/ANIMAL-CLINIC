<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';

$isLocalRequest = PHP_SAPI === 'cli'
    || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

if (!$isLocalRequest) {
    http_response_code(404);
    exit;
}

$fullName = 'admin';
$email = 'admin@animalclinic.local';
$password = 'admin123';
$message = '';
$messageType = '';
$setupComplete = false;

try {
    $check = db()->prepare("SELECT user_id FROM users WHERE `role` = 'admin' OR email = ? LIMIT 1");
    $check->execute([$email]);
    $setupComplete = (bool) $check->fetch();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$setupComplete) {
        if (!csrf_valid(posted_string('csrf'))) {
            $message = 'Your session expired. Reload this page and try again.';
            $messageType = 'error';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT user_id FROM users WHERE `role` = 'admin' OR email = ? LIMIT 1 FOR UPDATE");
            $check->execute([$email]);

            if ($check->fetch()) {
                $pdo->rollBack();
                $setupComplete = true;
                $message = 'An Admin account already exists. Initial setup is disabled.';
                $messageType = 'notice';
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO users (full_name, email, password_hash, `role`, status) VALUES (?, ?, ?, 'admin', 'active')"
                );
                $insert->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $pdo->commit();
                $setupComplete = true;
                $message = 'Initial Admin created. Delete setup_admin.php now. Sign in with admin@animalclinic.local and the development password.';
                $messageType = 'success';
            }
        }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && !$setupComplete) {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }
} catch (PDOException $ex) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($ex->getMessage());
    $message = 'Could not create the Admin account. Confirm that the temporary database migrations have been applied.';
    $messageType = 'error';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Initial Admin Setup | Animal Clinic</title>
  <style>
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; color: #17324d; background: #edf4f8; font: 16px Arial, sans-serif; }
    main { width: min(100%, 520px); padding: 28px; background: #fff; border-radius: 16px; box-shadow: 0 16px 48px rgba(0, 40, 70, .12); }
    h1 { margin-top: 0; font-size: 1.6rem; }
    p { line-height: 1.5; }
    .message { padding: 12px 14px; border-radius: 8px; background: #e8f4ec; color: #185b32; }
    .message.error { background: #feecec; color: #8d2424; }
    .message.notice { background: #edf4f8; color: #17324d; }
    button { padding: 12px 18px; color: #fff; background: #087eae; border: 0; border-radius: 8px; font: inherit; font-weight: 700; cursor: pointer; }
  </style>
</head>
<body>
  <main>
    <h1>Initial Admin setup</h1>
    <p>This local-only, one-time setup creates the development Admin account. Do not deploy this script to a public server.</p>
    <?php if ($message): ?><p class="message <?= e($messageType) ?>" role="status"><?= e($message) ?></p><?php endif; ?>
    <?php if (!$setupComplete): ?>
      <p>Account: <strong>admin@animalclinic.local</strong><br>Password: <strong>admin123</strong></p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button type="submit">Create initial Admin</button>
      </form>
    <?php else: ?>
      <p>Setup is disabled because an Admin account already exists or the setup email is already in use. Remove this file from the project.</p>
    <?php endif; ?>
  </main>
</body>
</html>
