<?php
final class PetRepository
{
    public function __construct(private PDO $pdo) {}
    public function ownedBy(int $ownerId): array
    {
        $st=$this->pdo->prepare('SELECT * FROM Pets WHERE owner_id=? ORDER BY pet_name');
        $st->execute([$ownerId]);
        return $st->fetchAll();
    }
}
