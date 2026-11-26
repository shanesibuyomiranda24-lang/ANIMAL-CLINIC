<?php
require_once __DIR__ . '/auth.php';

function money_php(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function format_clinic_date(?string $value, string $format = 'M j, Y'): string
{
    if (!$value || strtotime($value) === false) {
        return '—';
    }
    return date($format, strtotime($value));
}

function valid_phone(string $phone): bool
{
    return $phone === '' || (preg_match('/^[0-9+\-\s()]{7,20}$/', $phone) === 1);
}

function valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function db_rows(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_scalar(PDO $pdo, string $sql, array $params = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}
