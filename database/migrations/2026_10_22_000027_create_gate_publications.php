<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_gate_activation_assignments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('admin_id');
            $t->boolean('active')->default(false);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'school_id', 'admin_id'], 'ec_gate_activation_scope');
        });
        Schema::create('ec_gate_publications', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('draft_id');
            $t->unsignedInteger('draft_revision');
            $t->json('configuration');
            $t->date('starts_on');
            $t->dateTime('activated_at');
            $t->unsignedBigInteger('activated_by');
            $t->string('state', 16);
            $t->dateTime('paused_at')->nullable();
            $t->unsignedBigInteger('paused_by')->nullable();
            $t->dateTime('last_materialized_at')->nullable();
            $t->string('materialization_error', 64)->nullable();
            $t->index(['school_id', 'state'], 'ec_gate_publication_school');
        });
        Schema::create('ec_gate_publication_actions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('publication_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('operation', 16);
            $t->string('reason_code', 32);
            $t->string('request_id', 64)->unique();
            $t->string('request_hash', 64);
            $t->dateTime('created_at');
        });
        Schema::create('ec_gate_policy_publications', function (Blueprint $t): void {
            $t->string('policy_id', 64)->primary();
            $t->unsignedBigInteger('publication_id');
            $t->date('day');
            $t->unique(['publication_id', 'day'], 'ec_gate_publication_day');
        });
    }

    public function down(): void
    {
        if (DB::table('ec_gate_publications')->exists() || DB::table('ec_gate_publication_actions')->exists()) {
            throw new RuntimeException('Preserve published school rules and audit history; pause and roll back code, not attendance history.');
        }
        Schema::dropIfExists('ec_gate_policy_publications');
        Schema::dropIfExists('ec_gate_publication_actions');
        Schema::dropIfExists('ec_gate_publications');
        Schema::dropIfExists('ec_gate_activation_assignments');
    }
};
