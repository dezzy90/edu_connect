<?php

use App\Services\Auth\MobileOtpAuth;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$root = realpath(dirname(__DIR__, 2).'/storage/framework/testing');
$path = realpath($input['database'] ?? '');
if (getenv('APP_ENV') !== 'testing' || ! $root || ! $path
    || dirname($path) !== $root || ! preg_match('/^mobile-otp-race-[a-f0-9-]+\.sqlite$/D', basename($path))) {
    exit(2);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.key' => 'base64:'.base64_encode(str_repeat('e', 32)),
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $path,
    'database.connections.sqlite.busy_timeout' => 5000,
    'cache.stores.database.connection' => 'sqlite',
    'cache.stores.database.lock_connection' => 'sqlite',
    'cache.stores.database.table' => 'cache',
    'cache.stores.database.lock_table' => 'cache_locks',
    'cache.prefix' => 'mobile-otp-test:',
    'mobile_otp.enabled' => true,
    'mobile_otp.cache_store' => 'database',
    'mobile_otp.hmac_key' => str_repeat('h', 32),
    'services.scmc.enabled' => true,
    'services.scmc.api_key' => 'sk_offline_test',
    'services.scmc.otp_template_id' => 'b045f9d9-ba98-4ba1-9884-faab98aa169c',
    'services.scmc.app_name' => 'EduConnect',
]);
DB::purge('sqlite');
Carbon::setTestNow(Carbon::parse($input['now']));
$sends = 0;
$failureType = null;
Http::preventStrayRequests();
Http::fake(function () use (&$sends) {
    $sends++;

    return Http::response(['success' => true, 'data' => ['sent' => 1]]);
});

file_put_contents($path.'.ready.'.$input['worker'], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($path.'.ready.0') || ! is_file($path.'.ready.1')) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(20000);
}

try {
    $otp = app(MobileOtpAuth::class);
    if ($input['mode'] === 'request') {
        $otp->request('+237677223344', '192.0.2.'.($input['worker'] + 1));
    } else {
        $otp->verify($input['id'], $input['code'], '192.0.2.'.($input['worker'] + 1), function ($parent): array {
            $token = $parent->createToken('race-test', ['mobile:parent']);

            return ['token_id' => $token->accessToken->id];
        });
    }
    $status = 200;
} catch (HttpResponseException $exception) {
    $status = $exception->getResponse()->getStatusCode();
} catch (Throwable $exception) {
    $failureType = $exception::class.':'.$exception->getCode();
    $status = 503;
}

echo json_encode(['status' => $status, 'sends' => $sends, 'failure_type' => $failureType], JSON_THROW_ON_ERROR);
