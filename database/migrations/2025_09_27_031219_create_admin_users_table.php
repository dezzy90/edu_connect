<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Superseded by 2025_09_27_031030_create_admin_users_table.php
        // and 2025_09_27_032935_add_columns_to_admin_users_table.php.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The admin_users table is owned by the earlier create migration.
    }
};
