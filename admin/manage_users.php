<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
require_role(['admin']);

$error = '';
$success = '';
$passwordWeak = false;

// Admin-created accounts require a long password with mixed character types.
function admin_password_is_strong(string $password): bool
{
    return strlen($password) >= 7
        && strlen($password) <= 72
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

/* ------------------------------------------------------------------
   Create account / change status
------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        $action = posted_string('action');
        $pdo = null;
        try {
            $pdo = db();
            if ($action === 'create_user') {
                $fullName = trim(posted_string('full_name'));
                $email = normalize_email(posted_string('email'));
                $password = posted_string('password');
                $accountRole = posted_string('role');
                $licenseNumber = trim(posted_string('license_number'));
                $contactNumber = trim(posted_string('contact_number'));
                $staffPosition = posted_string('staff_role');
                $otherStaffPosition = trim(posted_string('staff_role_other'));

                if (!valid_full_name($fullName) || strlen($fullName) > 100) {
                    $error = 'Enter a valid full name (up to 100 characters).';
                } elseif (!valid_email($email)) {
                    $error = 'Enter a valid email address.';
                } elseif (!in_array($accountRole, ['staff', 'vet'], true)) {
                    $error = 'Only Staff and Veterinarian accounts can be created here.';
                } elseif (!admin_password_is_strong($password)) {
                    // No banner text: the "Weak password" popup is shown on the field instead.
                    $passwordWeak = true;
                } elseif ($accountRole === 'vet' && ($licenseNumber === '' || strlen($licenseNumber) > 20)) {
                    $error = 'Enter the veterinarian license number (up to 20 characters).';
                } elseif ($accountRole === 'vet' && ($contactNumber === '' || strlen($contactNumber) > 20)) {
                    $error = 'Enter the veterinarian contact number (up to 20 characters).';
                } elseif ($accountRole === 'staff' && !in_array($staffPosition, ['Clinic Staff', 'Groomer', 'Receptionist', 'Veterinary Assistant', 'Other'], true)) {
                    $error = 'Choose a valid staff position.';
                } elseif ($accountRole === 'staff' && $staffPosition === 'Other' && ($otherStaffPosition === '' || mb_strlen($otherStaffPosition) > 50)) {
                    $error = 'Enter a staff position up to 50 characters long.';
                } else {
                    if ($staffPosition === 'Other') {
                        $staffPosition = $otherStaffPosition;
                    }
                    $pdo->beginTransaction();
                    $check = $pdo->prepare('SELECT user_id FROM Users WHERE email = ? LIMIT 1 FOR UPDATE');
                    $check->execute([$email]);
                    if ($check->fetch()) {
                        $pdo->rollBack();
                        $error = 'An account with this email already exists.';
                    } else {
                        $staffId = null;
                        $vetId = null;

                        if ($accountRole === 'vet') {
                            $insertProfile = $pdo->prepare(
                                'INSERT INTO Veterinarians (full_name, license_number, contact_number) VALUES (?, ?, ?)'
                            );
                            $insertProfile->execute([$fullName, $licenseNumber, $contactNumber]);
                            $vetId = (int) $pdo->lastInsertId();
                            $assignServices = $pdo->prepare(
                                'INSERT IGNORE INTO VetServices (vet_id, service_id)
                                 SELECT ?, service_id FROM Services'
                            );
                            $assignServices->execute([$vetId]);
                        } else {
                            $insertProfile = $pdo->prepare(
                                'INSERT INTO Staff (full_name, `role`, can_manage_groomer_schedules) VALUES (?, ?, ?)'
                            );
                            $insertProfile->execute([$fullName, $staffPosition, $staffPosition === 'Groomer' ? 1 : 0]);
                            $staffId = (int) $pdo->lastInsertId();
                        }

                        $insertUser = $pdo->prepare(
                            "INSERT INTO Users (full_name, email, password_hash, `role`, status, staff_id, vet_id)
                             VALUES (?, ?, ?, ?, 'active', ?, ?)"
                        );
                        $insertUser->execute([
                            $fullName,
                            $email,
                            password_hash($password, PASSWORD_DEFAULT),
                            $accountRole,
                            $staffId,
                            $vetId,
                        ]);
                        $userId = (int) $pdo->lastInsertId();

                        if ($accountRole === 'staff' && $staffPosition === 'Groomer') {
                            $existingGroomer = $pdo->prepare(
                                'SELECT groomer_id FROM Groomers
                                 WHERE user_id IS NULL AND full_name = ? LIMIT 1 FOR UPDATE'
                            );
                            $existingGroomer->execute([$fullName]);
                            $groomerId = (int) $existingGroomer->fetchColumn();
                            if ($groomerId > 0) {
                                $pdo->prepare(
                                    'UPDATE Groomers SET user_id = ? WHERE groomer_id = ? AND user_id IS NULL'
                                )->execute([$userId, $groomerId]);
                            } else {
                                $pdo->prepare(
                                    'INSERT INTO Groomers (user_id, full_name) VALUES (?, ?)'
                                )->execute([$userId, $fullName]);
                            }
                        }

                        $pdo->commit();
                        $success = $accountRole === 'vet'
                            ? 'Veterinarian account created.'
                            : ($staffPosition === 'Groomer'
                                ? 'Groomer account created and linked to its profile.'
                                : 'Staff account created.');
                    }
                }
            } elseif ($action === 'set_status') {
                $userId = filter_var(posted_string('user_id'), FILTER_VALIDATE_INT);
                $status = posted_string('status');
                if (!$userId || !in_array($status, ['active', 'inactive'], true)) {
                    $error = 'Invalid account status request.';
                } elseif ((int) $userId === (int) $_SESSION['user_id'] && $status === 'inactive') {
                    $error = 'You cannot deactivate your own account.';
                } else {
                    $update = $pdo->prepare('UPDATE Users SET status = ? WHERE user_id = ?');
                    $update->execute([$status, $userId]);
                    $success = $update->rowCount() ? 'Account status updated.' : 'Account status is unchanged or the account no longer exists.';
                }
            } elseif ($action === 'set_schedule_permission') {
                $userId = filter_var(posted_string('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $permission = posted_string('can_manage_vet_schedules');
                if ($userId === false || !in_array($permission, ['0', '1'], true)) {
                    $error = 'Choose a valid staff account and permission.';
                } else {
                    $pdo->beginTransaction();
                    $staffStatement = $pdo->prepare(
                      "SELECT s.staff_id
                         FROM Users u JOIN Staff s ON s.staff_id = u.staff_id
                       JOIN Groomers g ON g.user_id = u.user_id
                       WHERE u.user_id = ? AND u.`role` = 'staff'
                         FOR UPDATE"
                    );
                    $staffStatement->execute([(int) $userId]);
                    $staffId = (int) $staffStatement->fetchColumn();
                    if ($staffId < 1) {
                        $pdo->rollBack();
                        $error = 'That staff account could not be found.';
                    } else {
                        $pdo->prepare('UPDATE Staff SET can_manage_vet_schedules = ? WHERE staff_id = ?')
                            ->execute([(int) $permission, $staffId]);
                        audit_log_record(
                            $pdo,
                            (int) $_SESSION['user_id'],
                            'staff.schedule_permission.updated',
                            'staff',
                            $staffId,
                            'Vet schedule management ' . ((int) $permission === 1 ? 'granted' : 'revoked')
                        );
                        $pdo->commit();
                        $success = (int) $permission === 1
                            ? 'Veterinarian schedule management access granted.'
                            : 'Veterinarian schedule management access revoked.';
                    }
                }
                  } elseif ($action === 'set_groomer_schedule_permission') {
                    $userId = filter_var(posted_string('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $permission = posted_string('can_manage_groomer_schedules');
                    if ($userId === false || !in_array($permission, ['0', '1'], true)) {
                      $error = 'Choose a valid staff account and grooming schedule permission.';
                    } else {
                      $pdo->beginTransaction();
                      $staffStatement = $pdo->prepare(
                        "SELECT s.staff_id, s.`role`
                         FROM Users u JOIN Staff s ON s.staff_id = u.staff_id
                         WHERE u.user_id = ? AND u.`role` = 'staff'
                         FOR UPDATE"
                      );
                      $staffStatement->execute([(int) $userId]);
                      $staff = $staffStatement->fetch();
                      $staffId = (int) ($staff['staff_id'] ?? 0);
                      if ($staffId < 1) {
                        $pdo->rollBack();
                        $error = 'That staff account could not be found.';
                      } elseif ($staff['role'] === 'Groomer') {
                        $pdo->rollBack();
                        $error = 'Grooming access is automatic for Groomer accounts.';
                      } else {
                        $pdo->prepare('UPDATE Staff SET can_manage_groomer_schedules = ? WHERE staff_id = ?')
                          ->execute([(int) $permission, $staffId]);
                        audit_log_record(
                          $pdo,
                          (int) $_SESSION['user_id'],
                          'staff.groomer_schedule_permission.updated',
                          'staff',
                          $staffId,
                          'Groomer schedule access ' . ((int) $permission === 1 ? 'granted' : 'revoked')
                        );
                        $pdo->commit();
                        $success = (int) $permission === 1
                          ? 'Grooming schedule access granted.'
                          : 'Grooming schedule access revoked.';
                      }
                    }
            } elseif ($action === 'set_vet_capacity') {
                $vetId = filter_var(posted_string('vet_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $capacity = filter_var(posted_string('max_appointments_per_day'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
                if ($vetId === false || $capacity === false) {
                    $error = 'Set a daily capacity between 1 and 100 appointments.';
                } else {
                    $pdo->beginTransaction();
                    $update = $pdo->prepare('UPDATE Veterinarians SET max_appointments_per_day = ? WHERE vet_id = ?');
                    $update->execute([(int) $capacity, (int) $vetId]);
                    if ($update->rowCount() < 1) {
                        $exists = $pdo->prepare('SELECT vet_id FROM Veterinarians WHERE vet_id = ?');
                        $exists->execute([(int) $vetId]);
                        if (!$exists->fetchColumn()) {
                            throw new InvalidArgumentException('That veterinarian no longer exists.');
                        }
                    }
                    audit_log_record(
                        $pdo,
                        (int) $_SESSION['user_id'],
                        'vet.daily_capacity.updated',
                        'veterinarian',
                        (int) $vetId,
                        'Daily appointment capacity set to ' . (int) $capacity
                    );
                    $pdo->commit();
                    $success = 'Daily appointment capacity updated.';
                }
            } else {
                $error = 'Unsupported request.';
            }
        } catch (Throwable $ex) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex instanceof PDOException && $ex->getCode() === '23000') {
                $error = 'This email or veterinarian license number is already in use.';
            } else {
                error_log($ex->getMessage());
                $error = 'The account could not be saved. Check the database connection and try again.';
            }
        }
    }
}

/* ------------------------------------------------------------------
   Load every account (search and role filter run live in the browser)
------------------------------------------------------------------ */
$users = [];
$listError = '';
try {
    $statement = db()->prepare(
        "SELECT u.user_id, u.full_name, u.email, u.`role`, u.status, u.created_at, u.staff_id, u.vet_id,
          COALESCE(s.can_manage_vet_schedules, 0) AS can_manage_vet_schedules,
                COALESCE(s.can_manage_groomer_schedules, 0) AS can_manage_groomer_schedules,
          COALESCE(s.`role`, '') AS staff_position,
                EXISTS (SELECT 1 FROM Groomers g WHERE g.user_id = u.user_id) AS has_groomer_profile,
                CASE WHEN u.`role` = 'staff' AND EXISTS (
                    SELECT 1 FROM Groomers g
                    WHERE g.user_id = u.user_id
                      AND (
                        EXISTS (SELECT 1 FROM GroomerScheduleRequests r WHERE r.groomer_id = g.groomer_id AND r.status = 'Pending')
                        OR EXISTS (SELECT 1 FROM GroomerLeaves l WHERE l.groomer_id = g.groomer_id AND l.status = 'Pending')
                      )
                      )
                THEN 1 ELSE 0 END AS has_pending_schedule_or_leave_request
         FROM Users u
         LEFT JOIN Staff s ON s.staff_id = u.staff_id
         ORDER BY u.created_at DESC, u.user_id DESC"
    );
    $statement->execute();
    $users = $statement->fetchAll();
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $listError = 'User accounts are unavailable. Check the database connection and required user columns.';
}
$veterinarians = [];
try {
    $veterinarians = db()->query(
        'SELECT vet_id, full_name, max_appointments_per_day
         FROM Veterinarians
         ORDER BY full_name'
    )->fetchAll();
} catch (Throwable $ex) {
    error_log('Veterinarian capacity list failed: ' . $ex->getMessage());
    $listError = 'Veterinarian booking capacity is unavailable.';
}

require_once __DIR__ . '/../includes/admin_notifications.php';
$notifications = ['count' => 0, 'items' => [], 'error' => ''];
try {
    $noticeDb = db();
    $notifications = dashboard_notification_state(
        $noticeDb,
        (int) $_SESSION['user_id']
    );
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $notifications['error'] = 'Notification data is unavailable.';
}
$notificationBadge = $notifications['count'] > 99 ? '99+' : (string) $notifications['count'];
$pageTitle = 'Manage Users';
$activePage = 'users';

$roleLabel = function (string $role): string {
    return $role === 'vet' ? 'Veterinarian' : ucfirst($role);
};
$filterRoles = ['admin', 'staff', 'vet', 'client'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($pageTitle) ?> | Animal Clinic</title>
  <script src="../assets/JS/admin-sidebar-state.js"></script>
  <style>
    :root{--blue-50:#eaf3fa;--blue-100:#c2dcf1;--blue-300:#81b7e0;--blue-500:#0284c7;--blue-700:#015b8c;--blue-900:#002e4a;--black-blue:#000914;--ink:#092947;--muted:#42637e;--line:#d7e8f5;--grid-line:#d3e3ef;--grid-strong:#8fbadb}
    *{box-sizing:border-box}html,body{width:100%;height:100%;margin:0}body.admin-dashboard{min-width:320px;height:100vh;height:100dvh;overflow:hidden;color:var(--ink);background:#f4f9fd;font:14px/1.45 Arial,Helvetica,sans-serif}
    .app-shell{width:100%;height:100%;display:grid;grid-template-columns:198px minmax(0,1fr);overflow:hidden}.sidebar{position:relative;z-index:2;min-width:0;padding:20px 10px 16px;overflow:hidden auto;color:#fff;background:linear-gradient(180deg,var(--blue-700),var(--blue-900))}
    .sidebar:after{position:absolute;z-index:-1;right:-60px;bottom:0;left:-60px;height:210px;background:url("../assets/images/Abstract%20Blue%20Wave%20Gradient.png") center bottom/cover no-repeat;opacity:.34;content:"";pointer-events:none}
    .brand-lockup{margin:2px 0 28px;display:flex;align-items:center;flex-direction:column;gap:8px;color:#fff;text-align:center;text-decoration:none}.brand-logo{width:94px;height:94px;display:block;object-fit:cover;background:#fff;border:2px solid rgba(255,255,255,.55);border-radius:50%}
    .brand-copy{font-size:.92rem;font-weight:700;line-height:1.35}.brand-copy span{display:block;font-size:.82rem;font-weight:400}.side-nav{display:grid;gap:5px}
    .nav-item{min-width:0;min-height:46px;padding:10px 12px;display:grid;grid-template-columns:23px minmax(0,1fr);align-items:center;gap:12px;color:#eaf3fa;border-radius:10px;font-size:.92rem;text-decoration:none}
    .nav-item svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:2}.nav-item:first-child svg path{fill:currentColor;stroke:none}.nav-item:hover,.nav-item.active{color:#fff;background:var(--blue-500)}.logout-link{margin-top:16px}
    .main-area{min-width:0;min-height:0;display:grid;grid-template-rows:76px minmax(0,1fr);overflow:hidden}.topbar{position:relative;z-index:5;min-width:0;padding:10px clamp(18px,2.4vw,36px);display:flex;align-items:center;justify-content:space-between;gap:16px;background:linear-gradient(100deg,var(--blue-50),#fff 76%);border-bottom:1px solid var(--line);box-shadow:0 2px 9px rgba(0,46,74,.06)}
    .topbar-title h1{margin:0;color:var(--blue-900);font-size:1.48rem;line-height:1.2}.topbar-title p{margin:2px 0 0;color:var(--blue-700);font-size:.91rem}.profile-area{display:flex;align-items:center;gap:12px;color:var(--blue-900)}
    .notification-menu{position:relative}.notification{position:relative;width:34px;height:36px;display:grid;place-items:center;color:var(--blue-700);list-style:none;cursor:pointer}.notification::-webkit-details-marker{display:none}.notification svg{width:27px;height:27px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
    .notification-count{position:absolute;top:-3px;right:-5px;min-width:18px;height:18px;padding:0 4px;display:grid;place-items:center;color:white;background:#e33b43;border:2px solid white;border-radius:999px;font-size:.64rem;font-weight:700}
    .notification-panel{position:absolute;top:calc(100% + 13px);right:-18px;z-index:8;width:min(360px,calc(100vw - 24px));max-height:min(460px,calc(100dvh - 100px));padding:8px;overflow-y:auto;color:var(--ink);background:white;border:1px solid var(--line);border-radius:13px;box-shadow:0 14px 36px rgba(0,46,74,.2)}
    .notification-panel h2{margin:4px 8px 8px;color:var(--blue-900);font-size:.95rem}.notification-item{padding:9px 8px;display:grid;grid-template-columns:10px minmax(0,1fr) auto;align-items:center;gap:9px;border-top:1px solid #edf3f8;text-decoration:none}
    .notification-item:hover{background:var(--blue-50)}.notification-dot{width:9px;height:9px;border-radius:50%;background:var(--blue-500)}.notification-dot.inventory,.notification-dot.billing{background:#e59824}.notification-dot.transaction{background:#39a267}
    .notification-copy{min-width:0;display:grid;gap:2px}.notification-copy strong{color:var(--blue-900);font-size:.8rem}.notification-copy small,.notification-empty{color:var(--muted);font-size:.75rem}.notification-item-count{color:var(--blue-700);font-size:.78rem;font-weight:700}.notification-empty{margin:0;padding:14px 8px;text-align:center}
    .profile-divider{width:1px;height:34px;margin:0 5px;background:var(--blue-300)}.profile-display{min-width:0;display:flex;align-items:center;gap:9px;color:inherit;text-decoration:none}.profile-avatar{width:42px;height:42px;display:grid;place-items:center;color:var(--blue-700);background:var(--blue-100);border-radius:50%}.profile-avatar svg{width:27px;height:27px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}.profile-copy{min-width:0}.profile-name{max-width:180px;overflow:hidden;color:var(--blue-900);font-weight:700;text-overflow:ellipsis;white-space:nowrap}
    .page-content{width:100%;max-width:none;min-width:0;min-height:0;margin:0;padding:12px clamp(16px,1.8vw,26px) 24px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain}
    .notice{margin-bottom:14px;padding:11px 14px;border-radius:9px}.notice.error{color:#842626;background:#fdeaea}.notice.success{color:#17623b;background:#e3f5e9}.notice.info{color:var(--blue-900);background:var(--blue-50)}

    .panel{min-width:0;margin:0 0 14px;overflow:hidden;background:white;border:1px solid #cbdfee;border-radius:16px;box-shadow:0 6px 18px rgba(0,46,74,.11)}.panel-header{min-height:48px;padding:9px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:white;background:linear-gradient(105deg,#015b8c,#002e4a)}.panel-header h2,.panel-title h2{margin:0;color:inherit;font-size:.98rem}.panel-title{min-width:0;display:flex;align-items:center;gap:9px}.panel-body{min-width:0;padding:15px}.data-count,.muted{color:var(--muted);font-size:.78rem}.panel-header .data-count{color:#eaf3fa;font-weight:700}
    .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form-grid .wide{grid-column:1/-1}.form-grid label{min-width:0;display:grid;gap:5px;color:var(--blue-900);font-size:.82rem;font-weight:700}.form-grid label[hidden]{display:none}
    .form-grid input,.form-grid select,.toolbar input,.toolbar select{width:100%;min-width:0;min-height:41px;padding:9px 11px;color:var(--ink);background:white;border:1px solid #bed4e5;border-radius:8px;font:inherit}.form-grid input:focus,.form-grid select:focus,.toolbar input:focus,.toolbar select:focus{outline:3px solid rgba(2,132,199,.16);border-color:var(--blue-500)}
    /* Dropdown arrow: custom, aligned with the field padding */
    .form-grid select,.toolbar select{-webkit-appearance:none;-moz-appearance:none;appearance:none;padding-right:42px;cursor:pointer;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23002e4a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 16px center/16px 16px}
    .toolbar{margin:0 0 13px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px}.toolbar label{min-width:150px;flex:1 1 220px;display:grid;gap:5px;color:var(--blue-900);font-size:.79rem;font-weight:700}.toolbar label.role-filter{flex:0 1 220px}
    .button,button.button{min-height:40px;padding:8px 13px;display:inline-flex;align-items:center;justify-content:center;gap:6px;color:white;background:var(--blue-500);border:1px solid var(--blue-500);border-radius:8px;font:inherit;font-size:.84rem;font-weight:700;text-decoration:none;cursor:pointer}.button:hover,button.button:hover{background:var(--blue-700);border-color:var(--blue-700)}.button.secondary{color:var(--blue-700);background:white;border-color:#b8d2e5}.button.secondary:hover{background:var(--blue-50)}.button.danger{background:#b93838;border-color:#b93838}.button.danger:hover{background:#972b2b;border-color:#972b2b}.button:disabled{opacity:.55;cursor:not-allowed}
    .button.small{min-height:34px;padding:6px 12px;font-size:.78rem}

    /* ---------- Weak-password popup (same style as signup) ---------- */
    .pw-wrap{position:relative;display:block}
    .pw-wrap input{padding-right:50px}
    .pw-toggle{position:absolute;top:50%;right:8px;width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;color:#647d9f;background:transparent;border:0;border-radius:50%;transform:translateY(-50%);cursor:pointer;transition:color .2s,background .2s}
    .pw-toggle:hover{color:var(--blue-900);background:rgba(2,132,199,.08)}.pw-toggle:focus-visible{outline:2px solid var(--blue-500);outline-offset:1px}
    .pw-toggle svg{width:22px;height:22px}.pw-toggle .eye-open{display:none}.pw-toggle .eye-closed{display:block}
    .pw-toggle[aria-pressed="true"] .eye-open{display:block}.pw-toggle[aria-pressed="true"] .eye-closed{display:none}
    .pw-tip{position:absolute;top:calc(100% + 8px);left:0;z-index:5;max-width:min(100%,calc(100vw - 48px));display:flex;align-items:center;gap:8px;padding:8px 12px;color:#b3261e;background:#fff;border:1px solid #f3b4b0;border-radius:10px;box-shadow:0 8px 24px rgba(0,46,74,.18);font-size:.85rem;font-weight:700;line-height:1;white-space:normal;pointer-events:none;opacity:0;visibility:hidden;transform:translateY(-4px);transition:opacity .2s ease,transform .2s ease,visibility 0s .2s}
    .pw-tip.show{opacity:1;visibility:visible;transform:translateY(0);transition-delay:0s}
    .pw-tip::before{position:absolute;top:-6px;left:20px;width:10px;height:10px;content:"";background:#fff;border-top:1px solid #f3b4b0;border-left:1px solid #f3b4b0;transform:rotate(45deg)}
    .pw-tip svg{width:16px;height:16px;flex:none}
    .pw-requirements{color:var(--muted);font-size:.72rem;font-weight:500;line-height:1.4}
    .form-grid input.invalid{border-color:#d93025;box-shadow:0 0 0 3px rgba(217,48,37,.12)}

    /* ---------- Table: aligned columns, grid lines, alternating colors ---------- */
    .table-wrap{width:100%;min-width:0;overflow-x:auto;overflow-y:hidden;border:1px solid var(--grid-line);border-radius:12px;background:#fff}
    .records-table{width:100%;min-width:820px;table-layout:fixed;border-collapse:separate;border-spacing:0;text-align:left}
    .records-table th,.records-table td{padding:12px 14px;overflow:hidden;border-right:1px solid var(--grid-line);border-bottom:1px solid var(--grid-line);text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
    .records-table th:last-child,.records-table td:last-child{border-right:0}
    .records-table thead th{position:sticky;top:0;z-index:1;color:var(--blue-900);background:linear-gradient(180deg,#e4f1fb,#cfe5f5);border-bottom:2px solid var(--grid-strong);border-right-color:#b5d2e7;font-size:.77rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase}
    .records-table tbody td{color:#244661;font-size:.83rem;line-height:1.35;background:#fff}
    .records-table tbody tr.alt td{background:#f1f7fc}
    .records-table tbody tr:hover td{background:#e1effa}
    .records-table tbody tr:last-child td{border-bottom:0}
    .records-table tr[hidden]{display:none}
    .records-table .col-center{text-align:center}
    .records-table .col-name{font-weight:700;color:var(--blue-900)}
    .records-table tbody tr.empty-row td{padding:0;background:#fff;border-right:0;border-bottom:0}
    .empty-state-box{min-height:150px;padding:16px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;color:var(--muted);text-align:center;white-space:normal}.empty-state-box span{font-size:.92rem;font-weight:700;color:#4d6f8a}.empty-state-box small{font-size:.76rem;color:#7b97ad}

    .status,.role-pill{padding:5px 11px;display:inline-flex;align-items:center;border-radius:999px;font-size:.72rem;font-weight:700;line-height:1.2;white-space:nowrap}
    .status{color:#225d40;background:#e3f5e9}.status.active{color:#225d40;background:#e3f5e9}.status.inactive{color:#8c3232;background:#fdeaea}
    .role-pill{color:#155c86;background:#e2f2fc}.role-pill.admin{color:#fff;background:#015b8c}.role-pill.staff{color:#155c86;background:#e2f2fc}.role-pill.vet{color:#0b6b57;background:#e0f5f0}.role-pill.client{color:#4a5a68;background:#edf0f3}
    .inline-form{display:inline-flex}
    @media(max-width:1050px){.app-shell{grid-template-columns:178px minmax(0,1fr)}.brand-logo{width:82px;height:82px}}
    @media(max-width:760px){.app-shell{grid-template-columns:68px minmax(0,1fr)}.sidebar{padding:12px 6px}.brand-lockup{margin:3px 0 24px}.brand-logo{width:48px;height:48px}.brand-copy,.nav-item span{display:none}.nav-item{min-height:45px;padding:10px;grid-template-columns:1fr;justify-items:center}.nav-item svg{width:24px;height:24px}.main-area{grid-template-rows:68px minmax(0,1fr)}.topbar{padding:8px 12px;gap:8px}.topbar-title h1{font-size:1.12rem}.topbar-title p{font-size:.76rem}.profile-area{gap:7px}.profile-divider,.profile-copy,.profile-chevron{display:none}.profile-avatar{width:36px;height:36px}.profile-avatar svg{width:22px;height:22px}.page-content{padding:12px 11px 18px}.form-grid{grid-template-columns:minmax(0,1fr)}.form-grid .wide{grid-column:1}}
    @media(max-width:480px){.topbar-title h1{font-size:1rem}.topbar-title p{font-size:.68rem}.notification{width:30px}.notification svg{width:24px;height:24px}.panel-header{padding:8px 10px}.panel-header h2,.panel-title h2{font-size:.88rem}.panel-body{padding:11px}.notification-panel{right:-41px}}
    .notification-empty.error{color:#8c3232}
  </style>
  <style>
    body.admin-dashboard{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5}
    body.admin-dashboard .topbar-title h1{font-size:1.4rem;line-height:1.2}
    body.admin-dashboard .topbar-title p,body.admin-dashboard .page-intro p{font-size:.875rem}
    body.admin-dashboard .page-intro h2{font-size:1.25rem}
    body.admin-dashboard .panel{border-radius:14px}
    body.admin-dashboard .panel-header{min-height:44px;padding:9px 14px}
    body.admin-dashboard .panel-header h2,body.admin-dashboard .panel-title h2{font-size:1rem}
    body.admin-dashboard .button,body.admin-dashboard button.button{min-height:40px;font-size:.85rem;line-height:1.25}
    body.admin-dashboard .button.small{min-height:34px;font-size:.8rem}
    body.admin-dashboard .records-table thead th{font-size:.75rem}
    body.admin-dashboard .records-table tbody td{font-size:.84rem;line-height:1.4}
    body.admin-dashboard .stat-label{font-size:.75rem}
    body.admin-dashboard .stat-value{font-size:1.75rem}
    body.admin-dashboard .status{font-size:.75rem}
    body.admin-dashboard .form-grid label,body.admin-dashboard label.field{font-size:.82rem}
    body.admin-dashboard input,body.admin-dashboard select,body.admin-dashboard textarea{font-family:inherit;font-size:.875rem}
    body.admin-dashboard .hint,body.admin-dashboard .form-note,body.admin-dashboard .data-count{font-size:.8rem}
    @media(max-width:760px){body.admin-dashboard .topbar-title h1{font-size:1.12rem}body.admin-dashboard .topbar-title p{font-size:.78rem}}
    @media(max-width:480px){body.admin-dashboard .topbar-title h1{font-size:1.05rem}body.admin-dashboard .page-intro h2{font-size:1.15rem}body.admin-dashboard .panel-header{padding:9px 11px}body.admin-dashboard .panel-header h2,body.admin-dashboard .panel-title h2{font-size:.95rem}body.admin-dashboard .panel-body{padding:12px}}
  </style>
  <link rel="stylesheet" href="../assets/css/data-tables.css">
  <link rel="stylesheet" href="../assets/css/admin-professional.css">
  <style>
    body.admin-dashboard .main-area{width:100%;min-width:0}
    body.admin-dashboard .page-content{width:100%;max-width:1600px;padding:clamp(16px,2vw,30px);overflow-x:hidden}
    body.admin-dashboard .panel{border:1px solid #d7e8f5;border-radius:18px;box-shadow:0 8px 24px rgba(0,46,74,.08)}
    body.admin-dashboard .panel-header{min-height:54px;padding:12px 18px}
    body.admin-dashboard .panel-body{padding:clamp(16px,2vw,24px)}
    body.admin-dashboard .stat-grid{gap:16px;margin-bottom:20px}
    body.admin-dashboard .stat-card{border-radius:16px;box-shadow:0 2px 6px rgba(0,46,74,.04),0 8px 20px rgba(0,46,74,.06)}
    body.admin-dashboard .toolbar{gap:12px;padding:14px;background:#f6fafe;border:1px solid #e1edf6;border-radius:14px}
    body.admin-dashboard .toolbar label{min-width:0;flex:1 1 220px}
    body.admin-dashboard .toolbar input,body.admin-dashboard .toolbar select{min-height:44px;border-radius:10px}
    body.admin-dashboard .toolbar .button{min-height:44px;border-radius:10px}
    body.admin-dashboard .data-table-card{border-color:#d1e2ee;border-radius:14px;box-shadow:0 3px 12px rgba(0,46,74,.04)}
    @media(max-width:760px){body.admin-dashboard .page-content{padding:14px}body.admin-dashboard .panel-header{padding:11px 14px}body.admin-dashboard .panel-body{padding:16px}body.admin-dashboard .toolbar{padding:12px}}
    @media(max-width:480px){body.admin-dashboard .page-content{padding:12px}body.admin-dashboard .stat-grid{gap:10px}}
  </style>
</head>
<body class="admin-dashboard role-app">
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand-lockup" href="dashboard.php"><img class="brand-logo" src="../assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic"><span class="brand-copy">Animal Clinic<span>Management System</span></span></a>
      <nav class="side-nav" aria-label="Admin navigation">
        <a class="nav-item" href="dashboard.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8v9a1.2 1.2 0 0 1-1.2 1.2h-5.3v-6.2h-5V21H4.2A1.2 1.2 0 0 1 3 19.8z"/></svg><span>Dashboard</span></a>
        <a class="nav-item active" href="manage_users.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H5.4A3.4 3.4 0 0 0 2 18.4V20m7.5-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm12.5 9v-1.6a3.4 3.4 0 0 0-2.6-3.3M16.2 3.1a4 4 0 0 1 0 7.7"/></svg><span>Manage Users</span></a>
        <a class="nav-item" href="appointments.php"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M7 14h3m-3 3h3m4-3h3"/></svg><span>Appointments</span></a>
        <a class="nav-item" href="schedule_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg><span>Vet Schedules</span></a>
        <a class="nav-item" href="groomer_schedule_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4.5 4.5l2 2M19.5 4.5l-2 2"/></svg><span>Groomer Schedules</span></a>
        <a class="nav-item" href="inventory.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5zm-9 9 9 5 9-5m-18 5 9 5 9-5M12 12v9"/></svg><span>Inventory</span></a>
        <a class="nav-item" href="admin_reports.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V11h4v9zm6 0V5h4v15zm6 0V8h4v12z"/></svg><span>Reports</span></a>
        <a class="nav-item" href="admin_settings.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/><path d="m19.4 15 .1.1 1.1 1.7-1.8 3.1-2-.5a8 8 0 0 1-1.5.9l-.4 2h-3.6l-.4-2a8 8 0 0 1-1.5-.9l-2 .5-1.8-3.1 1.1-1.7a7 7 0 0 1 0-1.8l-1.1-1.7 1.8-3.1 2 .5a8 8 0 0 1 1.5-.9l.4-2h3.6l.4 2a8 8 0 0 1 1.5.9l2-.5 1.8 3.1-1.1 1.7a7 7 0 0 1-.1 1.8z"/></svg><span>Settings</span></a>
        <a class="nav-item logout-link" href="../logout.php?session_context=admin"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg><span>Logout</span></a>
      </nav>
    </aside>
    <div class="main-area">
      <header class="topbar"><div class="topbar-title"><div><h1><?= e($pageTitle) ?></h1><p>Manage staff and veterinarian accounts</p></div></div>
        <div class="profile-area"><details class="notification-menu" id="notification-menu"><summary class="notification" id="notification-summary" aria-label="Open clinic notifications" title="Clinic notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"/></svg><?php if ($notifications['count'] > 0): ?><span class="notification-count"><?= e($notificationBadge) ?></span><?php endif; ?></summary>
          <div class="notification-panel"><h2>Clinic notifications</h2><div id="notification-items" aria-live="polite"><?php if ($notifications['error']): ?><p class="notification-empty error" role="alert"><?= e($notifications['error']) ?></p><?php elseif ($notifications['items']): ?><?php foreach ($notifications['items'] as $item): ?><a class="notification-item" href="<?= e($item['href']) ?>"><span class="notification-dot <?= e($item['kind']) ?>" aria-hidden="true"></span><span class="notification-copy"><strong><?= e($item['label']) ?></strong><small><?= e($item['detail']) ?></small></span><?php if ($item['count'] > 0): ?><span class="notification-item-count"><?= number_format($item['count']) ?></span><?php endif; ?></a><?php endforeach; ?><?php else: ?><p class="notification-empty">No notifications right now.</p><?php endif; ?></div></div>
        </details><span class="profile-divider" aria-hidden="true"></span><a class="profile-display" href="admin_settings.php" aria-label="Admin profile settings"><span class="profile-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><span class="profile-copy"><span class="profile-name"><?= e($_SESSION['full_name'] ?? 'Admin') ?></span></span></a></div>
      </header>
      <main class="page-content">
        <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="notice success" role="status"><?= e($success) ?></div><?php endif; ?>
        <?php if ($listError): ?><div class="notice error" role="alert"><?= e($listError) ?></div><?php endif; ?>

        <section class="panel">
          <div class="panel-header"><h2>Create Staff or Veterinarian account</h2></div>
          <div class="panel-body">
            <form method="post" class="form-grid" id="create-user-form" novalidate>
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_user">
              <label>Full name<input type="text" name="full_name" required maxlength="100" autocomplete="name"></label>
              <label>Email address<input type="email" name="email" required maxlength="254" autocomplete="email"></label>
              <label>Password
                <span class="pw-wrap">
                  <input type="password" id="password" name="password" required minlength="7" maxlength="72" autocomplete="new-password" aria-describedby="password-requirements"<?= $passwordWeak ? ' class="invalid"' : '' ?>>
                  <button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false">
                    <svg class="eye-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                      <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5ZM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5Zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3Z"/>
                    </svg>
                    <svg class="eye-closed" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                      <path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7ZM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27ZM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2Zm4.31-.78 3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01Z"/>
                    </svg>
                  </button>
                  <span class="pw-tip<?= $passwordWeak ? ' show' : '' ?>" id="pwTip" role="alert">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/></svg>
                    <span>Use 7+ characters with uppercase, lowercase, a number, and a symbol.</span>
                  </span>
                </span>
                <span class="pw-requirements" id="password-requirements">At least 7 characters, including uppercase, lowercase, a number, and a symbol.</span>
              </label>
              <label>Account type<select name="role" id="account-role" required><option value="staff">Staff</option><option value="vet">Veterinarian</option></select></label>
              <label class="vet-only" hidden>Veterinarian license number<input type="text" name="license_number" id="license-number" maxlength="20"></label>
              <label class="vet-only" hidden>Contact number<input type="text" name="contact_number" id="contact-number" maxlength="20" autocomplete="tel"></label>
              <label class="wide staff-only">Staff position
                <select name="staff_role" id="staff-position" required>
                  <option value="Clinic Staff">Clinic Staff</option>
                  <option value="Groomer">Groomer</option>
                  <option value="Receptionist">Receptionist</option>
                  <option value="Veterinary Assistant">Veterinary Assistant</option>
                  <option value="Other">Other (specify)</option>
                </select>
              </label>
              <label class="wide staff-only" id="other-staff-position-field" hidden>Other staff position
                <input type="text" name="staff_role_other" id="other-staff-position" maxlength="50" placeholder="Enter position">
              </label>
              <div class="wide"><button class="button" type="submit">Create account</button></div>
            </form>
          </div>
        </section>

        <section class="panel">
          <div class="panel-header"><div class="panel-title"><h2>Veterinarian daily booking capacity</h2></div><span class="data-count">Confirmed appointments only</span></div>
          <div class="panel-body">
            <p class="muted">Each veterinarian defaults to 10 confirmed appointments per day. Pending requests, cancelled visits and rejected visits do not count. The existing conflict check still limits a veterinarian to one appointment at a time.</p>
            <div class="table-wrap data-table-card">
              <table class="records-table">
                <colgroup><col style="width:55%"><col style="width:7rem"><col style="width:15rem"></colgroup>
                <thead><tr><th>Veterinarian</th><th class="col-center">Daily limit</th><th>Update</th></tr></thead>
                <tbody>
                  <?php foreach ($veterinarians as $veterinarian): ?>
                    <tr>
                      <td data-label="Veterinarian"><?= e($veterinarian['full_name']) ?></td>
                      <td class="col-center" data-label="Daily limit"><?= (int) $veterinarian['max_appointments_per_day'] ?></td>
                      <td data-label="Update">
                        <form method="post" class="inline-form">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="set_vet_capacity">
                          <input type="hidden" name="vet_id" value="<?= (int) $veterinarian['vet_id'] ?>">
                          <label class="sr-only" for="capacity-<?= (int) $veterinarian['vet_id'] ?>">Maximum confirmed appointments per day</label>
                          <input id="capacity-<?= (int) $veterinarian['vet_id'] ?>" type="number" name="max_appointments_per_day" min="1" max="100" step="1" value="<?= (int) $veterinarian['max_appointments_per_day'] ?>" required>
                          <button class="button small" type="submit">Save limit</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$veterinarians): ?>
                    <tr class="empty-row"><td colspan="3"><div class="empty"><strong>No veterinarians are registered</strong></div></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-header"><div class="panel-title"><h2>Registered accounts</h2></div><span class="data-count" id="users-count" aria-live="polite" aria-atomic="true"><?= number_format(count($users)) ?> shown</span></div>
          <div class="panel-body">
            <div class="toolbar">
              <label>Search by name or email<input type="search" id="user-search" placeholder="Start typing a name or email…" maxlength="150" autocomplete="off"></label>
              <label class="role-filter">Filter by role<select id="role-filter"><option value="">All roles</option><?php foreach ($filterRoles as $role): ?><option value="<?= e($role) ?>"><?= e($roleLabel($role)) ?></option><?php endforeach; ?></select></label>
              <button class="button secondary" type="button" id="user-clear">Clear</button>
            </div>
            <div class="table-wrap data-table-card">
              <table class="records-table users-table">
                <colgroup><col style="width:22%"><col style="width:28%"><col style="width:6rem"><col style="width:7rem"><col style="width:9rem"><col style="width:17rem"></colgroup>
                <thead><tr><th>Name</th><th>Email</th><th class="col-center">Role</th><th class="col-center">Status</th><th class="col-center">Created</th><th class="col-center">Action</th></tr></thead>
                <tbody id="users-body">
                  <?php foreach ($users as $user):
                      $isSelf = (int) $user['user_id'] === (int) $_SESSION['user_id'];
                      $nextStatus = $user['status'] === 'active' ? 'inactive' : 'active';
                      $searchText = mb_strtolower($user['full_name'] . ' ' . $user['email']);
                  ?>
                    <tr class="user-row" data-search="<?= e($searchText) ?>" data-role="<?= e($user['role']) ?>">
                      <td class="col-name" title="<?= e($user['full_name']) ?>" data-label="Name"><?= e($user['full_name']) ?><?= $isSelf ? ' <span class="muted">(you)</span>' : '' ?></td>
                      <td title="<?= e($user['email']) ?>" data-label="Email"><?= e($user['email']) ?></td>
                      <td class="col-center" data-label="Role"><span class="role-pill <?= e($user['role']) ?>"><?= e($roleLabel($user['role'])) ?></span></td>
                      <td class="col-center" data-label="Status"><span class="status <?= e($user['status']) ?>"><?= e(ucfirst($user['status'])) ?></span></td>
                      <td class="col-center" data-label="Created"><?= e(date('M j, Y', strtotime($user['created_at']))) ?></td>
                      <td class="col-center" data-label="Action">
                        <div class="account-actions">
                          <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>"><input type="hidden" name="status" value="<?= e($nextStatus) ?>">
                            <button class="button small <?= $nextStatus === 'inactive' ? 'danger' : 'secondary' ?>" type="submit"<?= $isSelf && $nextStatus === 'inactive' ? ' disabled' : '' ?>><?= $nextStatus === 'active' ? 'Activate' : 'Deactivate' ?></button>
                          </form>
                          <?php if ($user['role'] === 'staff' && !empty($user['staff_id']) && !empty($user['has_pending_schedule_or_leave_request'])): ?>
                            <?php $nextPermission = empty($user['can_manage_vet_schedules']) ? 1 : 0; ?>
                            <form method="post" class="inline-form">
                              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="action" value="set_schedule_permission">
                              <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                              <input type="hidden" name="can_manage_vet_schedules" value="<?= $nextPermission ?>">
                              <button class="button small secondary" type="submit"><?= $nextPermission ? 'Grant schedule access' : 'Revoke schedule access' ?></button>
                            </form>
                            <?php if (!empty($user['has_groomer_profile']) && $user['staff_position'] !== 'Groomer'): ?>
                              <?php $nextGroomerPermission = empty($user['can_manage_groomer_schedules']) ? 1 : 0; ?>
                              <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="set_groomer_schedule_permission">
                                <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                <input type="hidden" name="can_manage_groomer_schedules" value="<?= $nextGroomerPermission ?>">
                                <button class="button small secondary" type="submit"><?= $nextGroomerPermission ? 'Grant grooming access' : 'Revoke grooming access' ?></button>
                              </form>
                            <?php endif; ?>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="empty-row" id="users-empty"<?= $users ? ' hidden' : '' ?>>
                    <td colspan="6"><div class="empty-state-box"><span id="users-empty-title"><?= $users ? 'No matching accounts' : 'No records found' ?></span><small id="users-empty-note"><?= $users ? 'Try a different name, email or role.' : 'No accounts have been created yet.' ?></small></div></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </main>
    </div>
  </div>
  <script>
    (() => {
      /* ---------- Live search + role filter + Clear ---------- */
      const search = document.getElementById('user-search');
      const roleFilter = document.getElementById('role-filter');
      const clearBtn = document.getElementById('user-clear');
      const rows = Array.from(document.querySelectorAll('#users-body tr.user-row'));
      const emptyRow = document.getElementById('users-empty');
      const count = document.getElementById('users-count');
      const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
      const indexedRows = rows.map((row) => ({ row, searchText: normalize(row.dataset.search) }));

      function applyFilters() {
        const query = normalize(search.value).split(/\s+/).filter(Boolean);
        const role = roleFilter.value;
        let shown = 0;

        indexedRows.forEach(({ row, searchText }) => {
          const matches = query.every((term) => searchText.includes(term)) && (!role || row.dataset.role === role);
          row.hidden = !matches;
          row.classList.toggle('alt', matches && shown % 2 === 1);  // keep alternating colors correct
          if (matches) shown++;
        });

        count.textContent = shown.toLocaleString() + ' shown';
        emptyRow.hidden = shown !== 0;
      }

      search.addEventListener('input', applyFilters);
      roleFilter.addEventListener('change', applyFilters);
      clearBtn.addEventListener('click', () => {
        search.value = '';
        roleFilter.value = '';
        applyFilters();
        search.focus();
      });
      applyFilters();

      /* ---------- Show only the fields that fit the account type ---------- */
      const accountRole = document.getElementById('account-role');
      const vetFields = document.querySelectorAll('.vet-only');
      const staffFields = document.querySelectorAll('.staff-only');
      const license = document.getElementById('license-number');
      const contact = document.getElementById('contact-number');
      const staffPosition = document.getElementById('staff-position');
      const otherStaffPositionField = document.getElementById('other-staff-position-field');
      const otherStaffPosition = document.getElementById('other-staff-position');

      function syncAccountFields() {
        const isVet = accountRole.value === 'vet';
        vetFields.forEach((el) => { el.hidden = !isVet; });
        staffFields.forEach((el) => { el.hidden = isVet; });
        license.required = isVet;
        contact.required = isVet;
        staffPosition.required = !isVet;
        const needsOtherPosition = !isVet && staffPosition.value === 'Other';
        otherStaffPositionField.hidden = !needsOtherPosition;
        otherStaffPosition.required = needsOtherPosition;
      }

      accountRole.addEventListener('change', syncAccountFields);
      staffPosition.addEventListener('change', syncAccountFields);
      syncAccountFields();

      /* ---------- Password requirements ---------- */
      const form = document.getElementById('create-user-form');
      const pw = document.getElementById('password');
      const tip = document.getElementById('pwTip');
      const passwordToggle = document.querySelector('.pw-toggle');

      passwordToggle.addEventListener('mousedown', (event) => event.preventDefault());
      passwordToggle.addEventListener('click', () => {
        const show = pw.type === 'password';
        const start = pw.selectionStart;
        const end = pw.selectionEnd;
        pw.type = show ? 'text' : 'password';
        passwordToggle.setAttribute('aria-pressed', String(show));
        passwordToggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        pw.focus({ preventScroll: true });
        try { pw.setSelectionRange(start, end); } catch (error) {}
      });

      function isStrong(p) {
        return p.length >= 7 && p.length <= 72 &&
               /[a-z]/.test(p) && /[A-Z]/.test(p) &&
               /[0-9]/.test(p) && /[^A-Za-z0-9]/.test(p);
      }

      function updatePasswordTip(showIfEmpty) {
        const weak = !isStrong(pw.value) && (pw.value !== '' || showIfEmpty);
        tip.classList.toggle('show', weak);
        pw.classList.toggle('invalid', weak);
      }

      pw.addEventListener('input', () => updatePasswordTip(false));
      pw.addEventListener('focus', () => updatePasswordTip(false));
      pw.addEventListener('blur', () => tip.classList.remove('show'));

      form.addEventListener('submit', (e) => {
        // The form is novalidate so only the popup shows for passwords; check the other required fields here.
        if (!form.checkValidity()) {
          e.preventDefault();
          form.reportValidity();
          return;
        }
        if (!isStrong(pw.value)) {
          e.preventDefault();
          updatePasswordTip(true);
          pw.focus();
        }
      });

    })();
  </script>
  <script src="../assets/JS/admin_notifications.js" defer></script>
  <script src="../assets/JS/admin-shell.js" defer></script>
  <style>
    .account-actions{display:grid;justify-items:center;gap:6px}
    .inline-form input[type=number]{width:90px;min-height:34px;padding:5px 8px;border:1px solid #bed4e5;border-radius:7px}
    .inline-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  </style>
</body>
</html>