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
        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['super_admin', 'school_admin'])->default('school_admin');
            $table->unsignedBigInteger('school_id')->nullable(); // null for super_admin, specific school for school_admin
            $table->string('avatar')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('set null');
            $table->index(['role', 'school_id']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropIndex(['role', 'school_id']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'name',
                'email',
                'email_verified_at',
                'password',
                'role',
                'school_id',
                'avatar',
                'phone',
                'last_login_at',
                'is_active',
                'remember_token'
            ]);
        });
    }
};
