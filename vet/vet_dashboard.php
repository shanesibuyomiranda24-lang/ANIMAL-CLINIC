<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_once __DIR__ . '/../includes/pet_photos.php';
require_role(['vet']);

$pdo = db();
$vetStatement = $pdo->prepare('SELECT vet_id FROM Users WHERE user_id = ?');
$vetStatement->execute([(int) $_SESSION['user_id']]);
$vetId = (int) $vetStatement->fetchColumn();

role_render('vet', ['dashboard'], 'Veterinarian Dashboard', 'Assigned patients and consultation activity', function (array $context) use ($pdo, $vetId): void {
    $todayStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM Appointments
         WHERE vet_id = ? AND appointment_date >= CURRENT_DATE
           AND appointment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)'
    );
    $todayStatement->execute([$vetId]);
    $todayCount = (int) $todayStatement->fetchColumn();

    $waitingStatement = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE vet_id = ? AND status = 'Check-in'");
    $waitingStatement->execute([$vetId]);
    $waiting = (int) $waitingStatement->fetchColumn();

    $activeStatement = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE vet_id = ? AND status = 'In Consultation'");
    $activeStatement->execute([$vetId]);
    $active = (int) $activeStatement->fetchColumn();

    $completeStatement = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE vet_id = ? AND status = 'Complete'");
    $completeStatement->execute([$vetId]);
    $complete = (int) $completeStatement->fetchColumn();

    $appointmentStatement = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.status, p.pet_id, p.pet_name, p.photo_updated_at,
                o.full_name AS owner_name
         FROM Appointments a
         JOIN Pets p ON p.pet_id = a.pet_id
         JOIN PetOwners o ON o.owner_id = p.owner_id
         WHERE a.vet_id = ?
           AND a.appointment_date >= CURRENT_DATE
           AND a.appointment_date < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
           AND a.status <> 'Cancelled'
         ORDER BY a.appointment_date"
    );
    $appointmentStatement->execute([$vetId]);
    $appointments = $appointmentStatement->fetchAll();
    ?>
    <section class="clinic-banner" aria-label="Welcome">
      <div class="clinic-banner-copy">
        <p class="clinic-banner-eyebrow">Veterinarian dashboard</p>
        <h2>Welcome back, <?= e($_SESSION['full_name'] ?? 'Veterinarian') ?>!</h2>
        <p>Review your patients, consultations and schedule for the day.</p>
        <div class="clinic-banner-actions">
          <a class="button light" href="appointments.php">Today's appointments</a>
          <a class="button ghost" href="schedules.php">My schedule</a>
        </div>
      </div>
    </section>
    <section class="stat-grid">
      <article class="stat-card" style="--accent:#0284c7"><span class="stat-label">Today's patients</span><strong class="stat-value"><?= $todayCount ?></strong></article>
      <article class="stat-card" style="--accent:#6550a4"><span class="stat-label">Waiting</span><strong class="stat-value"><?= $waiting ?></strong></article>
      <article class="stat-card" style="--accent:#008b78"><span class="stat-label">In consultation</span><strong class="stat-value"><?= $active ?></strong></article>
      <article class="stat-card" style="--accent:#225d40"><span class="stat-label">Completed visits</span><strong class="stat-value"><?= $complete ?></strong></article>
    </section>
    <section class="panel">
      <div class="panel-header"><h2>Today's assigned patients</h2><span class="data-count"><?= count($appointments) ?> shown</span></div>
      <div class="panel-body table-search-toolbar">
        <label class="field">Search today's visits
          <input type="search" data-table-search placeholder="Pet, owner, time or status" autocomplete="off">
        </label>
        <span class="table-search-count" data-table-search-count aria-live="polite"></span>
      </div>
      <div class="table-wrap data-table-card">
        <table class="records-table">
          <colgroup><col style="width:8rem"><col style="width:30%"><col style="width:30%"><col></colgroup>
          <thead><tr><th>Time</th><th>Pet</th><th>Owner</th><th>Status</th></tr></thead>
          <tbody>
            <?php if ($appointments): ?>
              <?php foreach ($appointments as $index => $appointment): ?>
                <?php $photoUrl = pet_photo_url($appointment['photo_updated_at'] ?? null, (int) $appointment['pet_id']); ?>
                <tr<?= $index % 2 ? ' class="alt"' : '' ?>>
                  <td data-label="Time"><?= e(date('g:i A', strtotime($appointment['appointment_date']))) ?></td>
                  <td class="vet-pet-cell" data-label="Pet">
                    <?php if ($photoUrl !== ''): ?>
                      <img class="vet-pet-thumb" src="<?= e($photoUrl) ?>" alt="Photo of <?= e($appointment['pet_name']) ?>" loading="lazy">
                    <?php else: ?>
                      <span class="vet-pet-thumb vet-pet-thumb-empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($appointment['pet_name'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <strong><?= e($appointment['pet_name']) ?></strong>
                  </td>
                  <td data-label="Owner"><?= e($appointment['owner_name']) ?></td>
                  <td data-label="Status"><span class="status <?= e(role_status_class($appointment['status'])) ?>"><?= e($appointment['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr class="empty-row"><td colspan="4"><div class="empty-state-box"><strong>No patients assigned today</strong><small>New assignments and schedule changes will appear here and in your notifications.</small></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php
});
