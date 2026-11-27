<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/passwords.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_login(true);

$role = current_role();
$error = '';

if (empty($_SESSION['must_change_password'])) {
    header('Location: ' . home_for_role($role));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $newPassword = posted_string('new_password');
    $confirmPassword = posted_string('confirm_password');

    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (($passwordError = password_policy_error($newPassword)) !== '') {
        $error = $passwordError;
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new password and confirmation do not match.';
    } else {
        $pdo = null;
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $account = $pdo->prepare(
                "SELECT password_hash FROM Users
                 WHERE user_id = ? AND `role` = ? AND status = 'active'
                 FOR UPDATE"
            );
            $account->execute([(int) $_SESSION['user_id'], $role]);
            $currentHash = $account->fetchColumn();
            if (!is_string($currentHash)) {
                $pdo->rollBack();
                $error = 'Your active account could not be verified. Contact the clinic administrator.';
            } elseif (password_verify($newPassword, $currentHash)) {
                $pdo->rollBack();
                $error = 'Choose a password different from your temporary password.';
            } else {
                $pdo->prepare(
                    "UPDATE Users
                     SET password_hash = ?, must_change_password = 0
                     WHERE user_id = ? AND `role` = ? AND status = 'active'"
                )->execute([
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    (int) $_SESSION['user_id'],
                    $role,
                ]);
                audit_log_record(
                    $pdo,
                    (int) $_SESSION['user_id'],
                    'user.password.updated',
                    'user',
                    (int) $_SESSION['user_id'],
                    'Temporary password replaced with a new password'
                );
                $pdo->commit();
                unset($_SESSION['csrf']);
                $_SESSION['must_change_password'] = false;
                session_regenerate_id(true);
                header('Location: ' . home_for_role($role));
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Forced password change failed: ' . $exception->getMessage());
            $error = 'Your password could not be changed. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set a new password | Animal Clinic</title>
  <link rel="stylesheet" href="assets/css/auth-flow.css">
</head>
<body class="auth-page">
  <main class="welcome-card">
    <section class="intro" aria-label="Animal Clinic introduction">
      <h1><img src="assets/images/Healthy%20Pets,%20Happier%20Lives.png" alt="Healthy pets, happier lives"></h1>
      <p>Book appointments, keep track of medical records and vaccinations, and manage bills in one place.</p>
      <img class="pets" src="assets/images/pet.png" alt="A happy dog and cat">
    </section>
    <section class="actions" aria-labelledby="change-password-title">
      <a class="back" href="logout.php?session_context=<?= e($role) ?>">Log out</a>
      <div class="brand"><img src="assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic logo"></div>
      <h2 id="change-password-title">Set a New Password</h2>
      <p class="subtitle">Your administrator issued a temporary password. Choose a new password to continue.</p>
      <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form class="auth-form" method="post" action="change_password.php?session_context=<?= e($role) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="form-label" for="new-password">New password
          <input id="new-password" name="new_password" type="password" required minlength="7" maxlength="14" autocomplete="new-password">
          <span class="field-hint"><?= e(password_policy_description()) ?></span>
        </label>
        <label class="form-label" for="confirm-password">Confirm new password
          <input id="confirm-password" name="confirm_password" type="password" required minlength="7" maxlength="14" autocomplete="new-password">
        </label>
        <button class="button" type="submit">Save New Password</button>
      </form>
    </section>
  </main>
</body>
</html>
