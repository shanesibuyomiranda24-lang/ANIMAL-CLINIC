<?php
final class BillingService
{
    public static function recordPayment(PDO $pdo, int $invoiceId, float $amount, string $method, int $userId, ?string $reference = null): int
    {
        $pdo->beginTransaction();
        $st = $pdo->prepare('CALL sp_record_payment(?,?,?,?,?)');
        $st->execute([$invoiceId, $amount, $method, $userId, $reference]);
        $result = $st->fetch();
        $pdo->commit();
        return (int) ($result['payment_id'] ?? 0);
    }
}
