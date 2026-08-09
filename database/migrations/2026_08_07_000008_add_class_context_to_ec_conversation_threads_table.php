<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_conversation_threads', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('school_id')->constrained('ec_classes')->nullOnDelete();
            $table->json('metadata')->nullable()->after('source_id');

            $table->index(['tenant_id', 'school_id', 'class_id', 'type'], 'ec_threads_school_class_type_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ec_conversation_threads')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('ec_conversation_threads'))->pluck('name');

        if ($indexes->contains('ec_threads_school_class_type_idx')) {
            Schema::table('ec_conversation_threads', function (Blueprint $table) {
                $table->dropIndex('ec_threads_school_class_type_idx');
            });
        }

        Schema::table('ec_conversation_threads', function (Blueprint $table) {
            if (Schema::hasColumn('ec_conversation_threads', 'class_id')) {
                $table->dropForeign(['class_id']);
                $table->dropColumn('class_id');
            }

            if (Schema::hasColumn('ec_conversation_threads', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
