<?php
// Pet owners create their own account; the role is always 'client'.
// Only email + password are collected here. The full name is asked later,
// when the client lands on client_dashboard.php.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/audit_log.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (is_logged_in()) {
    header('Location: ' . home_for_role(current_role()));
    exit;
}

// Returns an error message if the password is weak, or '' if it is strong.
function password_problem(string $password): string {
    if (strlen($password) < 8) {
        return 'Weak password: it must be at least 8 characters.';
    }
    if (strlen($password) > 72) {
        return 'Password is too long (maximum 72 characters).';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Weak password: add at least one lowercase letter.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Weak password: add at least one uppercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Weak password: add at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Weak password: add at least one special character (e.g. ! @ # $ %).';
    }
    return '';
}

$error = '';
$passwordError = '';
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = normalize_email(posted_string('email'));
    $password = posted_string('password');
    $confirm  = posted_string('confirm');

    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!valid_email($email)) {
        $error = 'Enter a valid email address.';
    } elseif (($problem = password_problem($password)) !== '') {
        $error = $problem;
        $passwordError = $problem;
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $pdo = null;
        // The name is collected later on the client dashboard, so it starts empty.
        $fullName = '';
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetch()) {
                $pdo->rollBack();
                $error = 'An account with this email already exists.';
            } else {
                // FIX: the real table is "petowners" (it was "pet_owners").
                $owner = $pdo->prepare('INSERT INTO petowners (full_name, email) VALUES (?, ?)');
                $owner->execute([$fullName, $email]);
                $ownerId = (int) $pdo->lastInsertId();

                $ins = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, `role`, status, owner_id) VALUES (?, ?, ?, 'client', 'active', ?)");
                $ins->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $ownerId]);
                $userId = (int) $pdo->lastInsertId();
                audit_log_record($pdo, $userId, 'client.registered', 'owner', $ownerId, 'Pet owner account registered.');
                $recipients = array_merge(
                    notification_role_users($pdo, 'staff'),
                    notification_role_users($pdo, 'admin')
                );
                $notified = notification_send_users(
                    $pdo,
                    $recipients,
                    'account',
                    'New client registration',
                    'A new pet owner account has been registered.',
                    'staff/owners.php',
                    'client_registered_' . $userId
                );
                if ($notified === 0) {
                    error_log('Client registration created without an active staff/admin notification recipient.');
                }
                $pdo->commit();
                header('Location: login.php?msg=registered');
                exit;
            }
        } catch (PDOException $ex) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                $error = 'An account with this email already exists.';
            } else {
                // The real reason is written to the PHP error log (xampp/apache/logs/error.log).
                error_log('signup failed: ' . $ex->getMessage());
                $error = 'Database unavailable. Check the connection and required tables, then try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign up | Animal Clinic</title>
  <style>
    :root {
      --blue-50: #eaf3fa;
      --blue-100: #c2dcf1;
      --blue-300: #81b7e0;
      --blue-500: #0284c7;
      --blue-700: #015b8c;
      --blue-900: #002e4a;
      --ink: #000914;
    }

    * { box-sizing: border-box; }

    body {
      min-height: 100svh;
      margin: 0;
      padding: clamp(12px, 3vw, 28px);
      display: grid;
      place-items: center;
      color: var(--ink);
      background: linear-gradient(135deg, var(--blue-50), #f5f8fb 55%, var(--blue-100));
      font-family: Arial, Helvetica, sans-serif;
    }

    .welcome-card {
      width: min(100%, 900px);
      height: min(520px, calc(100svh - 56px));
      padding: 0;
      display: grid;
      grid-template-columns: 1fr 1fr;
      overflow: hidden;
      background: #fff;
      border: 1px solid rgba(0, 46, 74, .07);
      border-radius: 28px;
      box-shadow: 0 24px 70px rgba(0, 46, 74, .16);
    }

    .intro {
      position: relative;
      min-height: 0;
      padding: clamp(28px, 4vw, 46px);
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      overflow: hidden;
      color: #fff;
      background-color: var(--blue-900);
      background-image: url("assets/images/Abstract%20Blue%20Wave%20Gradient.png");
      background-position: center;
      background-size: cover;
      border-radius: 27px 0 0 27px;
    }

    .intro::before {
      position: absolute;
      width: 310px;
      height: 310px;
      right: -210px;
      top: -90px;
      border: 2px solid rgba(129, 220, 245, .3);
      border-radius: 50%;
      content: "";
      pointer-events: none;
    }

    .intro h1 {
      position: relative;
      z-index: 1;
      width: min(100%, 460px);
      margin: 0 auto 18px;
    }

    .intro h1 img { display: block; width: 100%; height: auto; }

    .intro p {
      position: relative;
      z-index: 1;
      max-width: 38ch;
      margin: 0 auto;
      color: var(--blue-50);
      font-size: clamp(.95rem, 1.5vw, 1.1rem);
      line-height: 1.55;
      text-align: center;
    }

    .pets {
      position: absolute;
      z-index: 1;
      width: 112%;
      max-width: none;
      height: 48%;
      left: -7%;
      bottom: 0;
      object-fit: contain;
      object-position: center bottom;
    }

    .actions {
      position: relative;
      padding: 30px clamp(22px, 3vw, 36px) 18px;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      text-align: center;
    }

    .back {
      position: absolute;
      top: 34px;
      left: 18px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--blue-500);
      font-size: 1rem;
      text-decoration: none;
    }

    .back:hover, .login-link:hover { color: var(--blue-900); }

    .brand {
      margin: 0 0 6px;
      display: flex;
      justify-content: center;
    }

    .brand img {
      width: 76px;
      height: 76px;
      object-fit: contain;
      border-radius: 50%;
    }

    .actions h2 {
      margin: 0 0 6px;
      color: var(--blue-900);
      font-size: clamp(1.9rem, 3.5vw, 2.35rem);
    }

    .subtitle {
      max-width: 34ch;
      margin: 0 auto 12px;
      color: #557184;
      line-height: 1.5;
    }

    .alert {
      margin: 0 0 10px;
      padding: 9px 11px;
      color: #991b1b;
      background: #fee2e2;
      border-radius: 10px;
      font-size: .85rem;
      line-height: 1.35;
      text-align: left;
    }

    .signup-form { display: grid; gap: 8px; }

    .field {
      position: relative;
      display: flex;
      align-items: center;
      color: #647d9f;
    }

    .field-icon {
      position: absolute;
      left: 16px;
      width: 20px;
      height: 20px;
      pointer-events: none;
    }

    .field input {
      width: 100%;
      min-height: 44px;
      padding: 10px 16px 10px 48px;
      color: var(--blue-900);
      background: #f4f7fb;
      border: 1px solid #d5e0f0;
      border-radius: 14px;
      font: inherit;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
    }

    .field input::placeholder { color: #8194b2; opacity: 1; }

    .field input:focus {
      border-color: var(--blue-500);
      box-shadow: 0 0 0 3px rgba(2, 132, 199, .14);
    }

    /* Floating weak-password popup */
    .pw-tip {
      position: absolute;
      top: calc(100% + 8px);
      left: 0;
      z-index: 5;
      max-width: min(100%, calc(100vw - 48px));
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      color: #b3261e;
      background: #fff;
      border: 1px solid #f3b4b0;
      border-radius: 10px;
      box-shadow: 0 8px 24px rgba(0, 46, 74, .18);
      font-size: .85rem;
      font-weight: 700;
      line-height: 1;
      white-space: normal;
      pointer-events: none;
      opacity: 0;
      visibility: hidden;
      transform: translateY(-4px);
      transition: opacity .2s ease, transform .2s ease, visibility 0s .2s;
    }

    .pw-tip.show {
      opacity: 1;
      visibility: visible;
      transform: translateY(0);
      transition-delay: 0s;
    }

    /* little arrow pointing up at the field */
    .pw-tip::before {
      position: absolute;
      top: -6px;
      left: 20px;
      width: 10px;
      height: 10px;
      content: "";
      background: #fff;
      border-top: 1px solid #f3b4b0;
      border-left: 1px solid #f3b4b0;
      transform: rotate(45deg);
    }

    .pw-tip svg { width: 16px; height: 16px; flex: none; }

    .field input.invalid {
      border-color: #d93025;
      box-shadow: 0 0 0 3px rgba(217, 48, 37, .12);
    }

    .button {
      min-height: 48px;
      padding: 12px 20px;
      display: block;
      color: #fff;
      background: var(--blue-500);
      border: 2px solid var(--blue-500);
      border-radius: 12px;
      font: inherit;
      font-weight: 700;
      text-align: center;
      cursor: pointer;
      transition: background .2s, border-color .2s, transform .2s;
    }

    .button:hover {
      background: var(--blue-700);
      border-color: var(--blue-700);
      transform: translateY(-1px);
    }

    .login-prompt {
      margin: 14px 0 0;
      color: var(--blue-900);
      line-height: 1.5;
    }

    .login-link {
      margin-left: 6px;
      color: var(--blue-500);
      font-weight: 600;
      text-decoration: none;
    }

    @media (max-width: 700px) {
      body { padding: 12px; }
      .welcome-card {
        min-height: 0;
        height: auto;
        width: min(100%, 480px);
        grid-template-columns: 1fr;
        border-radius: 22px;
      }
      .intro {
        min-height: 0;
        padding: clamp(22px, 7vw, 30px);
        padding-bottom: 0;
        display: grid;
        grid-template-rows: auto auto auto;
        align-content: start;
        gap: 12px;
        border-radius: 22px 22px 0 0;
      }
      .intro h1 { width: min(100%, 370px); margin: 0 auto; }
      .intro p {
        max-width: 34ch;
        margin: 0 auto;
        text-shadow: 0 1px 3px rgba(0, 9, 20, .45);
      }
      .pets {
        position: relative;
        display: block;
        width: 100%;
        height: clamp(150px, 44vw, 220px);
        margin: 2px 0 0;
        align-self: end;
        left: 0;
        bottom: auto;
        object-fit: contain;
        object-position: center bottom;
      }
      .actions { padding: 64px clamp(20px, 8vw, 42px) 32px; }
      .back { top: 20px; left: 20px; }
      .brand { margin: 0 0 8px; }
      .subtitle { margin-bottom: 18px; }
    }

    @media (max-width: 380px) {
      .actions { padding-right: 18px; padding-left: 18px; }
      .actions h2 { font-size: 1.9rem; }
      .button, .field input { min-height: 48px; }
      .button { padding: 13px 16px; }
    }

    @media (max-height: 650px) and (min-width: 701px) {
      body { padding-block: 12px; }
      .welcome-card { height: min(520px, calc(100svh - 24px)); }
      .intro { min-height: 0; padding-block: 22px; }
      .pets { height: 45%; }
      .actions { padding-top: 20px; }
      .brand img { width: 64px; height: 64px; }
      .brand { margin: 0 0 4px; }
      .subtitle { margin-bottom: 10px; }
      .login-prompt { margin-top: 10px; }
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
  </style>
</head>
<body>
  <main class="welcome-card">
    <section class="intro" aria-label="Animal Clinic introduction">
      <h1><img src="assets/images/Healthy%20Pets,%20Happier%20Lives.png" alt="Healthy pets, happier lives"></h1>
      <p>Book appointments, keep track of medical records and vaccinations, and manage bills in one place.</p>
      <img class="pets" src="assets/images/pet.png" alt="A happy dog and cat">
    </section>

    <section class="actions" aria-labelledby="signup-title">
      <a class="back" id="signupBack" href="index.php"><span aria-hidden="true">&larr;</span> Back</a>
      <div class="brand">
        <img src="assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic logo">
      </div>
      <h2 id="signup-title">Create account</h2>
      <p class="subtitle">Sign up to manage your pet’s care and appointments.</p>

      <?php if ($error && !$passwordError): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>

      <form class="signup-form" id="signupForm" method="post" action="signup.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="field">
          <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.1 0-7.5 2.1-7.5 4.7V21h15v-2.3c0-2.6-3.4-4.7-7.5-4.7Z"/>
          </svg>
          <input type="email" name="email" value="<?= e($email) ?>" placeholder="Email address" aria-label="Email address" required autofocus autocomplete="email" maxlength="254">
        </label>
        <label class="field">
          <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2ZM10 6a2 2 0 1 1 4 0v2h-4V6Zm3 9.7V18h-2v-2.3a2 2 0 1 1 2 0Z"/>
          </svg>
          <input type="password" id="password" name="password" placeholder="Password" aria-label="Password" required maxlength="72" autocomplete="new-password"<?= $passwordError ? ' class="invalid"' : '' ?>>
          <span class="pw-tip<?= $passwordError ? ' show' : '' ?>" id="pwTip" role="alert">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/>
            </svg>
            <span class="pw-tip-message"><?= e($passwordError ?: 'Weak password') ?></span>
          </span>
        </label>
        <label class="field">
          <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2ZM10 6a2 2 0 1 1 4 0v2h-4V6Zm3 9.7V18h-2v-2.3a2 2 0 1 1 2 0Z"/>
          </svg>
          <input type="password" name="confirm" placeholder="Confirm password" aria-label="Confirm password" required minlength="8" maxlength="72" autocomplete="new-password">
        </label>
        <button class="button" type="submit">Sign up</button>
      </form>

      <p class="login-prompt">Already have an account? <a class="login-link" href="login.php">Log in</a></p>
    </section>
  </main>

  <script>
    (function () {
      var form = document.getElementById('signupForm');
      var pw = document.getElementById('password');
      var confirm = form.querySelector('[name="confirm"]');
      var tip = document.getElementById('pwTip');
      var tipMessage = tip.querySelector('.pw-tip-message');
      var back = document.getElementById('signupBack');

      function isStrong(p) {
        return p.length >= 8 &&
               /[a-z]/.test(p) &&
               /[A-Z]/.test(p) &&
               /[0-9]/.test(p) &&
               /[^A-Za-z0-9]/.test(p);
      }

      function passwordMessage(p) {
        if (p.length < 8) return 'Weak password';
        if (p.length > 72) return 'Password is too long (maximum 72 characters).';
        if (!/[a-z]/.test(p)) return 'Weak password: add at least one lowercase letter.';
        if (!/[A-Z]/.test(p)) return 'Weak password: add at least one uppercase letter.';
        if (!/[0-9]/.test(p)) return 'Weak password: add at least one number.';
        if (!/[^A-Za-z0-9]/.test(p)) return 'Weak password: add at least one special character (e.g. ! @ # $ %).';
        return 'Weak password';
      }

      function update(showIfEmpty) {
        var weak = !isStrong(pw.value) && (pw.value !== '' || showIfEmpty);
        tipMessage.textContent = passwordMessage(pw.value);
        tip.classList.toggle('show', weak);
        pw.classList.toggle('invalid', weak);
      }

      function resetPasswords() {
        pw.value = '';
        confirm.value = '';
        tip.classList.remove('show');
        pw.classList.remove('invalid');
        tipMessage.textContent = 'Weak password';
      }

      pw.addEventListener('input', function () { update(false); });
      pw.addEventListener('focus', function () { update(false); });
      pw.addEventListener('blur', function () { tip.classList.remove('show'); });
      back.addEventListener('click', resetPasswords);
      window.addEventListener('pagehide', resetPasswords);
      window.addEventListener('pageshow', function (event) {
        var restoredFromHistory = event.persisted;
        window.setTimeout(function () {
          pw.value = '';
          confirm.value = '';
          if (restoredFromHistory) {
            resetPasswords();
          }
        }, 0);
      });

      form.addEventListener('submit', function (e) {
        if (!isStrong(pw.value)) {
          e.preventDefault();
          update(true);
          pw.focus();
        }
      });
    })();
  </script>
</body>
</html>