<?php
// includes/auth.php - sessions, login checks, roles, CSRF

function auth_session_roles(): array
{
    return ['admin', 'staff', 'vet', 'client'];
}

function auth_normalize_role($role): ?string
{
    if (!is_string($role)) {
        return null;
    }

    $role = strtolower(trim($role));
    return in_array($role, auth_session_roles(), true) ? $role : null;
}

function auth_session_context(): string
{
    // Role routes choose their own cookie; shared endpoints pass the context explicitly.
    $scriptDirectory = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptDirectory === 'groomer') {
        return 'staff';
    }
    if (in_array($scriptDirectory, auth_session_roles(), true)) {
        return $scriptDirectory;
    }

    $requestedContext = $_GET['session_context'] ?? $_POST['session_context'] ?? '';
    return is_string($requestedContext) && in_array($requestedContext, auth_session_roles(), true)
        ? $requestedContext
        : 'public';
}

function auth_session_name(string $context): string
{
    return 'ANIMALCLINIC_' . strtoupper($context);
}

function auth_start_session(string $context, bool $newSession = false, ?string $sessionId = null): void
{
    if (!in_array($context, array_merge(['public'], auth_session_roles()), true)) {
        throw new InvalidArgumentException('Invalid session context.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Per-role cookies let separate role tabs coexist in one browser profile.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(auth_session_name($context));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!$newSession && session_status() === PHP_SESSION_NONE) {
        $cookieSessionId = $_COOKIE[session_name()] ?? null;
        if (!is_string($cookieSessionId) && $context === 'public') {
            $legacySessionId = $_COOKIE['PHPSESSID'] ?? null;
            if (is_string($legacySessionId) && preg_match('/\A[a-zA-Z0-9,-]{1,128}\z/', $legacySessionId)) {
                session_id($legacySessionId);
                $GLOBALS['auth_legacy_cookie_used'] = true;
            }
        }
    }

    if ($newSession && session_status() === PHP_SESSION_NONE) {
        session_id($sessionId ?? '');
    }
    session_start();
}

if (session_status() === PHP_SESSION_NONE) {
    auth_start_session(auth_session_context());
}

function auth_switch_session_context(string $context): void
{
    $cookieName = auth_session_name($context);
    $sessionId = $_COOKIE[$cookieName] ?? null;
    if (!is_string($sessionId) || !preg_match('/\A[a-zA-Z0-9,-]{1,128}\z/', $sessionId)) {
        $sessionId = '';
    }
    auth_start_session($context, true, $sessionId);
}

function auth_expire_legacy_session_cookie(): void
{
    if (empty($GLOBALS['auth_legacy_cookie_used'])) {
        return;
    }

    setcookie('PHPSESSID', '', [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['PHPSESSID']);
    $GLOBALS['auth_legacy_cookie_used'] = false;
}

function auth_migrate_session_context(string $context): void
{
    $sessionData = $_SESSION;
    unset($sessionData['csrf']);
    session_write_close();
    auth_switch_session_context($context);
    $_SESSION = $sessionData;
    session_regenerate_id(true);
    auth_expire_legacy_session_cookie();
}

/** Escape text for safe output in HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function posted_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function normalize_email($value): string
{
    return is_string($value) ? strtolower(trim($value)) : '';
}

function valid_email(string $email): bool
{
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_full_name(string $name): bool
{
    $name = trim($name);
    $length = preg_match_all('/./us', $name);
    return $name !== ''
        && $length !== false
        && $length <= 150
        && preg_match('/[\p{C}]/u', $name) !== 1;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

/** Where each role lands after logging in. */
function home_for_role(?string $role): string
{
    switch ($role) {
        case 'admin':  return 'admin/dashboard.php';
        case 'staff':  return 'staff/staff_dashboard.php';
        case 'vet':    return 'vet/vet_dashboard.php';
        case 'client': return 'client/client_dashboard.php';
        default:       return 'login.php';
    }
}

function app_path(string $path): string
{
    $directory = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $prefix = in_array($directory, ['admin', 'staff', 'vet', 'client', 'groomer'], true) ? '../' : '';
    return $prefix . ltrim($path, '/');
}

function destroy_auth_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/** Put at the top of any page that needs a logged-in user. */
function require_login(bool $allowForcedPasswordChange = false): void
{
    if (!is_logged_in()) {
        header('Location: ' . app_path('login.php'));
        exit;
    }

    require_once __DIR__ . '/database.php';
    try {
        $st = db()->prepare('SELECT full_name, email, `role`, status, must_change_password, archived_at FROM users WHERE user_id = ? LIMIT 1');
        $st->execute([$_SESSION['user_id']]);
        $user = $st->fetch();
    } catch (PDOException $exception) {
        error_log('Authentication database check failed: ' . $exception->getMessage());
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><title>Temporarily unavailable</title><body><h1>Service temporarily unavailable</h1><p>We cannot verify your account right now. Please try again shortly.</p></body></html>');
    }

    $role = $user ? auth_normalize_role($user['role'] ?? null) : null;
    if (!$user || $user['status'] !== 'active' || $user['archived_at'] !== null || $role === null) {
        destroy_auth_session();
        header('Location: ' . app_path('login.php?msg=account_unavailable'));
        exit;
    }

    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $role;
    $_SESSION['must_change_password'] = (int) $user['must_change_password'] === 1;

    if (!$allowForcedPasswordChange && $_SESSION['must_change_password']) {
        header('Location: ' . app_path('change_password.php?session_context=' . rawurlencode($role)));
        exit;
    }
}

/** Put at the top of pages limited to certain roles, e.g. require_role(['admin','staff']). */
function require_role(array $roles): void
{
    require_login();
    if (!in_array(current_role(), $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function role_label(?string $role): string
{
    return [
        'admin'  => 'Owner / Manager',
        'staff'  => 'Clinic Staff',
        'vet'    => 'Veterinarian',
        'client' => 'Pet Owner',
    ][$role] ?? 'User';
}

/* ---------- CSRF protection for forms ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid($token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}