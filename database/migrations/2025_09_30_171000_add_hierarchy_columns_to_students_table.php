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
        Schema::table('students', function (Blueprint $table) {
            // Add foreign keys for the hierarchical structure
            $table->foreignId('section_id')->nullable()->after('class_id')->constrained('sections')->onDelete('set null');
            $table->foreignId('option_id')->nullable()->after('section_id')->constrained('options')->onDelete('set null');
            $table->foreignId('level_id')->nullable()->after('option_id')->constrained('levels')->onDelete('set null');
            
            // Add indexes for performance
            $table->index(['section_id']);
            $table->index(['option_id']);
            $table->index(['level_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropForeign(['option_id']);
            $table->dropForeign(['level_id']);
            $table->dropColumn(['section_id', 'option_id', 'level_id']);
        });
    }
};
