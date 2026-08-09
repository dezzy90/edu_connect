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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->enum('role', ['super_admin', 'school_admin', 'principal', 'teacher', 'staff'])->after('password')->default('staff');
            $table->boolean('is_active')->after('role')->default(true);
            $table->timestamp('last_login_at')->after('is_active')->nullable();
            $table->softDeletes();

            $table->index(['school_id', 'role']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['school_id', 'role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['school_id', 'role', 'is_active', 'last_login_at']);
        });
    }
};
