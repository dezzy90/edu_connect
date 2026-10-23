<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_gate_account_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('email_hash', 64);
            $table->string('token_hash', 64)->nullable();
            $table->string('delivery_channel', 24);
            $table->unsignedBigInteger('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('ec_gate_account_invitations')->exists()) {
            throw new RuntimeException('Retain account invitation audit history; use code-only rollback.');
        }
        Schema::dropIfExists('ec_gate_account_invitations');
    }
};
