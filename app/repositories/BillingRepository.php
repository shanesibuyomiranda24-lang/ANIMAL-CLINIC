<?php
final class BillingRepository
{
    public function __construct(private PDO $pdo) {}
    public function invoice(int $appointmentId): ?array
    {
        $st=$this->pdo->prepare('SELECT * FROM v_invoices WHERE appointment_id=?');
        $st->execute([$appointmentId]);
        return $st->fetch() ?: null;
    }
}
