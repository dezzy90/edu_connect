<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_device_personnel', function (Blueprint $t): void {
            $t->timestamp('school_bound_at')->nullable();
        });
        Schema::create('ec_device_admin_assignments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('admin_id');
            $t->boolean('active')->default(false);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'admin_id'], 'ec_device_admin_scope');
        });
        Schema::create('ec_device_registry', function (Blueprint $t): void {
            $t->unsignedBigInteger('device_id')->primary();
            $t->string('serial_key', 128)->unique();
            $t->unsignedInteger('revision')->default(0);
            $t->boolean('managed_registration')->default(false);
            $t->string('scope_mode', 24)->default('single_school');
            $t->unsignedInteger('credential_version')->default(0);
            $t->timestamp('credentials_issued_at')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('last_verified_at')->nullable();
            $t->timestamp('retired_at')->nullable();
            $t->unsignedBigInteger('updated_by');
            $t->timestamp('updated_at')->useCurrent();
        });
        Schema::create('ec_device_school_assignments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('epoch')->unique();
            $t->unsignedBigInteger('device_id');
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->boolean('active')->default(false);
            $t->timestamp('authorized_from')->useCurrent();
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('assigned_by');
            $t->index(['device_id', 'school_id', 'active'], 'ec_device_school_active');
        });
        Schema::create('ec_device_registry_audits', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('device_id');
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('operation', 32);
            $t->unsignedInteger('revision');
            $t->string('request_id', 64)->unique();
            $t->string('request_hash', 64);
            $t->json('details');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['device_id', 'id']);
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('ec_device_registry_audits')->exists()) {
            throw new RuntimeException('Preserve device ownership and credential audit history; a reviewed retirement is required.');
        }
        Schema::dropIfExists('ec_device_registry_audits');
        Schema::dropIfExists('ec_device_school_assignments');
        Schema::dropIfExists('ec_device_registry');
        Schema::dropIfExists('ec_device_admin_assignments');
        Schema::table('ec_device_personnel', fn (Blueprint $t) => $t->dropColumn('school_bound_at'));
    }
};
