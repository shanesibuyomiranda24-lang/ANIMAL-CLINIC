<?php
// client/appointments.php - upcoming and past appointments, cancel
require_once __DIR__ . '/client_layout.php';
require_once __DIR__ . '/../includes/audit_log.php';
$c = client_boot();
$pdo = $c['pdo'];

$clientAction = posted_string('action');
if (!$c['dbError'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($clientAction, ['cancel', 'confirm_pickup'], true)) {
    $id = filter_var(posted_string('appointment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if (!csrf_valid(posted_string('csrf')) || $id === false) {
            throw new InvalidArgumentException('Invalid appointment action.');
        }

        $pdo->beginTransaction();
        if ($clientAction === 'confirm_pickup') {
            $pickupStatement = $pdo->prepare(
                "SELECT a.appointment_id, a.pickup_ready_at, a.picked_up_at, p.pet_name,
                        EXISTS(
                            SELECT 1 FROM AppointmentServices aps
                            JOIN Services s ON s.service_id = aps.service_id
                            WHERE aps.appointment_id = a.appointment_id
                              AND s.service_category = 'Grooming & Hygiene'
                        ) AS has_grooming_services,
                        EXISTS(
                            SELECT 1 FROM AppointmentServices aps
                            JOIN Services s ON s.service_id = aps.service_id
                            WHERE aps.appointment_id = a.appointment_id
                              AND s.service_category <> 'Grooming & Hygiene'
                        ) AS has_other_services
                 FROM Appointments a
                 JOIN Pets p ON p.pet_id = a.pet_id
                 WHERE a.appointment_id = ? AND p.owner_id = ?
                   AND a.pickup_ready_at IS NOT NULL AND a.picked_up_at IS NULL
                   AND a.status NOT IN ('Cancelled','Rejected')
                   AND EXISTS(
                       SELECT 1 FROM AppointmentServices aps
                       JOIN Services s ON s.service_id = aps.service_id
                       WHERE aps.appointment_id = a.appointment_id
                         AND s.service_category = 'Grooming & Hygiene'
                   )
                 FOR UPDATE"
            );
            $pickupStatement->execute([(int) $id, (int) $c['ownerId']]);
            $appointment = $pickupStatement->fetch();
            if (!$appointment || !(int) $appointment['has_grooming_services']) {
                throw new InvalidArgumentException('This appointment is not awaiting pickup or was already confirmed.');
            }

            $pickupUpdate = (int) $appointment['has_other_services']
                ? $pdo->prepare(
                    "UPDATE Appointments SET picked_up_at = NOW()
                     WHERE appointment_id = ? AND pickup_ready_at IS NOT NULL AND picked_up_at IS NULL
                       AND EXISTS(
                           SELECT 1 FROM AppointmentServices aps
                           JOIN Services s ON s.service_id = aps.service_id
                           WHERE aps.appointment_id = Appointments.appointment_id
                             AND s.service_category = 'Grooming & Hygiene'
                       )"
                )
                : $pdo->prepare(
                    "UPDATE Appointments SET picked_up_at = NOW(), status = 'Complete'
                     WHERE appointment_id = ? AND pickup_ready_at IS NOT NULL AND picked_up_at IS NULL
                       AND EXISTS(
                           SELECT 1 FROM AppointmentServices aps
                           JOIN Services s ON s.service_id = aps.service_id
                           WHERE aps.appointment_id = Appointments.appointment_id
                             AND s.service_category = 'Grooming & Hygiene'
                       )"
                );
            $pickupUpdate->execute([(int) $id]);
            if ($pickupUpdate->rowCount() !== 1) {
                throw new InvalidArgumentException('Pickup was already confirmed for this appointment.');
            }
            audit_log_record($pdo, (int) $c['userId'], 'appointment.picked_up', 'appointment', (int) $id, 'Pickup confirmed by pet owner.');
            $pdo->commit();
            header('Location: appointments.php?ok=picked_up');
            exit;
        }

        $appointmentStatement = $pdo->prepare(
            "SELECT a.appointment_id, a.vet_id, a.status, p.owner_id, p.pet_name
             FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id
             WHERE a.appointment_id = ? AND p.owner_id = ?
               AND a.status IN ('Pending','Scheduled')
             FOR UPDATE"
        );
        $appointmentStatement->execute([(int) $id, (int) $c['ownerId']]);
        $appointment = $appointmentStatement->fetch();
        if (!$appointment) {
            throw new InvalidArgumentException('That appointment cannot be cancelled.');
        }

        $pdo->prepare("UPDATE Appointments SET status = 'Cancelled' WHERE appointment_id = ?")
            ->execute([(int) $id]);
        audit_log_record($pdo, (int) $c['userId'], 'appointment.cancelled', 'appointment', (int) $id, 'Cancelled by pet owner.');
        $notice = 'Appointment #' . (int) $id . ' for ' . $appointment['pet_name'] . ' was cancelled by the pet owner.';
        $recipients = array_merge(
            notification_role_users($pdo, 'staff'),
            notification_role_users($pdo, 'admin')
        );
        notification_send_users(
            $pdo,
            $recipients,
            'appointment',
            'Appointment cancelled by client',
            $notice,
            'staff/appointments.php',
            'client_cancelled_' . (int) $id
        );
        if ($appointment['status'] !== 'Pending') {
            notification_send_users(
                $pdo,
                notification_vet_users($pdo, $appointment['vet_id'] ? (int) $appointment['vet_id'] : null),
                'appointment',
                'Appointment cancelled',
                $notice,
                'vet/appointments.php',
                'client_cancelled_vet_' . (int) $id
            );
        }
        notification_send_users(
            $pdo,
            notification_owner_users($pdo, (int) $c['ownerId']),
            'appointment',
            'Appointment cancelled',
            'Your appointment for ' . $appointment['pet_name'] . ' has been cancelled.',
            'client/appointments.php',
            'client_cancelled_owner_' . (int) $id
        );
        $pdo->commit();
        header('Location: appointments.php?ok=cancelled');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Client appointment action failed: ' . $ex->getMessage());
        header('Location: appointments.php?error=' . ($clientAction === 'confirm_pickup' ? 'pickup_failed' : 'cancel_failed'));
    }
    exit;
}

$upcomingVeterinary = $c['dbError'] ? [] : client_upcoming($pdo, $c['ownerId'], 20, 'veterinary');
$upcomingGrooming = $c['dbError'] ? [] : client_upcoming($pdo, $c['ownerId'], 20, 'grooming');
$pastVeterinary = $c['dbError'] ? [] : client_past($pdo, $c['ownerId'], 50, 'veterinary');
$pastGrooming = $c['dbError'] ? [] : client_past($pdo, $c['ownerId'], 50, 'grooming');
client_header($c, 'Appointments', 'Keep upcoming visits and appointment history organized', 'appointments');
?>
    <style>
      .appointment-service-preview{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .appointment-service-view-all{margin-top:4px}
      .appointment-datetime-cell{white-space:normal!important;line-height:1.35}
      .appointment-datetime-date,.appointment-datetime-cell time{display:block;white-space:nowrap}
    .appointment-groomer-cell{white-space:normal;overflow-wrap:anywhere}
    .pickup-ready-services,.pickup-ready-time{display:block;margin-top:4px;line-height:1.35;white-space:normal}
      .appointment-services-dialog{width:min(36rem,calc(100vw - 2rem));max-height:80vh;padding:1.25rem;color:var(--ink);border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 50px rgba(0,46,74,.25)}
      .appointment-services-dialog::backdrop{background:rgba(0,28,46,.55)}
      .appointment-services-dialog h2{margin:0 0 .75rem;font-size:1.1rem}
      .appointment-services-dialog p{max-height:55vh;margin:0 0 1rem;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}
      .appointment-services-dialog form{display:flex;justify-content:flex-end}
    </style>
        <?php if (($_GET['ok'] ?? '') === 'picked_up'): ?><div class="notice success" role="status">Pickup confirmed. The grooming appointment is now in Past appointments.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'pickup_failed'): ?><div class="notice error" role="alert">Pickup could not be confirmed. Refresh the page and try again.</div><?php endif; ?>
        <section class="panel appointment-panel">
          <div class="panel-header"><h2>Upcoming veterinary appointments</h2><span class="data-count"><?= count($upcomingVeterinary) ?> visits</span></div>
          <div class="panel-body appointment-panel-body">
            <?php client_search('upcoming-vet-search', 'Find a veterinary appointment', 'Search pet, service or status…', 'upcoming-vet-body', 'upcoming-vet-count', 'upcoming-vet-empty'); ?>
            <?php appt_table($upcomingVeterinary, true, 'upcoming-vet-body', 'upcoming-vet-empty', 'veterinary'); ?>
          </div>
        </section>
        <section class="panel appointment-panel">
          <div class="panel-header"><h2>Upcoming grooming appointments</h2><span class="data-count"><?= count($upcomingGrooming) ?> visits</span></div>
          <div class="panel-body appointment-panel-body">
            <?php client_search('upcoming-grooming-search', 'Find a grooming appointment', 'Search pet, service or status…', 'upcoming-grooming-body', 'upcoming-grooming-count', 'upcoming-grooming-empty'); ?>
            <?php appt_table($upcomingGrooming, true, 'upcoming-grooming-body', 'upcoming-grooming-empty', 'grooming'); ?>
          </div>
        </section>
        <section class="panel appointment-panel">
          <div class="panel-header"><h2>Past veterinary appointments</h2><span class="data-count"><?= count($pastVeterinary) ?> visits</span></div>
          <div class="panel-body appointment-panel-body">
            <?php client_search('past-vet-search', 'Find a past veterinary visit', 'Search pet, service or status…', 'past-vet-body', 'past-vet-count', 'past-vet-empty'); ?>
            <?php appt_table($pastVeterinary, false, 'past-vet-body', 'past-vet-empty', 'veterinary'); ?>
          </div>
        </section>
        <section class="panel appointment-panel">
          <div class="panel-header"><h2>Past grooming appointments</h2><span class="data-count"><?= count($pastGrooming) ?> visits</span></div>
          <div class="panel-body appointment-panel-body">
            <?php client_search('past-grooming-search', 'Find a past grooming visit', 'Search pet, service or status…', 'past-grooming-body', 'past-grooming-count', 'past-grooming-empty'); ?>
            <?php appt_table($pastGrooming, false, 'past-grooming-body', 'past-grooming-empty', 'grooming'); ?>
          </div>
        </section>
                <dialog class="appointment-services-dialog" id="appointment-services-dialog" aria-labelledby="appointment-services-title">
                    <h2 id="appointment-services-title">Appointment services</h2>
                    <p id="appointment-services-content"></p>
                    <form method="dialog"><button class="button secondary" type="submit">Close</button></form>
                </dialog>
                <script>
                    (function () {
                        var dialog = document.getElementById('appointment-services-dialog');
                        var content = document.getElementById('appointment-services-content');
                        document.querySelectorAll('[data-view-services]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                content.textContent = button.dataset.viewServices || 'To be confirmed';
                                dialog.showModal();
                            });
                        });
                    })();
                </script>
<?php client_footer($c);