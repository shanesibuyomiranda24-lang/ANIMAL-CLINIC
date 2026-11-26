<?php
final class InventoryRepository
{
    public function __construct(private PDO $pdo) {}
    public function find(int $id): ?array
    {
        $st=$this->pdo->prepare('SELECT * FROM Inventory WHERE inventory_id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
}
