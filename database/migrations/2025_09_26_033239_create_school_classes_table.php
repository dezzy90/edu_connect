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
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete(); // Required - belongs to a level
            $table->string('name'); // e.g., "6ème A", "Form 1 Alpha", "Terminal C1"
            $table->string('code'); // e.g., "6A", "F1A", "TC1"
            $table->string('academic_year'); // e.g., "2024-2025"
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'level_id', 'code', 'academic_year']);
            $table->index(['school_id', 'is_active']);
            $table->index(['school_id', 'academic_year']);
            $table->index(['level_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_classes');
    }
};
