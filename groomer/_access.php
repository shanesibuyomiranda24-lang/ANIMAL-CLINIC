<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';

function groomer_require_portal_access(): void
{
    require_role(['staff']);

    $statement = db()->prepare(
        "SELECT s.`role`
         FROM Users u
         JOIN Staff s ON s.staff_id = u.staff_id
         WHERE u.user_id = ? AND u.`role` = 'staff' AND u.status = 'active'"
    );
    $statement->execute([(int) $_SESSION['user_id']]);
    if ($statement->fetchColumn() !== 'Groomer') {
        http_response_code(403);
        exit('Groomer access required.');
    }
}
