<?php

use App\Services\Sms\ScmcOtpSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config([
        'services.scmc.enabled' => true,
        'services.scmc.api_key' => 'sk_test_not_a_real_key',
        'services.scmc.otp_template_id' => 'b045f9d9-ba98-4ba1-9884-faab98aa169c',
        'services.scmc.app_name' => 'EduConnect',
        'services.scmc.timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('sends the supplied template and preserves leading zeroes in a six-digit code', function (): void {
    Http::fake(['api.scmchost.com/*' => Http::response(['success' => true, 'data' => ['sent' => 1]])]);

    app(ScmcOtpSender::class)->send('+237677223344', '012345', '5 minutes', 'challenge-001');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.scmchost.com/messages/send'
        && $request->method() === 'POST'
        && $request->hasHeader('X-API-Key', 'sk_test_not_a_real_key')
        && $request->hasHeader('Content-Type', 'application/json')
        && ! $request->hasHeader('Authorization')
        && $request->data() === [
            'template_id' => 'b045f9d9-ba98-4ba1-9884-faab98aa169c',
            'recipients' => ['+237677223344'],
            'variables' => ['NOM_APP' => 'EduConnect', 'CODE' => '012345', 'TEMPS' => '5 minutes'],
        ]);
});

it('accepts a string sent count and a numeric-only template validity value', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['sent' => '1']])]);
    app(ScmcOtpSender::class)->send('+237677223344', '012345', '5', 'challenge-001');
    Http::assertSent(fn (Request $request): bool => $request['variables']['TEMPS'] === '5');
});

it('reuses the same idempotency key for retries but isolates new challenges apps and environments', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['sent' => 1]])]);
    $sender = app(ScmcOtpSender::class);
    $sender->send('+237677223344', '012345', '5', 'challenge-001');
    $sender->send('+237677223344', '012345', '5', 'challenge-001');
    $sender->send('+237677223344', '012345', '5', 'challenge-002');
    config(['services.scmc.app_name' => 'EduAdmin']);
    $sender->send('+237677223344', '012345', '5', 'challenge-001');
    config(['app.env' => 'production']);
    $sender->send('+237677223344', '012345', '5', 'challenge-001');

    $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->all();
    expect($keys[0])->toBe($keys[1])->toStartWith('otp-')->not->toContain('237677223344');
    expect(count(array_unique($keys)))->toBe(4);
});

it('makes no network request when disabled', function (): void {
    config(['services.scmc.enabled' => false]);
    Http::fake();
    expect(fn () => app(ScmcOtpSender::class)->send('+237677223344', '012345', '5', 'challenge-001'))
        ->toThrow(RuntimeException::class, 'SCMC OTP delivery is disabled.');
    Http::assertNothingSent();
});

it('rejects incomplete configuration before sending', function (string $setting, mixed $value): void {
    config([$setting => $value]);
    Http::fake();
    expect(fn () => app(ScmcOtpSender::class)->send('+237677223344', '012345', '5', 'challenge-001'))
        ->toThrow(RuntimeException::class, 'SCMC OTP configuration is incomplete or invalid.');
    Http::assertNothingSent();
})->with([
    ['services.scmc.api_key', ''],
    ['services.scmc.api_key', 'sk_VOTRE_CLE_API'],
    ['services.scmc.api_key', "sk_bad\r\nInjected: value"],
    ['services.scmc.otp_template_id', 'invalid'],
    ['services.scmc.app_name', ''],
    ['services.scmc.app_name', "App\nName"],
]);

it('rejects invalid inputs without sending', function (string $phone, string $code, string $validity, string $id): void {
    Http::fake();
    expect(fn () => app(ScmcOtpSender::class)->send($phone, $code, $validity, $id))
        ->toThrow(InvalidArgumentException::class, 'Invalid OTP delivery parameters.');
    Http::assertNothingSent();
})->with([
    ['677223344', '012345', '5', 'challenge-001'],
    ['+237677223344', '12345', '5', 'challenge-001'],
    ['+237677223344', '1234567', '5', 'challenge-001'],
    ['+237677223344', 'abcdef', '5', 'challenge-001'],
    ['+237677223344', "012345\n", '5', 'challenge-001'],
    ['+237677223344', '012345', '', 'challenge-001'],
    ['+237677223344', '012345', "5\nminutes", 'challenge-001'],
    ['+237677223344', '012345', '5', ''],
    ['+237677223344', '012345', '5', "challenge-001\n"],
]);

it('does not treat an error malformed reply or zero sent count as delivery success', function (mixed $body, int $status): void {
    Http::fake(['*' => Http::response($body, $status)]);
    expect(fn () => app(ScmcOtpSender::class)->send('+237677223344', '012345', '5', 'challenge-001'))
        ->toThrow(RuntimeException::class, 'SCMC did not confirm acceptance of the OTP message.');
    Http::assertSentCount(1);
})->with([
    [['success' => false, 'error' => 'Echoed secret: 012345 sk_test_not_a_real_key'], 200],
    [['success' => true, 'data' => ['sent' => 0]], 200],
    [['success' => true, 'data' => ['sent' => 2]], 200],
    [['success' => true, 'data' => ['sent' => true]], 200],
    [['success' => 'false', 'data' => ['sent' => 1]], 200],
    [['success' => true], 200],
    [['success' => true, 'data' => 'bad'], 200],
    ['not-json', 200],
    [['success' => true, 'data' => ['sent' => 1]], 401],
    [['success' => true, 'data' => ['sent' => 1]], 429],
    [['success' => true, 'data' => ['sent' => 1]], 500],
]);

it('does not follow redirects or expose a raw HTTP exception', function (): void {
    $calls = 0;
    Http::fake(function (Request $request, array $options) use (&$calls) {
        $calls++;
        expect($options['allow_redirects'])->toBeFalse();
        throw new ConnectionException('Sensitive provider error: 012345 sk_test_not_a_real_key');
    });

    try {
        app(ScmcOtpSender::class)->send('+237677223344', '012345', '5', 'challenge-001');
        $this->fail('Expected a sanitized transport exception.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('SCMC OTP delivery could not be confirmed.');
        expect($exception->getPrevious())->toBeNull();
    }

    expect($calls)->toBe(1);
});

it('rejects an HTTP redirect as an unconfirmed send', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://example.com'])]);
    expect(fn () => app(ScmcOtpSender::class)->send('+237677223344', '012345', '5', 'challenge-001'))
        ->toThrow(RuntimeException::class, 'SCMC did not confirm acceptance of the OTP message.');
    Http::assertSentCount(1);
});
