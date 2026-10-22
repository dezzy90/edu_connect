<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_hikvision_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained('ec_biometric_devices')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->restrictOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->restrictOnDelete();
            $table->string('serial', 128)->unique();
            $table->uuid('deployment_epoch');
            $table->string('model', 64);
            $table->string('firmware', 128);
            $table->string('auth_profile', 32);
            $table->string('username', 64);
            $table->text('credential_encrypted');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('login_failures')->default(0);
            $table->unsignedBigInteger('locked_until')->default(0);
            $table->timestamps();
        });
        Schema::create('ec_hikvision_push_sessions', function (Blueprint $table): void {
            $table->foreignId('push_device_id')->primary()->constrained('ec_hikvision_push_devices')->cascadeOnDelete();
            $table->string('phase', 24);
            $table->string('challenge', 64);
            $table->string('salt', 64);
            $table->unsignedInteger('iterations');
            $table->unsignedBigInteger('expires_at');
            $table->timestamp('last_seen_at')->nullable();
        });
        Schema::create('ec_hikvision_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('push_device_id')->constrained('ec_hikvision_push_devices')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->restrictOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->restrictOnDelete();
            $table->string('event_key', 64)->unique();
            $table->string('fingerprint', 64);
            $table->json('metadata');
            $table->string('status', 64)->index();
            $table->string('delivery_state', 32)->default('held_hardware_acceptance');
            $table->timestamp('received_at')->useCurrent();
        });
        Schema::create('ec_hikvision_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('push_device_id')->constrained('ec_hikvision_push_devices')->restrictOnDelete();
            $table->string('upload_uuid', 64);
            $table->string('payload_hash', 64);
            $table->foreignId('observation_id')->nullable()->constrained('ec_hikvision_observations')->restrictOnDelete();
            $table->string('status', 64);
            $table->timestamp('received_at')->useCurrent();
            $table->unique(['push_device_id', 'upload_uuid'], 'ec_hk_upload_unique');
        });
        Schema::create('ec_hikvision_push_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('push_device_id')->constrained('ec_hikvision_push_devices')->restrictOnDelete();
            $table->string('upload_uuid', 64);
            $table->string('original_hash', 64);
            $table->string('conflicting_hash', 64);
            $table->timestamp('received_at')->useCurrent();
        });
        Schema::create('ec_hikvision_push_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('push_device_id')->constrained('ec_hikvision_push_devices')->restrictOnDelete();
            $table->uuid('command_uuid')->unique();
            $table->string('url', 256);
            $table->string('state', 24)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('result_hash', 64)->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // MySQL 5.7 with NO_ZERO_DATE rejects an implicit zero default when
            // this required timestamp follows nullable timestamps. Be explicit.
            $table->timestamp('created_at')->useCurrent();
            $table->index(['push_device_id', 'state']);
        });
    }

    public function down(): void
    {
        foreach (['ec_hikvision_push_commands', 'ec_hikvision_push_conflicts', 'ec_hikvision_push_deliveries',
            'ec_hikvision_observations', 'ec_hikvision_push_sessions', 'ec_hikvision_push_devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
