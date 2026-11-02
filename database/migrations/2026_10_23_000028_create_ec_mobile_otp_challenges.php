<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_mobile_otp_phones', function (Blueprint $table): void {
            $table->char('phone_hash', 64)->primary();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('window_ends_at')->nullable();
            $table->unsignedInteger('request_count')->default(0);
        });

        Schema::create('ec_mobile_otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('purpose', 64);
            $table->char('phone_hash', 64)->index();
            $table->text('phone_ciphertext');
            $table->char('code_hash', 64);
            $table->string('subject_type', 24)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 24);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            // Avoid implicit zero TIMESTAMP defaults on older strict MySQL servers.
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_mobile_otp_challenges');
        Schema::dropIfExists('ec_mobile_otp_phones');
    }
};
