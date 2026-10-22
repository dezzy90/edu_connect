<?php

declare(strict_types=1);

namespace Tests\Support\Biometrics\Hikvision;

use Closure;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/** Independent fixture client. Calls the real Laravel route through an injected test transport. */
final class PushTerminalSimulator
{
    public ?string $challenge = null;

    public ?string $salt = null;

    public function __construct(private readonly Closure $transport, private readonly string $username,
        private readonly string $password, private readonly string $profile = 'sha256') {}

    public function connect(): TestResponse
    {
        $auth = ($this->transport)('AuthInfo', ['data' => ['securityVersion' => [3, 4]]], []);
        if ($auth->status() !== 200) {
            return $auth;
        }
        $data = $auth->json('data');
        $this->salt = $data['salt'];
        $input = hash('sha256', $this->username.$this->salt.$this->password).$data['challenge'];
        $login = ($this->transport)('Login', ['data' => ['username' => $this->username,
            'loginPassword' => hash_pbkdf2($this->profile === 'sha1-3.16.1' ? 'sha1' : 'sha256',
                $input, $this->salt, $data['iterations'], 128)]], []);
        $this->challenge = $login->headers->get('My-Custom-Challenge');

        return $login;
    }

    public function send(string $operation, array $body = [], bool $loseResponse = false): TestResponse
    {
        if ($this->salt === null || $this->challenge === null) {
            throw new RuntimeException('Fixture terminal must login first.');
        }
        $auth = hash('sha256', hash('sha256', $this->username.$this->salt.$this->password).$this->challenge);
        $response = ($this->transport)($operation, $body, ['My-Custom-Auth' => $auth]);
        if (! $loseResponse && $response->headers->has('My-Custom-Challenge')) {
            $this->challenge = $response->headers->get('My-Custom-Challenge');
        }

        return $response;
    }

    public static function scan(int $serial = 101, string $person = '0001', string $uuid = 'scan-001', array $overrides = []): array
    {
        $event = ['eventType' => 'AccessControllerEvent', 'eventState' => 'active',
            'dateTime' => '2026-10-10T07:20:00+01:00',
            'AccessControllerEvent' => array_replace(['majorEventType' => 5, 'subEventType' => 75,
                'serialNo' => $serial, 'employeeNoString' => $person], $overrides)];

        return ['eventNum' => 1, 'eventList' => [['UUID' => $uuid, 'dataFormat' => 'jsonData',
            'data' => base64_encode(json_encode($event, JSON_THROW_ON_ERROR))]]];
    }
}
