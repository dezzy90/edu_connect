<?php

use App\Services\Biometrics\Hikvision\PushAuthentication;

it('matches independently generated PBKDF2 and custom auth vectors', function (): void {
    $salt = 'e43edaeac631e5db1c331046294a3aee6787bea7d0a72b36ff405b83e4e6f9f3';
    $challenge = '20678a373b5bc316149a7027422d1e8820678a373b5bc316149a7027422d1e88';
    expect(PushAuthentication::login('admin', 'aksj13579', $salt, $challenge, 5000, 'sha256'))
        ->toBe('08c0da14b549767c53d61d4bac2c4a216d7f0fadd116fd1243d1f7364754910c54c899019ab0e3e85a9c5074df40e0d2353454cffaa1a499b3c39929f2dc66a2');
    expect(PushAuthentication::login('admin', 'aksj13579', $salt, $challenge, 5000, 'sha1-3.16.1'))
        ->toBe('213c3a3f6d5145c4e7392a6f31951d0fbae141db48f13c3a96425b9e30b963cd17d0d9f4568b8845fba0e41b4348e859317c6280282ba9200ee2ef57e98a69f0');
    expect(PushAuthentication::request('admin', 'aksj13579', $salt, $challenge))
        ->toBe('08b2889589f753866ad66016fd664f1506c0882b08935a9f5d8e74dcf796c72b');
});

it('does not negotiate an unknown or unpinned authentication profile', function (): void {
    PushAuthentication::login('admin', 'test', 'salt', 'challenge', 5000, 'auto');
})->throws(InvalidArgumentException::class);

it('bounds PBKDF2 computation', function (int $iterations): void {
    PushAuthentication::login('admin', 'test', 'salt', 'challenge', $iterations, 'sha256');
})->with([0, 499, 5001, 100000])->throws(InvalidArgumentException::class);
