<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_gate_setup_assignments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('admin_id');
            $t->boolean('active')->default(false);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'school_id', 'admin_id'], 'ec_gate_setup_assignment_scope');
        });
        Schema::create('ec_gate_setup_drafts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->unsignedInteger('revision');
            $t->json('configuration');
            $t->unsignedBigInteger('updated_by');
            $t->timestamp('updated_at')->useCurrent();
            $t->unique(['tenant_id', 'school_id'], 'ec_gate_setup_draft_scope');
        });
        Schema::create('ec_gate_setup_revisions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('draft_id');
            $t->unsignedInteger('revision');
            $t->unsignedInteger('basis_revision');
            $t->unsignedBigInteger('actor_id');
            $t->string('request_id', 64)->unique();
            $t->string('request_hash', 64);
            $t->json('configuration');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['draft_id', 'revision'], 'ec_gate_setup_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_gate_setup_revisions');
        Schema::dropIfExists('ec_gate_setup_drafts');
        Schema::dropIfExists('ec_gate_setup_assignments');
    }
};
