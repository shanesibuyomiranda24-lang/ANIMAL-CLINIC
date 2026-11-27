<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$email = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = normalize_email(posted_string('email'));
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (!valid_email($email)) {
        $error = 'Enter a valid registered email address.';
    } else {
        $pdo = null;
        try {
            $pdo = db();
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $clientIp = is_string($clientIp) && filter_var($clientIp, FILTER_VALIDATE_IP)
                ? $clientIp
                : null;

            $pdo->beginTransaction();
            $rateLimited = false;
            if ($clientIp !== null) {
                $rateLimit = $pdo->prepare(
                    'SELECT COUNT(*) FROM PasswordRecoveryRequests
                     WHERE request_ip_address = ?
                       AND submitted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
                );
                $rateLimit->execute([$clientIp]);
                $rateLimited = (int) $rateLimit->fetchColumn() >= 5;
            }

            if (!$rateLimited) {
                $account = $pdo->prepare('SELECT user_id FROM Users WHERE email = ? LIMIT 1');
                $account->execute([$email]);
                $userId = $account->fetchColumn();

                $pending = $pdo->prepare(
                    "SELECT request_id FROM PasswordRecoveryRequests
                     WHERE request_email = ? AND status = 'Pending'
                     LIMIT 1"
                );
                $pending->execute([$email]);
                if (!$pending->fetchColumn()) {
                    $insert = $pdo->prepare(
                        'INSERT INTO PasswordRecoveryRequests
                            (request_email, user_id, request_ip_address)
                         VALUES (?, ?, ?)'
                    );
                    $insert->execute([
                        $email,
                        $userId === false ? null : (int) $userId,
                        $clientIp,
                    ]);
                }
            }

            $pdo->commit();
            header('Location: recovery_submitted.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Password recovery request submission failed: ' . $exception->getMessage());
            $error = 'We could not submit your request right now. Please try again later.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password | Animal Clinic</title>
  <link rel="stylesheet" href="assets/css/auth-flow.css">
</head>
<body class="auth-page">
  <main class="welcome-card">
    <section class="intro" aria-label="Animal Clinic introduction">
      <h1><img src="assets/images/Healthy%20Pets,%20Happier%20Lives.png" alt="Healthy pets, happier lives"></h1>
      <p>Book appointments, keep track of medical records and vaccinations, and manage bills in one place.</p>
      <img class="pets" src="assets/images/pet.png" alt="A happy dog and cat">
    </section>
    <section class="actions" aria-labelledby="recovery-title">
      <a class="back" href="login.php"><span aria-hidden="true">&larr;</span> Back to Login</a>
      <div class="brand"><img src="assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic logo"></div>
      <h2 id="recovery-title">Forgot Password?</h2>
      <p class="subtitle">Don't worry! We'll help you recover access to your account.</p>
      <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form class="auth-form" method="post" action="forgot_password.php" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="form-label" for="recovery-email">Registered Email Address
          <input id="recovery-email" type="email" name="email" value="<?= e($email) ?>" placeholder="Enter your registered email" required maxlength="254" autocomplete="email">
        </label>
        <div class="recovery-notice">
          <strong>Password Recovery Notice</strong><br>
          For your account's security, password resets are handled by the clinic administrator. Submit a recovery request for assistance.
        </div>
        <button class="button" type="submit">Request Password Assistance</button>
      </form>
      <p class="footer-link">Remembered your password? <a class="text-link" href="login.php">Back to Login</a></p>
    </section>
  </main>
</body>
</html>
