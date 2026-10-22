<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Production needs the deny-only marker used by scoped services, not the TEST recovery feature/tables.
        if (! Schema::hasColumn('admin_users', 'test_recovery_only')) {
            Schema::table('admin_users', fn (Blueprint $table) => $table->boolean('test_recovery_only')->default(false));
        }
        Schema::table('admin_users', function (Blueprint $table): void {
            // Not mass assignable. Account designation is a separate controlled operation.
            $table->boolean('gate_only')->default(false);
        });
    }

    public function down(): void
    {
        // Dropping this column would turn restricted gate accounts into ordinary admins.
        if (DB::table('admin_users')->where('gate_only', true)->exists()) {
            throw new RuntimeException('Preserve the gate-only restriction while designated accounts exist.');
        }
        Schema::table('admin_users', fn (Blueprint $table) => $table->dropColumn('gate_only'));
    }
};
