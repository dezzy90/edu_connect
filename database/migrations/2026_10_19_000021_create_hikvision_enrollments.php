<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_students', static function (Blueprint $table): void {
            $table->json('enrollment_photo')->nullable();
        });
        Schema::create('ec_hikvision_enrollment_photos', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('ec_students')->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('ec_integration_connections')->restrictOnDelete();
            $table->string('source_revision', 64)->nullable();
            $table->string('prepared_revision', 64)->nullable();
            $table->string('photo_hash', 64)->nullable();
            $table->longText('photo_encrypted')->nullable();
            $table->string('state', 48)->index();
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->unsignedBigInteger('lease_until')->default(0);
            $table->timestamp('last_reconciled_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('ec_hikvision_enrollments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('push_device_id')->constrained('ec_hikvision_push_devices')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('ec_students')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->restrictOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->restrictOnDelete();
            $table->string('person_identifier', 32);
            $table->boolean('desired_present')->default(true);
            $table->boolean('person_owned')->default(false);
            $table->boolean('face_owned')->default(false);
            $table->boolean('removal_in_progress')->default(false);
            $table->string('desired_revision', 64)->nullable();
            $table->string('confirmed_revision', 64)->nullable();
            $table->string('state', 48)->default('missing_photo')->index();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('recognition_confirmed_at')->nullable();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['push_device_id', 'student_id'], 'ec_hik_enroll_student_unique');
            $table->unique(['push_device_id', 'person_identifier'], 'ec_hik_enroll_person_unique');
        });
        Schema::create('ec_hikvision_enrollment_commands', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('ec_hikvision_enrollments')->restrictOnDelete();
            $table->uuid('command_uuid')->unique();
            $table->string('revision', 64);
            $table->string('operation', 24);
            $table->string('state', 32)->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->longText('payload_encrypted')->nullable();
            $table->string('result_hash', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_hikvision_enrollment_commands');
        Schema::dropIfExists('ec_hikvision_enrollments');
        Schema::dropIfExists('ec_hikvision_enrollment_photos');
        Schema::table('ec_students', static fn (Blueprint $table) => $table->dropColumn('enrollment_photo'));
    }
};
