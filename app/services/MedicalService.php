<?php
final class MedicalService
{
    public static function saveRecord(PDO $pdo, int $appointmentId, int $vetUserId, string $diagnosis, string $treatment, string $notes): void
    {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT record_id FROM MedicalRecords WHERE appointment_id=?');
        $st->execute([$appointmentId]);
        if ($st->fetch()) {
            $pdo->prepare('UPDATE MedicalRecords SET diagnosis=?,treatment=?,recommendations=?,created_by_user_id=?,is_finalized=1,finalized_at=NOW() WHERE appointment_id=?')
                ->execute([$diagnosis, $treatment ?: null, $notes ?: null, $vetUserId, $appointmentId]);
        } else {
            $pdo->prepare('INSERT INTO MedicalRecords(appointment_id,created_by_user_id,diagnosis,treatment,recommendations,is_finalized,finalized_at) VALUES(?,?,?,?,?,1,NOW())')
                ->execute([$appointmentId, $vetUserId, $diagnosis, $treatment ?: null, $notes ?: null]);
        }
        $pdo->commit();
    }
}
