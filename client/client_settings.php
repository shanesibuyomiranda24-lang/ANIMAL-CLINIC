<?php
// client/client_settings.php - profile details and password
require_once __DIR__ . '/client_layout.php';
$c = client_boot();
$pdo = $c['pdo'];
$errors = ['profile' => '', 'password' => ''];
$owner = [];
$userRow = [];
$phoneKey = null;
$pwKey = null;
if (!$c['dbError']) {
    $owner = qall($pdo, 'SELECT * FROM petowners WHERE owner_id = ?', [$c['ownerId']])[0] ?? [];
    $userRow = qall($pdo, 'SELECT * FROM users WHERE user_id = ?', [$c['userId']])[0] ?? [];
    foreach (['phone', 'contact_number', 'contact_no', 'mobile', 'phone_number'] as $k) {
        if (array_key_exists($k, $owner)) { $phoneKey = $k; break; }
    }
    foreach (['password_hash', 'password'] as $k) {
        if (array_key_exists($k, $userRow)) { $pwKey = $k; break; }
    }
}

if (!$c['dbError'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = posted_string('action');
    if ($action === 'profile') {
        $name = trim(preg_replace('/\s+/', ' ', posted_string('full_name')));
        $phone = trim(posted_string('phone'));
        $addr = trim(posted_string('address'));
        if (!csrf_valid(posted_string('csrf'))) {
            $errors['profile'] = 'Your session expired. Please try again.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !valid_full_name($name)) {
            $errors['profile'] = 'Enter your full name using letters (2 to 100 characters).';
        } elseif ($phoneKey && $phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            $errors['profile'] = 'Enter a valid phone number.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET full_name = ? WHERE user_id = ?')->execute([$name, $c['userId']]);
                $set = 'full_name = ?';
                $args = [$name];
                if ($phoneKey) { $set .= ", `$phoneKey` = ?"; $args[] = $phone !== '' ? $phone : null; }
                if (array_key_exists('address', $owner)) { $set .= ', address = ?'; $args[] = $addr !== '' ? mb_substr($addr, 0, 150) : null; }
                $args[] = $c['ownerId'];
                $pdo->prepare("UPDATE petowners SET $set WHERE owner_id = ?")->execute($args);
                $pdo->commit();
                $_SESSION['full_name'] = $name;
                header('Location: client_settings.php?ok=saved');
                exit;
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('settings profile: ' . $ex->getMessage());
                $errors['profile'] = 'Your changes could not be saved. Please try again.';
            }
        }
    } elseif ($action === 'password' && $pwKey) {
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $conf = (string) ($_POST['confirm'] ?? '');
        if (!csrf_valid(posted_string('csrf'))) {
            $errors['password'] = 'Your session expired. Please try again.';
        } elseif (!password_verify($cur, (string) $userRow[$pwKey])) {
            $errors['password'] = 'Your current password is not correct.';
        } elseif (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors['password'] = 'Use at least 8 characters with an uppercase letter, a lowercase letter and a number.';
        } elseif ($new !== $conf) {
            $errors['password'] = 'The new passwords do not match.';
        } else {
            try {
                $pdo->prepare("UPDATE users SET `$pwKey` = ? WHERE user_id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $c['userId']]);
                header('Location: client_settings.php?ok=saved');
                exit;
            } catch (Throwable $ex) {
                error_log('settings password: ' . $ex->getMessage());
                $errors['password'] = 'Your password could not be changed. Please try again.';
            }
        }
    }
}

client_header($c, 'Settings', 'Your contact details and password', 'settings');
?>
        <div class="settings-grid<?= $pwKey ? '' : ' single' ?>">
          <section class="panel settings-panel">
            <div class="panel-header">
              <div class="settings-panel-heading"><h2>Profile information</h2><p>Keep your contact details up to date.</p></div>
              <span class="settings-badge">PROFILE</span>
            </div>
            <form class="panel-body settings-form" method="post" action="client_settings.php">
              <?php if ($errors['profile']): ?><div class="notice error" role="alert"><?= e($errors['profile']) ?></div><?php endif; ?>
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
              <div class="form-grid">
                <label class="field">Full name<input name="full_name" required minlength="2" maxlength="100" autocomplete="name" value="<?= e($_POST['full_name'] ?? $c['fullName']) ?>"></label>
                <label class="field">Email address<input type="email" value="<?= e($userRow['email'] ?? $owner['email'] ?? ($_SESSION['email'] ?? '')) ?>" readonly></label>
                <?php if ($phoneKey): ?><label class="field">Phone number<input type="tel" name="phone" maxlength="20" autocomplete="tel" value="<?= e($_POST['phone'] ?? $owner[$phoneKey] ?? '') ?>" placeholder="+63 900 000 0000"></label><?php endif; ?>
                <?php if (array_key_exists('address', $owner)): ?><label class="field">Address<input name="address" maxlength="150" autocomplete="street-address" value="<?= e($_POST['address'] ?? $owner['address'] ?? '') ?>"></label><?php endif; ?>
              </div>
              <div class="settings-note"><span class="settings-note-icon" aria-hidden="true">i</span><p>Keep your phone number current so the clinic can reach you about your pet.</p></div>
              <div class="settings-action"><span class="hint">Your email address is managed with your account.</span><button class="button" type="submit">Save profile</button></div>
            </form>
          </section>
          <?php if ($pwKey): ?>
          <section class="panel settings-panel">
            <div class="panel-header">
              <div class="settings-panel-heading"><h2>Change password</h2><p>Protect access to your pet-care account.</p></div>
              <span class="settings-badge">SECURITY</span>
            </div>
            <form class="panel-body settings-form" method="post" action="client_settings.php" autocomplete="off">
              <?php if ($errors['password']): ?><div class="notice error" role="alert"><?= e($errors['password']) ?></div><?php endif; ?>
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password">
              <div class="form-grid">
                <label class="field full">Current password<input type="password" name="current" required autocomplete="current-password"></label>
                <label class="field">New password<input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
                <label class="field">Confirm new password<input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
              </div>
              <div class="settings-note"><span class="settings-note-icon" aria-hidden="true">i</span><p>Use at least 8 characters, including an uppercase letter, a lowercase letter, and a number.</p></div>
              <div class="settings-action"><span class="hint">You’ll use the new password the next time you sign in.</span><button class="button" type="submit">Update password</button></div>
            </form>
          </section>
          <?php endif; ?>
        </div>
<?php client_footer($c);