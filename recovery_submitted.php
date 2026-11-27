<?php
require_once __DIR__ . '/includes/auth.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recovery Request Submitted | Animal Clinic</title>
  <link rel="stylesheet" href="assets/css/auth-flow.css">
</head>
<body class="auth-page">
  <main class="welcome-card">
    <section class="intro" aria-label="Animal Clinic introduction">
      <h1><img src="assets/images/Healthy%20Pets,%20Happier%20Lives.png" alt="Healthy pets, happier lives"></h1>
      <p>Book appointments, keep track of medical records and vaccinations, and manage bills in one place.</p>
      <img class="pets" src="assets/images/pet.png" alt="A happy dog and cat">
    </section>
    <section class="actions" aria-labelledby="submitted-title">
      <div class="brand"><img src="assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic logo"></div>
      <h2 id="submitted-title">Request Submitted</h2>
      <p class="subtitle">Your password recovery request has been submitted. Please contact the clinic administrator for assistance.</p>
      <div class="recovery-notice" role="status">The administrator will review the request and verify your identity before approving a password reset.</div>
      <a class="button" href="login.php">Back to Login</a>
    </section>
  </main>
</body>
</html>
