<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['staff']);

header('Location: ../groomer/schedules.php');
exit;
