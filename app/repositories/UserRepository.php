<?php
final class UserRepository
{
    public function __construct(private PDO $pdo) {}
    public function findById(int $id): ?array
    {
        $st=$this->pdo->prepare('SELECT * FROM Users WHERE user_id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
}
