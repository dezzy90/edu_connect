<?php

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('keeps OTP expiry mandatory without implicit MySQL timestamp defaults', function (): void {
    $connection = new MySqlConnection(null, 'test', '', ['driver' => 'mysql', 'version' => '5.7.44']);
    $connection->setSchemaGrammar(new MySqlGrammar($connection));
    $sql = [];
    foreach (['ec_mobile_otp_phones', 'ec_mobile_otp_challenges'] as $table) {
        Schema::shouldReceive('create')->once()->with($table, Mockery::type('Closure'))
            ->andReturnUsing(function (string $name, Closure $definition) use ($connection, &$sql): void {
                $blueprint = new Blueprint($connection, $name);
                $blueprint->create();
                $definition($blueprint);
                $sql[$name] = implode('; ', $blueprint->toSql());
            });
    }

    (require database_path('migrations/2026_10_23_000028_create_ec_mobile_otp_challenges.php'))->up();

    expect(strtolower($sql['ec_mobile_otp_challenges']))
        ->toContain('`expires_at` datetime not null', 'ec_mobile_otp_challenges_expires_at_index')
        ->not->toContain('`expires_at` timestamp', 'on update');
});
