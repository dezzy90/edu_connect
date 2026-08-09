<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('device_id')->unique();
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('location')->nullable();
            $table->enum('device_type', ['face_recognition', 'fingerprint', 'iris', 'multi'])->default('face_recognition');
            $table->string('firmware_version')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_heartbeat')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'is_active']);
            $table->index(['device_id']);
            $table->index(['last_heartbeat']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
