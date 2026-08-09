<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_parent_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('region')->nullable();
            $table->text('address')->nullable();
            $table->string('preferred_language')->default('en');
            $table->string('status')->default('active')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('otp_secret')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ec_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('ec_classes')->nullOnDelete();
            $table->string('student_number')->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('photo_hash')->nullable();
            $table->string('biometric_identifier')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->string('parent_name')->nullable();
            $table->string('parent_phone')->nullable()->index();
            $table->string('parent_email')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->boolean('device_sync_enabled')->default(true);
            $table->boolean('mobile_visible')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'student_number']);
            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id', 'class_id']);
            $table->index(['tenant_id', 'school_id', 'mobile_visible']);
        });

        Schema::create('ec_parent_student_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('parent_account_id')->nullable()->constrained('ec_parent_accounts')->nullOnDelete();
            $table->foreignId('student_id')->constrained('ec_students')->cascadeOnDelete();
            $table->string('parent_phone')->index();
            $table->string('linking_code')->nullable()->index();
            $table->string('relationship')->default('parent');
            $table->string('relationship_description')->nullable();
            $table->boolean('is_primary_contact')->default(false);
            $table->boolean('can_pick_up')->default(true);
            $table->boolean('emergency_contact')->default(false);
            $table->json('communication_preferences')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['parent_phone', 'student_id']);
            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id', 'status']);
            $table->index(['parent_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_parent_student_links');
        Schema::dropIfExists('ec_students');
        Schema::dropIfExists('ec_parent_accounts');
    }
};
