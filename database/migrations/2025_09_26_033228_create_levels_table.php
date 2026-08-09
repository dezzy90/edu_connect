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
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g., "Form 1", "Form 2", "6ème", "5ème", etc.
            $table->string('code'); // e.g., "F1", "F2", "6E", "5E"
            $table->text('description')->nullable();
            $table->integer('order')->default(0); // For ordering levels (1st year, 2nd year, etc.)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'option_id', 'code']);
            $table->index(['school_id', 'option_id', 'order']);
            $table->index(['school_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
