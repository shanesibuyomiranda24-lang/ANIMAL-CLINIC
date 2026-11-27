<?php
// Public welcome page shared by pet owners, clinic staff, and vet.
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: ' . home_for_role(current_role()));
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Welcome | Animal Clinic</title>
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
      gap: 0;
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

    .intro h1 img {
      display: block;
      width: 100%;
      height: auto;
    }

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

    .brand img {
      width: clamp(78px, 10vw, 112px);
      height: clamp(78px, 10vw, 112px);
      object-fit: contain;
      border-radius: 50%;
    }

    .actions {
      padding: 18px clamp(22px, 3vw, 36px);
      display: flex;
      flex-direction: column;
      justify-content: center;
      text-align: center;
    }

    .panel-stage {
      display: grid;
      width: 100%;
    }

    .panel-view {
      grid-area: 1 / 1;
      align-self: center;
      width: 100%;
      opacity: 1;
      transform: translateY(0);
      transition: opacity .32s ease, transform .32s cubic-bezier(.2, .75, .25, 1), visibility 0s;
      visibility: visible;
    }

    .panel-view[aria-hidden="true"] {
      opacity: 0;
      pointer-events: none;
      visibility: hidden;
      transition-delay: 0s, 0s, .32s;
    }

    /* Hidden panels wait just off-position: welcome below, login/signup above. */
    .welcome-view[aria-hidden="true"] { transform: translateY(18px); }
    .login-view[aria-hidden="true"],
    .signup-view[aria-hidden="true"] { transform: translateY(-18px); }

    .brand {
      margin: 0 0 14px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      color: var(--blue-700);
      font-size: .95rem;
      font-weight: 700;
    }

    .actions h2 {
      margin: 0 0 6px;
      color: var(--blue-900);
      font-size: clamp(2rem, 4vw, 2.6rem);
    }

    .subtitle {
      max-width: 34ch;
      margin: 0 auto 24px;
      color: #557184;
      line-height: 1.5;
    }

    .button {
      min-height: 54px;
      padding: 15px 20px;
      display: block;
      color: #fff;
      background: var(--blue-500);
      border: 2px solid var(--blue-500);
      border-radius: 12px;
      font-weight: 700;
      text-decoration: none;
      transition: background .2s, border-color .2s, transform .2s;
    }

    .button:hover {
      background: var(--blue-700);
      border-color: var(--blue-700);
      transform: translateY(-1px);
    }

    .button-outline {
      color: var(--blue-700);
      background: #fff;
      border-color: var(--blue-100);
    }

    .button-outline:hover {
      color: #fff;
      background: var(--blue-700);
      border-color: var(--blue-700);
    }

    .separator {
      margin: 24px 0 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      color: #718797;
      font-size: .9rem;
    }

    .separator::before,
    .separator::after {
      height: 1px;
      flex: 1;
      content: "";
      background: var(--blue-100);
    }

    .note {
      margin: 24px 0 0;
      color: #718797;
      font-size: .88rem;
      line-height: 1.5;
    }

    .back {
      position: absolute;
      top: 8px;
      left: -18px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--blue-500);
      font-size: 1rem;
      text-decoration: none;
    }

    .back:hover, .forgot-link:hover, .signup-link:hover, .login-link:hover { color: var(--blue-900); }

    .login-view .brand { margin: 8px 0 6px; }
    .login-view .brand img { width: clamp(72px, 8vw, 86px); height: clamp(72px, 8vw, 86px); }
    .login-view .actions-title { margin: 0 0 6px; color: var(--blue-900); font-size: clamp(2rem, 4vw, 2.6rem); }
    .login-view .subtitle { margin-bottom: 14px; }
    .login-view { position: relative; padding-top: 28px; }

    /* Sign-up panel (matches signup.php) */
    .signup-view { position: relative; }
    .signup-view .back { top: 26px; }
    .signup-view .brand { margin: 2px 0 4px; }
    .signup-view .brand img { width: 72px; height: 72px; }
    .signup-view h2 { font-size: clamp(1.9rem, 3.5vw, 2.35rem); }
    .signup-view .subtitle { margin-bottom: 8px; }

    .alert {
      margin: 0 0 14px;
      padding: 10px 12px;
      border-radius: 10px;
      font-size: .9rem;
      line-height: 1.4;
      text-align: left;
    }

    .alert.ok { color: #14532d; background: #dcfce7; }
    .alert.error { color: #991b1b; background: #fee2e2; }

    .login-form { display: grid; gap: 9px; }
    .signup-form { display: grid; gap: 7px; }
    .signup-form .alert { margin: 0; padding: 9px 11px; font-size: .85rem; line-height: 1.35; }
    .signup-form .pw-warning {
      position: absolute;
      top: calc(100% + 8px);
      left: 0;
      z-index: 5;
      max-width: min(100%, calc(100vw - 48px));
      padding: 8px 12px;
      color: #b3261e;
      background: #fff;
      border: 1px solid #f3b4b0;
      border-radius: 10px;
      box-shadow: 0 8px 24px rgba(0, 46, 74, .18);
      font-size: .85rem;
      font-weight: 700;
      line-height: 1.3;
      text-align: left;
      pointer-events: none;
    }
    .signup-form .pw-warning[hidden] { display: none; }

    .field { position: relative; display: flex; align-items: center; color: #647d9f; }

    .field-icon {
      position: absolute;
      left: 16px;
      width: 20px;
      height: 20px;
      pointer-events: none;
    }

    .field input {
      width: 100%;
      min-height: 48px;
      padding: 11px 16px 11px 48px;
      color: var(--blue-900);
      background: #f4f7fb;
      border: 1px solid #d5e0f0;
      border-radius: 14px;
      font: inherit;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
    }

    .signup-form .field input { min-height: 42px; padding: 8px 16px 8px 48px; }

    .field input::placeholder { color: #8194b2; opacity: 1; }

    .field input:focus {
      border-color: var(--blue-500);
      box-shadow: 0 0 0 3px rgba(2, 132, 199, .14);
    }

    .forgot-row { text-align: right; }
    .forgot-link, .signup-link { color: var(--blue-500); font-weight: 600; text-decoration: none; }

    .forgot-help {
      display: none;
      margin: 8px 0 0;
      color: #557184;
      font-size: .83rem;
      line-height: 1.4;
      text-align: right;
    }

    .forgot-help:target { display: block; }

    .login-button {
      width: 100%;
      font: inherit;
      cursor: pointer;
    }

    .signup-button {
      min-height: 46px;
      padding: 11px 20px;
      width: 100%;
      font: inherit;
      cursor: pointer;
    }

    .signup-prompt { margin: 12px 0 0; color: var(--blue-900); line-height: 1.5; }
    .signup-link { margin-left: 6px; }

    .login-prompt { margin: 8px 0 0; color: var(--blue-900); line-height: 1.5; }
    .login-link { margin-left: 6px; color: var(--blue-500); font-weight: 600; text-decoration: none; }

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
      .intro h1 {
        width: min(100%, 370px);
        margin: 0 auto;
      }
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
      .actions { padding: 64px clamp(20px, 8vw, 42px) 30px; }
      .brand { margin-bottom: 12px; }
      .subtitle { margin-bottom: 20px; }
      .login-view { padding-top: 0; }
      .back { top: -44px; left: -8px; }
      .signup-view .back { top: -44px; }
    }

    @media (max-width: 380px) {
      .actions h2, .login-view .actions-title { font-size: 1.9rem; }
      .button, .field input { min-height: 48px; }
      .button { padding: 13px 16px; }
    }

    @media (max-height: 650px) and (min-width: 701px) {
      body { padding-block: 12px; }
      .welcome-card { height: min(520px, calc(100svh - 24px)); }
      .intro { min-height: 0; padding-block: 22px; }
      .pets { height: 45%; }
      .brand img { width: 76px; height: 76px; }
      .brand { margin-bottom: 10px; }
      .subtitle { margin-bottom: 18px; }
      .note { margin-top: 14px; }
      .login-view .brand { margin: 4px 0; }
      .login-view .subtitle { margin-bottom: 10px; }
      .signup-view .brand { margin: 2px 0 4px; }
      .signup-view .subtitle { margin-bottom: 8px; }
      .login-prompt { margin-top: 10px; }
    }

    @media (prefers-reduced-motion: reduce) {
      .panel-view { transition: none; }
    }
  </style>
</head>
<body>
  <main class="welcome-card">
    <section class="intro">
      <h1><img src="assets/images/Healthy%20Pets,%20Happier%20Lives.png" alt="Healthy pets, happier lives"></h1>
      <p>Book appointments, keep track of medical records and vaccinations, and manage bills in one place.</p>
      <img class="pets" src="assets/images/pet.png" alt="A happy dog and cat">
    </section>

    <section class="actions">
      <div class="panel-stage" id="panel-stage">

        <!-- Welcome panel -->
        <div class="panel-view welcome-view" data-panel="welcome" aria-labelledby="welcome-title">
          <div class="brand">
            <img src="assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic logo">
          </div>
          <h2 id="welcome-title">Welcome</h2>
          <p class="subtitle">Log in to manage your pet’s care, view records and book appointments.</p>
          <a class="button" href="login.php" data-show-login>Log in</a>
          <div class="separator">New to Animal Clinic?</div>
          <a class="button button-outline" href="signup.php" data-show-signup>Create an account</a>
        </div>

        <!-- Login panel -->
        <div class="panel-view login-view" data-panel="login" aria-labelledby="login-title" aria-hidden="true" inert>
          <a class="back" href="index.php" data-show-welcome><span aria-hidden="true">&larr;</span> Back</a>
          <div class="brand">
            <img src="assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic logo">
          </div>
          <h2 class="actions-title" id="login-title">Welcome</h2>
          <p class="subtitle">Log in to manage your pet’s care, view records and book appointments.</p>
          <form class="login-form" method="post" action="login.php" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label class="field">
              <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.1 0-7.5 2.1-7.5 4.7V21h15v-2.3c0-2.6-3.4-4.7-7.5-4.7Z"/>
              </svg>
              <input type="email" name="email" placeholder="Email address" aria-label="Email address" required autocomplete="email" maxlength="254">
            </label>
            <label class="field">
              <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2ZM10 6a2 2 0 1 1 4 0v2h-4V6Zm3 9.7V18h-2v-2.3a2 2 0 1 1 2 0Z"/>
              </svg>
              <input type="password" name="password" placeholder="Password" aria-label="Password" required autocomplete="current-password">
            </label>
            <div class="forgot-row">
              <a class="forgot-link" href="forgot_password.php">Forgot Password?</a>
            </div>
            <button class="button login-button" type="submit">Log in</button>
          </form>
          <p class="signup-prompt">Don't have an account yet? <a class="signup-link" href="signup.php" data-show-signup>Sign up</a></p>
        </div>

        <!-- Sign-up panel -->
        <div class="panel-view signup-view" data-panel="signup" aria-labelledby="signup-title" aria-hidden="true" inert>
          <a class="back" href="index.php" data-show-welcome><span aria-hidden="true">&larr;</span> Back</a>
          <div class="brand">
            <img src="assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic logo">
          </div>
          <h2 id="signup-title">Create account</h2>
          <p class="subtitle">Sign up to manage your pet’s care and appointments.</p>
          <form class="signup-form" id="signupForm" method="post" action="signup.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label class="field">
              <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.1 0-7.5 2.1-7.5 4.7V21h15v-2.3c0-2.6-3.4-4.7-7.5-4.7Z"/>
              </svg>
              <input type="text" name="full_name" placeholder="Full name" aria-label="Full name" required autocomplete="name" maxlength="150">
            </label>
            <label class="field">
              <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.1 0-7.5 2.1-7.5 4.7V21h15v-2.3c0-2.6-3.4-4.7-7.5-4.7Z"/>
              </svg>
              <input type="email" name="email" placeholder="Email address" aria-label="Email address" required autocomplete="email" maxlength="254">
            </label>
            <label class="field">
              <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2ZM10 6a2 2 0 1 1 4 0v2h-4V6Zm3 9.7V18h-2v-2.3a2 2 0 1 1 2 0Z"/>
              </svg>
              <input type="password" id="signupPassword" name="password" placeholder="Password" aria-label="Password" required minlength="7" maxlength="14" autocomplete="new-password">
              <div class="pw-warning" id="pwWarning" role="alert" hidden></div>
            </label>

            <label class="field">
              <svg class="field-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2ZM10 6a2 2 0 1 1 4 0v2h-4V6Zm3 9.7V18h-2v-2.3a2 2 0 1 1 2 0Z"/>
              </svg>
              <input type="password" name="confirm" placeholder="Confirm password" aria-label="Confirm password" required minlength="7" maxlength="14" autocomplete="new-password">
            </label>
            <button class="button signup-button" type="submit">Sign up</button>
          </form>
          <p class="login-prompt">Already have an account? <a class="login-link" href="login.php" data-show-login>Log in</a></p>
        </div>

      </div>
    </section>
  </main>

  <script>
    const panelStage = document.getElementById('panel-stage');
    const panels = {
      welcome: panelStage.querySelector('[data-panel="welcome"]'),
      login:   panelStage.querySelector('[data-panel="login"]'),
      signup:  panelStage.querySelector('[data-panel="signup"]')
    };

    function currentPanel() {
      return (history.state && history.state.panel) || 'welcome';
    }

    function showPanel(view) {
      [panels.login, panels.signup].forEach((panel) => {
        panel.querySelectorAll('input[type="password"]').forEach((input) => { input.value = ''; });
      });
      const warning = document.getElementById('pwWarning');
      warning.textContent = '';
      warning.hidden = true;

      Object.keys(panels).forEach((name) => {
        const active = name === view;
        panels[name].setAttribute('aria-hidden', String(!active));
        panels[name].inert = !active;
      });

      if (view === 'welcome') {
        panels.welcome.querySelector('[data-show-login]').focus({ preventScroll: true });
      } else {
        const field = view === 'signup'
          ? panels.signup.querySelector('input[name="full_name"]')
          : panels.login.querySelector('input[name="email"]');
        field.focus({ preventScroll: true });
      }
    }

    document.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-show-login], [data-show-signup]');
      const backTrigger = event.target.closest('[data-show-welcome]');

      if (trigger) {
        event.preventDefault();
        const target = trigger.hasAttribute('data-show-signup') ? 'signup' : 'login';
        const current = currentPanel();
        if (current === target) return;

        // From welcome, add a history entry; between login and signup, replace it
        // so "Back" always returns to the welcome panel.
        if (current === 'welcome') {
          history.pushState({ panel: target }, '', trigger.href);
        } else {
          history.replaceState({ panel: target }, '', trigger.href);
        }
        showPanel(target);
      } else if (backTrigger) {
        event.preventDefault();
        if (currentPanel() !== 'welcome') {
          history.back();
        } else {
          history.replaceState({ panel: 'welcome' }, '', backTrigger.href);
          showPanel('welcome');
        }
      }
    });

    history.replaceState({ panel: 'welcome' }, '', window.location.href);
    window.addEventListener('popstate', (event) => {
      const panel = event.state && event.state.panel;
      showPanel(panel === 'login' || panel === 'signup' ? panel : 'welcome');
    });

    // Weak password warning for the sign-up panel
    (function () {
      const form = document.getElementById('signupForm');
      const pw = document.getElementById('signupPassword');
      const warn = document.getElementById('pwWarning');

      function problem(p) {
        if (p.length < 7) return 'Password must be at least 7 characters.';
        if (p.length > 14) return 'Password must not exceed 14 characters.';
        if (!/[a-z]/.test(p)) return 'Password must include a lowercase letter.';
        if (!/[A-Z]/.test(p)) return 'Password must include an uppercase letter.';
        if (!/[0-9]/.test(p)) return 'Password must include a number.';
        if (!/[^A-Za-z0-9]/.test(p)) return 'Password must include a special character.';
        return '';
      }

      pw.addEventListener('input', function () {
        const msg = pw.value ? problem(pw.value) : '';
        warn.textContent = msg;
        warn.hidden = !msg;
      });

      form.addEventListener('submit', function (e) {
        const msg = problem(pw.value);
        if (msg) {
          e.preventDefault();
          warn.textContent = msg;
          warn.hidden = false;
          pw.focus();
        }
      });
    })();
  </script>
</body>
</html>