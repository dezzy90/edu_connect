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
        Schema::create('parent_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('link_code', 6)->unique();
            $table->enum('relationship_type', ['father', 'mother', 'guardian', 'parent'])->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['parent_id', 'student_id']);
            $table->index(['link_code']);
            $table->index(['student_id', 'is_primary']);
            $table->index(['parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parent_student');
    }
};
