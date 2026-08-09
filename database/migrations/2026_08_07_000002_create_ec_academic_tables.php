<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('ec_schools')->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('status')->default('active')->index();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id', 'is_current']);
        });

        Schema::create('ec_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('active')->index();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id', 'status']);
        });

        Schema::create('ec_education_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('ec_sections')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('active')->index();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id', 'section_id']);
        });

        Schema::create('ec_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('ec_sections')->cascadeOnDelete();
            $table->foreignId('education_option_id')->nullable()->constrained('ec_education_options')->nullOnDelete();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->integer('grade_level')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('active')->index();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id', 'section_id']);
        });

        Schema::create('ec_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('stream_id')->constrained('ec_streams')->cascadeOnDelete();
            $table->string('name');
            $table->string('full_name');
            $table->integer('capacity')->default(0);
            $table->integer('current_enrollment')->default(0);
            $table->string('class_teacher_name')->nullable();
            $table->string('class_teacher_external_id')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id', 'stream_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_classes');
        Schema::dropIfExists('ec_streams');
        Schema::dropIfExists('ec_education_options');
        Schema::dropIfExists('ec_sections');
        Schema::dropIfExists('ec_academic_years');
    }
};
