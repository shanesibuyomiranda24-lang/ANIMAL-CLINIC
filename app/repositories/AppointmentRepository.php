<?php
final class AppointmentRepository
{
    public function __construct(private PDO $pdo) {}
    public function find(int $id): ?array
    {
        $st=$this->pdo->prepare('SELECT * FROM Appointments WHERE appointment_id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
}
