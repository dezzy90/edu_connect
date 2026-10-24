<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', fn (Blueprint $t) => $t->boolean('gate_source_only')->default(false));
        Schema::create('ec_gate_staff_bridges', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->unsignedBigInteger('connection_id')->unique();
            $t->unsignedBigInteger('source_complex_id');
            $t->text('secret'); // Laravel Crypt ciphertext; never returned by an API.
            $t->boolean('enabled')->default(false);
            $t->timestamps();
        });
        Schema::create('ec_gate_source_actors', function (Blueprint $t): void {
            $t->id();
            $t->string('bridge_id', 64);
            $t->unsignedBigInteger('source_user_id');
            $t->unsignedBigInteger('admin_id')->unique();
            $t->timestamp('authority_until');
            $t->unique(['bridge_id', 'source_user_id'], 'ec_gate_source_actor_identity');
        });
        Schema::create('ec_gate_staff_nonces', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_gate_staff_nonces');
        Schema::dropIfExists('ec_gate_source_actors');
        Schema::dropIfExists('ec_gate_staff_bridges');
        Schema::table('admin_users', fn (Blueprint $t) => $t->dropColumn('gate_source_only'));
    }
};
