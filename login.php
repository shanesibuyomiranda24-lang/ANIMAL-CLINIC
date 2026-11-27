<?php
// One login page for every role.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (is_logged_in()) {
    require_login();
    $role = current_role();
    auth_migrate_session_context($role);
    header('Location: ' . home_for_role($role));
    exit;
}

$error = '';
$notice = (($_GET['msg'] ?? '') === 'logged_out') ? 'You have been logged out.'
        : ((($_GET['msg'] ?? '') === 'registered') ? 'Account created. You can log in now.'
        : ((($_GET['msg'] ?? '') === 'account_unavailable') ? 'This account is unavailable. Contact the clinic administrator.' : ''));
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = normalize_email(posted_string('email'));
    $password = posted_string('password');

    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!valid_email($email) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        try {
            $st = db()->prepare("SELECT user_id, full_name, email, password_hash, `role`, must_change_password FROM users WHERE email = ? AND status = 'active' AND archived_at IS NULL LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch();

            $role = $u ? auth_normalize_role($u['role'] ?? null) : null;
            $passwordMatches = $u && password_verify($password, $u['password_hash']);
            if (!$passwordMatches) {
                $error = 'Wrong email or password.';
            } elseif ($role === null) {
                $error = 'This account has an unsupported role. Contact the clinic administrator.';
            } else {
                unset($_SESSION['csrf']);
                session_write_close();
                auth_switch_session_context($role);
                session_regenerate_id(true);
                auth_expire_legacy_session_cookie();
                $_SESSION = [];
                $_SESSION['user_id']  = $u['user_id'];
                $_SESSION['full_name'] = $u['full_name'];
                $_SESSION['email']    = $u['email'];
                $_SESSION['role']     = $role;
                $_SESSION['must_change_password'] = (int) $u['must_change_password'] === 1;
                header('Location: ' . ($_SESSION['must_change_password']
                    ? 'change_password.php?session_context=' . rawurlencode($role)
                    : home_for_role($role)));
                exit;
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $error = 'Database unavailable. Check the connection and required user columns, then try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log in | Animal Clinic</title>
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

    .back:hover, .forgot-link:hover, .signup-link:hover { color: var(--blue-900); }

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
      border-radius: 10px;
      font-size: .85rem;
      line-height: 1.35;
      text-align: left;
    }

    .alert.ok { color: #14532d; background: #dcfce7; }
    .alert.error { color: #991b1b; background: #fee2e2; }

    .login-form { display: grid; gap: 8px; }

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

    /* Show/hide password eye button */
    .field.password-field input { padding-right: 50px; }
    .field.password-field input::-ms-reveal,
    .field.password-field input::-ms-clear { display: none; }

    .toggle-password {
      position: absolute;
      right: 10px;
      width: 34px;
      height: 34px;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #647d9f;
      background: transparent;
      border: 0;
      border-radius: 50%;
      cursor: pointer;
      transition: color .2s, background .2s;
    }

    .toggle-password:hover { color: var(--blue-900); background: rgba(2, 132, 199, .08); }
    .toggle-password:focus-visible { outline: 2px solid var(--blue-500); outline-offset: 1px; }
    .toggle-password svg { width: 22px; height: 22px; }

    /* Password hidden  -> eye with a slash. Password shown -> plain eye. */
    .toggle-password .eye-open { display: none; }
    .toggle-password .eye-closed { display: block; }
    .toggle-password[aria-pressed="true"] .eye-open { display: block; }
    .toggle-password[aria-pressed="true"] .eye-closed { display: none; }

    .forgot-row { margin-top: 0; text-align: right; }

    .forgot-link, .signup-link {
      color: var(--blue-500);
      font-weight: 600;
      text-decoration: none;
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
      text-decoration: none;
      cursor: pointer;
      transition: background .2s, border-color .2s, transform .2s;
    }

    .button:hover {
      background: var(--blue-700);
      border-color: var(--blue-700);
      transform: translateY(-1px);
    }

    .signup-prompt {
      margin: 14px 0 0;
      color: var(--blue-900);
      line-height: 1.5;
    }

    .signup-link { margin-left: 6px; }

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
      .signup-prompt { margin-top: 10px; }
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

    <section class="actions" aria-labelledby="login-title">
      <a class="back" href="index.php"><span aria-hidden="true">&larr;</span> Back</a>
      <div class="brand">
        <img src="assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic logo">
      </div>
      <h2 id="login-title">Welcome</h2>
      <p class="subtitle">Log in to manage your pet’s care, view records and book appointments.</p>

      <?php if ($notice): ?><div class="alert ok" role="status"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

      <form class="login-form" method="post" action="login.php" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="field">
          <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.1 0-7.5 2.1-7.5 4.7V21h15v-2.3c0-2.6-3.4-4.7-7.5-4.7Z"/>
          </svg>
          <input type="email" name="email" value="<?= e($email) ?>" placeholder="Email address" aria-label="Email address" required autofocus autocomplete="email" maxlength="254">
        </label>
        <label class="field password-field">
          <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2ZM10 6a2 2 0 1 1 4 0v2h-4V6Zm3 9.7V18h-2v-2.3a2 2 0 1 1 2 0Z"/>
          </svg>
          <input type="password" name="password" placeholder="Password" aria-label="Password" required autocomplete="current-password">
          <button class="toggle-password" type="button" aria-label="Show password" aria-pressed="false">
            <!-- Plain eye: shown while the password is visible -->
            <svg class="eye-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5ZM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5Zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3Z"/>
            </svg>
            <!-- Eye with a slash: shown while the password is hidden -->
            <svg class="eye-closed" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7ZM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27ZM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2Zm4.31-.78 3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01Z"/>
            </svg>
          </button>
        </label>
        <div class="forgot-row">
          <a class="forgot-link" href="forgot_password.php">Forgot Password?</a>
        </div>
        <button class="button" type="submit">Log in</button>
      </form>

      <p class="signup-prompt">Don't have an account yet? <a class="signup-link" href="signup.php">Sign up</a></p>
    </section>
  </main>
  <script>
    (function () {
      var password = document.querySelector('input[name="password"]');
      var toggle = document.querySelector('.toggle-password');

      function clearPassword() {
        password.value = '';
      }

      function setVisible(visible) {
        password.type = visible ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', String(visible));
        toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
      }

      // Keep the button from stealing focus so the caret stays in the field.
      toggle.addEventListener('mousedown', function (event) { event.preventDefault(); });
      toggle.addEventListener('click', function () {
        var start = password.selectionStart;
        var end = password.selectionEnd;
        setVisible(password.type === 'password');
        password.focus({ preventScroll: true });
        try { password.setSelectionRange(start, end); } catch (e) {}
      });

      window.addEventListener('pagehide', function () { clearPassword(); setVisible(false); });
      window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
          setVisible(false);
          window.setTimeout(clearPassword, 0);
        }
      });
      document.querySelector('.back').addEventListener('click', clearPassword);
    })();
  </script>
</body>
</html>