<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_student_mobile_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('ec_students')->cascadeOnDelete();
            $table->json('profile')->nullable();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'student_id']);
            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id']);
            $table->index(['school_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_student_mobile_profiles');
    }
};
