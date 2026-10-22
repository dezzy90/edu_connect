<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use InvalidArgumentException;

final class PushAuthentication
{
    public static function login(string $username, string $password, string $salt, string $challenge, int $iterations, string $profile): string
    {
        $algorithm = match ($profile) {
            'sha256' => 'sha256',
            'sha1-3.16.1' => 'sha1',
            default => throw new InvalidArgumentException('Unsupported pinned authentication profile.'),
        };
        if ($iterations < 500 || $iterations > 5000) {
            throw new InvalidArgumentException('Invalid PBKDF2 iteration count.');
        }

        // 64 derived bytes -> 128 lowercase hex characters, confirmed by the C# demo.
        return hash_pbkdf2($algorithm, hash('sha256', $username.$salt.$password).$challenge, $salt, $iterations, 128);
    }

    public static function request(string $username, string $password, string $salt, string $challenge): string
    {
        return hash('sha256', hash('sha256', $username.$salt.$password).$challenge);
    }

    public static function challenge(): string
    {
        return bin2hex(random_bytes(32));
    }
}
