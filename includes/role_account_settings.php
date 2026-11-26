<?php
require_once __DIR__ . '/role_layout.php';

function role_account_settings_password_is_strong(string $password): bool
{
    return strlen($password) >= 7
        && strlen($password) <= 72
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function role_account_settings(string $role): void
{
    if (!in_array($role, ['staff', 'vet'], true)) {
        throw new InvalidArgumentException('Staff or veterinarian settings are required.');
    }

    require_role([$role]);

    $account = $role === 'vet'
        ? ['table' => 'Veterinarians', 'key' => 'vet_id']
        : ['table' => 'Staff', 'key' => 'staff_id'];
    $userId = (int) $_SESSION['user_id'];
    $flashKey = 'role_settings_flash_' . $role;
    $pdo = db();
    $error = '';
    $flash = (string) ($_SESSION[$flashKey] ?? '');
    unset($_SESSION[$flashKey]);

    $profileQuery = $role === 'vet'
        ? "SELECT u.full_name, u.email, u.status, u.created_at, 'Veterinarian' AS account_role
           FROM Users u
           JOIN Veterinarians r ON r.vet_id = u.vet_id
           WHERE u.user_id = ? AND u.`role` = 'vet' AND u.status = 'active'"
        : "SELECT u.full_name, u.email, u.status, u.created_at,
                  COALESCE(NULLIF(r.`role`, ''), 'Clinic Staff') AS account_role
           FROM Users u
           JOIN Staff r ON r.staff_id = u.staff_id
           WHERE u.user_id = ? AND u.`role` = 'staff' AND u.status = 'active'";
    $profileStatement = $pdo->prepare($profileQuery);
    $profileStatement->execute([$userId]);
    $profile = $profileStatement->fetch();
    if (!$profile) {
        http_response_code(403);
        exit('An active staff or veterinarian profile is required.');
    }

    $form = ['full_name' => (string) $profile['full_name'], 'email' => (string) $profile['email']];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = posted_string('action');
        if (!csrf_valid(posted_string('csrf'))) {
            $error = 'Your session expired. Reload the page and try again.';
        } elseif ($action === 'update_profile') {
            $form = [
                'full_name' => trim(posted_string('full_name')),
                'email' => normalize_email(posted_string('email')),
            ];
            if (!valid_full_name($form['full_name']) || mb_strlen($form['full_name']) > 100) {
                $error = 'Enter a valid full name up to 100 characters long.';
            } elseif (!valid_email($form['email'])) {
                $error = 'Enter a valid email address.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $accountLock = $pdo->prepare(
                        "SELECT {$account['key']} FROM Users
                         WHERE user_id = ? AND `role` = ? AND status = 'active'
                         FOR UPDATE"
                    );
                    $accountLock->execute([$userId, $role]);
                    $entityId = (int) $accountLock->fetchColumn();
                    if ($entityId < 1) {
                        throw new InvalidArgumentException('Your account profile is no longer available.');
                    }

                    $entityLock = $pdo->prepare(
                        "SELECT {$account['key']} FROM {$account['table']}
                         WHERE {$account['key']} = ?
                         FOR UPDATE"
                    );
                    $entityLock->execute([$entityId]);
                    if (!$entityLock->fetchColumn()) {
                        throw new InvalidArgumentException('Your account profile is no longer available.');
                    }

                    $emailCheck = $pdo->prepare(
                        'SELECT user_id FROM Users WHERE email = ? AND user_id <> ? LIMIT 1'
                    );
                    $emailCheck->execute([$form['email'], $userId]);
                    if ($emailCheck->fetchColumn()) {
                        throw new InvalidArgumentException('That email address is already associated with another account.');
                    }

                    $pdo->prepare(
                        'UPDATE Users SET full_name = ?, email = ? WHERE user_id = ? AND `role` = ?'
                    )->execute([$form['full_name'], $form['email'], $userId, $role]);
                    $pdo->prepare(
                        "UPDATE {$account['table']} SET full_name = ? WHERE {$account['key']} = ?"
                    )->execute([$form['full_name'], $entityId]);
                    role_log($pdo, $userId, $role . '.profile.updated', 'user', $userId, 'Account profile details updated');
                    $pdo->commit();

                    $_SESSION['full_name'] = $form['full_name'];
                    $_SESSION['email'] = $form['email'];
                    $_SESSION[$flashKey] = 'Profile details updated.';
                    header('Location: settings.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $duplicateEmail = $exception instanceof PDOException
                        && (int) ($exception->errorInfo[1] ?? 0) === 1062;
                    $error = $exception instanceof InvalidArgumentException
                        ? $exception->getMessage()
                        : ($duplicateEmail
                            ? 'That email address is already associated with another account.'
                            : 'Profile details could not be updated.');
                    error_log('Role account profile update: ' . $exception->getMessage());
                }
            }
        } elseif ($action === 'change_password') {
            $currentPassword = posted_string('current_password');
            $newPassword = posted_string('new_password');
            $confirmPassword = posted_string('confirm_password');

            $passwordStatement = $pdo->prepare(
                'SELECT password_hash FROM Users WHERE user_id = ? AND `role` = ? AND status = \'active\' LIMIT 1'
            );
            $passwordStatement->execute([$userId, $role]);
            $currentHash = $passwordStatement->fetchColumn();

            if (!is_string($currentHash) || !password_verify($currentPassword, $currentHash)) {
                $error = 'The current password is incorrect.';
            } elseif (!role_account_settings_password_is_strong($newPassword)) {
                $error = 'Use 7–72 characters with uppercase, lowercase, a number, and a symbol.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'The new password and confirmation do not match.';
            } elseif (password_verify($newPassword, $currentHash)) {
                $error = 'Choose a password different from your current password.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        'UPDATE Users SET password_hash = ? WHERE user_id = ? AND `role` = ? AND status = \'active\''
                    )->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId, $role]);
                    role_log($pdo, $userId, $role . '.password.updated', 'user', $userId, 'Account password updated');
                    $pdo->commit();
                    session_regenerate_id(true);
                    $_SESSION[$flashKey] = 'Password changed successfully.';
                    header('Location: settings.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'The password could not be changed.';
                    error_log('Role account password update: ' . $exception->getMessage());
                }
            }
        } else {
            $error = 'Unsupported settings request.';
        }
    }

    $statusClass = role_status_class((string) $profile['status']);
    $created = strtotime((string) $profile['created_at']);
    $roleName = (string) $profile['account_role'];

    role_render(
        $role,
        ['settings'],
        'Account Settings',
        'Manage your profile and account security',
        function (array $context) use ($profile, $form, $error, $flash, $statusClass, $created, $roleName): void {
            ?>
            <style>
              .role-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:stretch;gap:18px}
              .role-settings-panel{min-width:0;overflow:hidden}
              .role-settings-header{min-height:48px;padding:12px 16px;display:flex;align-items:center;background:#003e5d;color:#fff}
              .role-settings-header h2{margin:0;color:#fff;font-size:1rem;line-height:1.3}
              .role-settings-body{padding:18px}
              .role-settings-note{margin:0 0 16px;padding:12px 13px;border-left:3px solid #65b2e5;border-radius:4px;background:#f2f7fb;color:#345a78;font-size:.8rem;line-height:1.45}
              .role-settings-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 14px}
              .role-settings-form label{min-width:0;display:grid;align-content:start;gap:7px;color:var(--ink);font-size:.82rem;font-weight:700}
              .role-settings-form .wide{grid-column:1/-1}
              .role-settings-form input:not([type=hidden]){width:100%;min-width:0;height:44px;padding:0 12px;border:1px solid #b9d1e4;border-radius:9px;background:#fff;color:var(--ink);font:inherit;font-weight:600}
              .role-settings-form input:not([type=hidden]):focus{border-color:var(--blue-500);outline:3px solid rgba(2,132,199,.14)}
              .role-settings-form input[readonly]{background:#f6f9fc}
              .role-settings-form .hint{color:var(--muted);font-size:.76rem;font-weight:400;line-height:1.4}
              .role-settings-facts{margin:18px 0 0;border-top:1px solid var(--line)}
              .role-settings-facts>div{min-height:44px;padding:10px 0;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line)}
              .role-settings-facts dt{color:var(--muted);font-size:.8rem}
              .role-settings-facts dd{margin:0;color:var(--blue-900);font-size:.8rem;font-weight:700;text-align:right}
              .role-settings-facts .status{padding:5px 11px}
              .role-settings-actions{grid-column:1/-1;margin-top:2px}
              .role-settings-password-current{grid-column:1/-1}
              .role-settings-password-field{position:relative;display:block}
              .role-settings-password-field input{padding-right:44px}
              .role-settings-password-toggle{position:absolute;top:50%;right:9px;width:28px;height:28px;padding:4px;transform:translateY(-50%);border:0;background:transparent;color:#637d99;cursor:pointer}
              .role-settings-password-toggle svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
              .role-settings-password-toggle:focus-visible{outline:2px solid var(--blue-500);outline-offset:2px}
              .role-settings-password-toggle .eye-on{display:none}
              .role-settings-password-toggle[aria-pressed=true] .eye-off{display:none}
              .role-settings-password-toggle[aria-pressed=true] .eye-on{display:block}
              .role-settings-strength{height:6px;display:block;overflow:hidden;border-radius:99px;background:#e6eef5}
              .role-settings-strength span{width:0;height:100%;display:block;border-radius:inherit;background:#d25a5a;transition:width .18s ease,background .18s ease}
              .role-settings-hint{min-height:1em;color:var(--muted);font-size:.75rem;font-weight:400;line-height:1.4}
              @media(max-width:1050px){.role-settings-grid{grid-template-columns:minmax(0,1fr)}}
              @media(max-width:560px){.role-settings-body{padding:14px}.role-settings-form{grid-template-columns:minmax(0,1fr)}.role-settings-form .wide,.role-settings-actions,.role-settings-password-current{grid-column:1}.role-settings-facts>div{align-items:flex-start}}
            </style>

            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($flash !== ''): ?><div class="notice success" role="status"><?= e($flash) ?></div><?php endif; ?>

            <div class="role-settings-grid">
              <section class="panel role-settings-panel">
                <header class="role-settings-header"><h2>Profile Information</h2></header>
                <div class="role-settings-body">
                  <p class="role-settings-note">Your display name and email are used across the clinic system.</p>
                  <form method="post" class="role-settings-form" autocomplete="on">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <label>Full name
                      <input type="text" name="full_name" value="<?= e($form['full_name']) ?>" required maxlength="100" autocomplete="name">
                    </label>
                    <label>Email address
                      <input type="email" name="email" value="<?= e($form['email']) ?>" required maxlength="254" autocomplete="email">
                    </label>
                    <div class="role-settings-actions"><button class="button" type="submit">Save profile</button></div>
                  </form>
                  <dl class="role-settings-facts">
                    <div><dt>Role</dt><dd><?= e($roleName) ?></dd></div>
                    <div><dt>Account status</dt><dd><span class="status <?= e($statusClass) ?>"><?= e(ucfirst((string) $profile['status'])) ?></span></dd></div>
                    <div><dt>Created</dt><dd><?= $created === false ? '—' : e(date('M j, Y', $created)) ?></dd></div>
                  </dl>
                </div>
              </section>

              <section class="panel role-settings-panel">
                <header class="role-settings-header"><h2>Change Password</h2></header>
                <div class="role-settings-body">
                  <p class="role-settings-note">For security, enter your current password. Use 7–72 characters with uppercase, lowercase, a number, and a symbol.</p>
                  <form method="post" class="role-settings-form" id="role-settings-password-form" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <label class="role-settings-password-current">Current password
                      <span class="role-settings-password-field">
                        <input type="password" name="current_password" required autocomplete="current-password">
                        <button class="role-settings-password-toggle" type="button" aria-label="Show password" aria-pressed="false">
                          <svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                          <svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 5.2A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.5 7 10 7c1.4 0 2.7-.3 3.8-.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                      </span>
                    </label>
                    <label>New password
                      <span class="role-settings-password-field">
                        <input type="password" name="new_password" id="role-settings-new-password" required minlength="7" maxlength="72" autocomplete="new-password">
                        <button class="role-settings-password-toggle" type="button" aria-label="Show password" aria-pressed="false">
                          <svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                          <svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 5.2A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.5 7 10 7c1.4 0 2.7-.3 3.8-.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                      </span>
                      <span class="role-settings-strength" aria-hidden="true"><span id="role-settings-strength-bar"></span></span>
                      <span class="role-settings-hint" id="role-settings-strength-hint" aria-live="polite">Use 7+ characters with uppercase, lowercase, a number, and a symbol.</span>
                    </label>
                    <label>Confirm new password
                      <span class="role-settings-password-field">
                        <input type="password" name="confirm_password" id="role-settings-confirm-password" required minlength="7" maxlength="72" autocomplete="new-password">
                        <button class="role-settings-password-toggle" type="button" aria-label="Show password" aria-pressed="false">
                          <svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                          <svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 5.2A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.5 7 10 7c1.4 0 2.7-.3 3.8-.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                      </span>
                      <span class="role-settings-hint" id="role-settings-match-hint" aria-live="polite"></span>
                    </label>
                    <div class="role-settings-actions"><button class="button" type="submit">Update password</button></div>
                  </form>
                </div>
              </section>
            </div>

            <script>
              (function () {
                document.querySelectorAll('.role-settings-password-toggle').forEach(function (button) {
                  const input = button.parentElement.querySelector('input');
                  button.addEventListener('mousedown', function (event) { event.preventDefault(); });
                  button.addEventListener('click', function () {
                    const showing = input.type === 'password';
                    input.type = showing ? 'text' : 'password';
                    button.setAttribute('aria-pressed', String(showing));
                    button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
                    input.focus({ preventScroll: true });
                  });
                });

                const password = document.getElementById('role-settings-new-password');
                const confirmation = document.getElementById('role-settings-confirm-password');
                const bar = document.getElementById('role-settings-strength-bar');
                const strengthHint = document.getElementById('role-settings-strength-hint');
                const matchHint = document.getElementById('role-settings-match-hint');
                const rules = [
                  { test: function (value) { return value.length >= 7 && value.length <= 72; }, label: '7–72 characters' },
                  { test: function (value) { return /[a-z]/.test(value); }, label: 'lowercase letter' },
                  { test: function (value) { return /[A-Z]/.test(value); }, label: 'uppercase letter' },
                  { test: function (value) { return /[0-9]/.test(value); }, label: 'number' },
                  { test: function (value) { return /[^A-Za-z0-9]/.test(value); }, label: 'symbol' }
                ];

                function updatePasswordHints() {
                  const value = password.value;
                  const score = rules.reduce(function (total, rule) { return total + (rule.test(value) ? 1 : 0); }, 0);
                  const colors = ['#d25a5a', '#d25a5a', '#e0a030', '#4b9b68', '#16845a', '#16845a'];
                  const labels = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong', 'Strong'];
                  bar.style.width = (score * 20) + '%';
                  bar.style.background = colors[score];
                  strengthHint.textContent = value ? labels[score] : 'Use 7+ characters with uppercase, lowercase, a number, and a symbol.';
                  if (confirmation.value === '') {
                    matchHint.textContent = '';
                  } else {
                    matchHint.textContent = value === confirmation.value ? 'Passwords match.' : 'Passwords do not match.';
                  }
                }

                password.addEventListener('input', updatePasswordHints);
                confirmation.addEventListener('input', updatePasswordHints);
              }());
            </script>
            <?php
        }
    );
}
