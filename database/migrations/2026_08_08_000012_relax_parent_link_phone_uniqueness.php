<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ec_parent_student_links')) {
            return;
        }

        Schema::table('ec_parent_student_links', function (Blueprint $table) {
            if (Schema::hasIndex('ec_parent_student_links', 'ec_parent_student_links_parent_phone_student_id_unique')) {
                $table->dropUnique('ec_parent_student_links_parent_phone_student_id_unique');
            }

            if (! Schema::hasIndex('ec_parent_student_links', 'ec_parent_links_student_phone_idx')) {
                $table->index(['student_id', 'parent_phone'], 'ec_parent_links_student_phone_idx');
            }

            if (! Schema::hasIndex('ec_parent_student_links', 'ec_parent_links_student_account_unique')) {
                $table->unique(['student_id', 'parent_account_id'], 'ec_parent_links_student_account_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ec_parent_student_links')) {
            return;
        }

        Schema::table('ec_parent_student_links', function (Blueprint $table) {
            if (Schema::hasIndex('ec_parent_student_links', 'ec_parent_links_student_account_unique')) {
                $table->dropUnique('ec_parent_links_student_account_unique');
            }

            if (Schema::hasIndex('ec_parent_student_links', 'ec_parent_links_student_phone_idx')) {
                $table->dropIndex('ec_parent_links_student_phone_idx');
            }

            if (! Schema::hasIndex('ec_parent_student_links', 'ec_parent_student_links_parent_phone_student_id_unique')) {
                $table->unique(['parent_phone', 'student_id']);
            }
        });
    }
};
