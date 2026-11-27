<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/passwords.php';
require_once __DIR__ . '/../includes/audit_log.php';

require_role(['admin']);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function password_requests_redirect(string $type, string $message): void
{
    $_SESSION['password_request_flash'] = ['type' => $type, 'message' => $message];
    header('Location: manage_users.php#password-recovery-requests');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    password_requests_redirect('error', 'Submit a valid password recovery review action.');
}

if (!csrf_valid(posted_string('csrf'))) {
    password_requests_redirect('error', 'Your session expired. Reload the page and try again.');
}

$action = posted_string('action');
$requestId = filter_var(posted_string('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!in_array($action, ['verify', 'approve', 'reject'], true) || $requestId === false) {
    password_requests_redirect('error', 'Choose a valid password recovery request.');
}

$verificationMethod = posted_string('verification_method');
$verificationResult = posted_string('verification_result');
$verificationNotes = trim(posted_string('verification_notes'));
$rejectionReason = trim(posted_string('rejection_reason'));
$temporaryPassword = posted_string('temporary_password');
$confirmPassword = posted_string('confirm_password');

if ($action === 'verify') {
    if (!in_array($verificationMethod, ['in_person', 'registered_contact', 'other'], true)
        || !in_array($verificationResult, ['Verified', 'Not verified'], true)
        || mb_strlen($verificationNotes) < 10
        || mb_strlen($verificationNotes) > 1000) {
        password_requests_redirect('error', 'Choose a verification method and record at least 10 characters of verification notes.');
    }
} elseif ($action === 'approve') {
    if (($passwordError = password_policy_error($temporaryPassword)) !== '') {
        password_requests_redirect('error', $passwordError);
    }
    if (!hash_equals($temporaryPassword, $confirmPassword)) {
        password_requests_redirect('error', 'The temporary password and confirmation do not match.');
    }
} elseif (mb_strlen($rejectionReason) < 8 || mb_strlen($rejectionReason) > 500) {
    password_requests_redirect('error', 'Enter a rejection reason between 8 and 500 characters.');
}

$pdo = null;
try {
    $pdo = db();
    $pdo->beginTransaction();
    $requestStatement = $pdo->prepare(
        'SELECT request_id, request_email, user_id, status, verification_result, verified_by_user_id
         FROM PasswordRecoveryRequests
         WHERE request_id = ?
         FOR UPDATE'
    );
    $requestStatement->execute([(int) $requestId]);
    $request = $requestStatement->fetch();
    if (!$request || $request['status'] !== 'Pending') {
        $pdo->rollBack();
        password_requests_redirect('error', 'That request is no longer pending.');
    }
    if (!empty($request['user_id']) && (int) $request['user_id'] === (int) $_SESSION['user_id']) {
        $pdo->rollBack();
        password_requests_redirect('error', 'A different administrator must review your recovery request.');
    }

    if ($action === 'verify') {
        if ($verificationResult === 'Verified' && empty($request['user_id'])) {
            $pdo->rollBack();
            password_requests_redirect('error', 'This request has no matching account and cannot be verified for reset.');
        }
        $pdo->prepare(
            "UPDATE PasswordRecoveryRequests
             SET verification_method = ?, verification_result = ?, verification_notes = ?,
                 verified_by_user_id = ?, verified_at = NOW()
             WHERE request_id = ? AND status = 'Pending'"
        )->execute([
            $verificationMethod,
            $verificationResult,
            $verificationNotes,
            (int) $_SESSION['user_id'],
            (int) $requestId,
        ]);
        audit_log_record(
            $pdo,
            (int) $_SESSION['user_id'],
            'password.recovery.identity_checked',
            'password_recovery_request',
            (int) $requestId,
            'Identity verification result: ' . $verificationResult . ' (' . $verificationMethod . ')'
        );
        $pdo->commit();
        password_requests_redirect('success', 'Identity verification result saved.');
    }

    if ($action === 'reject') {
        $pdo->prepare(
            "UPDATE PasswordRecoveryRequests
             SET status = 'Rejected', reviewed_by_user_id = ?, reviewed_at = NOW(),
                 rejection_reason = ?, completed_at = NOW()
             WHERE request_id = ? AND status = 'Pending'"
        )->execute([(int) $_SESSION['user_id'], $rejectionReason, (int) $requestId]);
        audit_log_record(
            $pdo,
            (int) $_SESSION['user_id'],
            'password.recovery.rejected',
            'password_recovery_request',
            (int) $requestId,
            'Password recovery request rejected'
        );
        $pdo->commit();
        password_requests_redirect('success', 'Password recovery request rejected.');
    }

    if ($request['verification_result'] !== 'Verified' || empty($request['verified_by_user_id'])) {
        $pdo->rollBack();
        password_requests_redirect('error', 'Review and verify the user’s identity before approving the request.');
    }
    if (empty($request['user_id'])) {
        $pdo->rollBack();
        password_requests_redirect('error', 'No account is linked to this request.');
    }

    $userStatement = $pdo->prepare(
        "SELECT user_id, email, status, archived_at FROM Users
         WHERE user_id = ?
         FOR UPDATE"
    );
    $userStatement->execute([(int) $request['user_id']]);
    $user = $userStatement->fetch();
    if (!$user || $user['status'] !== 'active' || $user['archived_at'] !== null) {
        $pdo->rollBack();
        password_requests_redirect('error', 'The account must be active and not archived before its password can be reset.');
    }
    if (normalize_email((string) $user['email']) !== normalize_email((string) $request['request_email'])) {
        $pdo->rollBack();
        password_requests_redirect('error', 'The registered email changed after the request was submitted. Submit a new request and verify identity again.');
    }

    $passwordUpdate = $pdo->prepare(
        'UPDATE Users
         SET password_hash = ?, must_change_password = 1
         WHERE user_id = ? AND status = \'active\' AND archived_at IS NULL'
    );
    $passwordUpdate->execute([password_hash($temporaryPassword, PASSWORD_DEFAULT), (int) $user['user_id']]);
    if ($passwordUpdate->rowCount() !== 1) {
        $pdo->rollBack();
        password_requests_redirect('error', 'The account changed before the password reset could be saved.');
    }
    $pdo->prepare(
        "UPDATE PasswordRecoveryRequests
         SET status = 'Approved', reviewed_by_user_id = ?, reviewed_at = NOW(), completed_at = NOW()
         WHERE request_id = ? AND status = 'Pending'"
    )->execute([(int) $_SESSION['user_id'], (int) $requestId]);
    audit_log_record(
        $pdo,
        (int) $_SESSION['user_id'],
        'user.password.reset',
        'user',
        (int) $user['user_id'],
        'Password recovery approved; temporary password issued and change required at next login'
    );
    audit_log_record(
        $pdo,
        (int) $_SESSION['user_id'],
        'password.recovery.approved',
        'password_recovery_request',
        (int) $requestId,
        'Verified password recovery request approved'
    );
    $pdo->commit();
    $_SESSION['user_password_reset_flash'] = [
        'email' => (string) $user['email'],
        'password' => $temporaryPassword,
    ];
    password_requests_redirect('success', 'Request approved. Share the temporary password privately; the user must change it at next login.');
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Password recovery review failed: ' . $exception->getMessage());
    password_requests_redirect('error', 'The password recovery request could not be updated. Please try again.');
}
