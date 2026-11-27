<?php

function password_policy_error(string $password): string
{
    $length = mb_strlen($password, 'UTF-8');
    if ($length < 7) {
        return 'Password must be at least 7 characters.';
    }
    if ($length > 14) {
        return 'Password must not exceed 14 characters.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include a lowercase letter.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include an uppercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include a number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include a special character.';
    }

    return '';
}

function password_policy_description(): string
{
    return 'Use 7–14 characters with an uppercase letter, a lowercase letter, a number, and a special character.';
}

function generate_temporary_password(): string
{
    $characterSets = [
        'abcdefghijkmnopqrstuvwxyz',
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        '23456789',
        '!@#$%&*?',
    ];
    $passwordCharacters = [];
    foreach ($characterSets as $set) {
        $passwordCharacters[] = $set[random_int(0, strlen($set) - 1)];
    }

    $allCharacters = implode('', $characterSets);
    while (count($passwordCharacters) < 14) {
        $passwordCharacters[] = $allCharacters[random_int(0, strlen($allCharacters) - 1)];
    }
    for ($index = count($passwordCharacters) - 1; $index > 0; $index--) {
        $swapIndex = random_int(0, $index);
        [$passwordCharacters[$index], $passwordCharacters[$swapIndex]] =
            [$passwordCharacters[$swapIndex], $passwordCharacters[$index]];
    }

    return implode('', $passwordCharacters);
}
