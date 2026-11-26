<?php
// logout.php - end the session and return to the public landing page
require_once __DIR__ . '/includes/auth.php';

destroy_auth_session();

header('Location: index.php');
exit;