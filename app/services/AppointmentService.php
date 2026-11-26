<?php
final class AppointmentService
{
    public static function cancel(PDO $pdo, int $appointmentId, int $userId, string $reason = ''): bool
    {
        $pdo->beginTransaction();
        $st = $pdo->prepare("UPDATE Appointments SET status='Cancelled', cancellation_reason=? WHERE appointment_id=? AND status IN ('Pending','Scheduled','Waiting','Check-in')");
        $ok = $st->execute([$reason !== '' ? mb_substr($reason, 0, 255) : null, $appointmentId]) && $st->rowCount() === 1;
        if (!$ok) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare("INSERT INTO ActivityLog(user_id,action,entity,entity_id,details) VALUES(?, 'appointment.cancelled','appointment',?,?)")
            ->execute([$userId, $appointmentId, 'Appointment cancelled']);
        $pdo->commit();
        return true;
    }
}
