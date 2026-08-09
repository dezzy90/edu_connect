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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->constrained('school_classes')->onDelete('restrict');
            
            // Student identification
            $table->string('student_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            
            // Personal information
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable()->unique();
            
            // Emergency and medical info
            $table->string('emergency_contact')->nullable();
            $table->text('medical_info')->nullable();
            
            // Academic info
            $table->string('photo')->nullable();
            $table->string('biometric_id')->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->date('enrollment_date')->nullable();
            $table->date('graduation_date')->nullable();
            
            // Guardian information
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            
            // Parent linking system
            $table->string('parent_link_code', 12)->unique()->nullable();
            $table->timestamp('parent_link_code_expires_at')->nullable();
            $table->boolean('parent_link_enabled')->default(true);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['school_id', 'class_id']);
            $table->index(['school_id', 'student_number']);
            $table->index(['parent_link_code', 'school_id']);
            $table->index(['biometric_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
