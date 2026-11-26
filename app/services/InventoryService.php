<?php
final class InventoryService
{
    public static function adjust(PDO $pdo, int $inventoryId, int $changeQty, string $reason, int $userId): bool
    {
        $pdo->beginTransaction();
        $st = $pdo->prepare('UPDATE Inventory SET quantity=quantity+? WHERE inventory_id=? AND quantity+?>=0');
        $ok = $st->execute([$changeQty, $inventoryId, $changeQty]) && $st->rowCount() === 1;
        if (!$ok) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare('INSERT INTO InventoryTransactions(inventory_id,change_qty,reason,created_by_user_id) VALUES(?,?,?,?)')
            ->execute([$inventoryId, $changeQty, mb_substr($reason, 0, 60), $userId]);
        $pdo->commit();
        return true;
    }
}
