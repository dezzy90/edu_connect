<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('device_uid')->unique();
            $table->string('serial_number')->nullable()->index();
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('location')->nullable();
            $table->string('device_type')->nullable();
            $table->string('firmware_version')->nullable();
            $table->string('mqtt_client_id')->nullable();
            $table->string('mqtt_command_topic')->nullable();
            $table->string('mqtt_recognition_topic')->nullable();
            $table->string('status')->default('inactive')->index();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'school_id', 'status']);
        });

        Schema::create('ec_device_personnel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('ec_biometric_devices')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('ec_students')->cascadeOnDelete();
            $table->string('person_identifier')->index();
            $table->string('payload_hash')->nullable();
            $table->string('sync_status')->default('pending')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_ack_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'student_id']);
            $table->index(['tenant_id', 'school_id', 'sync_status']);
        });

        Schema::create('ec_device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('ec_biometric_devices')->cascadeOnDelete();
            $table->string('command_type')->index();
            $table->string('command_key')->unique();
            $table->json('payload');
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'school_id', 'status']);
        });

        Schema::create('ec_device_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('ec_biometric_devices')->cascadeOnDelete();
            $table->foreignId('command_id')->nullable()->constrained('ec_device_commands')->nullOnDelete();
            $table->string('ack_type')->index();
            $table->json('payload')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });

        Schema::create('ec_attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('ec_students')->nullOnDelete();
            $table->foreignId('device_id')->constrained('ec_biometric_devices')->cascadeOnDelete();
            $table->string('external_event_id')->nullable()->index();
            $table->string('event_key')->unique();
            $table->string('event_type')->index();
            $table->timestamp('event_time')->index();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('verify_status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('processing_status')->default('processed')->index();
            $table->string('edu_admin_sync_status')->default('pending')->index();
            $table->timestamp('edu_admin_synced_at')->nullable();
            $table->text('edu_admin_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'school_id', 'event_time']);
            $table->index(['student_id', 'event_time']);
        });

        Schema::create('ec_attendance_daily_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('ec_students')->cascadeOnDelete();
            $table->date('date')->index();
            $table->timestamp('first_check_in_at')->nullable();
            $table->timestamp('last_check_out_at')->nullable();
            $table->string('status')->default('not_arrived')->index();
            $table->integer('late_minutes')->nullable();
            $table->json('source_event_ids')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'date']);
            $table->index(['tenant_id', 'school_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_attendance_daily_states');
        Schema::dropIfExists('ec_attendance_events');
        Schema::dropIfExists('ec_device_acks');
        Schema::dropIfExists('ec_device_commands');
        Schema::dropIfExists('ec_device_personnel');
        Schema::dropIfExists('ec_biometric_devices');
    }
};
