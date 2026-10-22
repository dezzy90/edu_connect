<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_gate_policies', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->date('day');
            $t->string('timezone', 64);
            $t->string('version', 64);
            $t->json('snapshot');
            $t->json('student_ids');
            $t->json('device_ids');
            $t->dateTime('effective_from');
            $t->dateTime('effective_until')->nullable();
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('confirmed_by');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'school_id', 'version'], 'ec_gate_policy_version');
            $t->index(['school_id', 'day', 'enabled'], 'ec_gate_policy_day');
        });
        Schema::create('ec_gate_days', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->date('day');
            $t->string('state', 24)->default('unprocessed');
            $t->unsignedInteger('revision')->default(0);
            $t->string('projection_hash', 64)->nullable();
            $t->json('decisions')->nullable();
            $t->timestamp('updated_at')->useCurrent();
            $t->unique(['tenant_id', 'school_id', 'student_id', 'day'], 'ec_gate_day_scope');
        });
        Schema::create('ec_gate_observation_links', function (Blueprint $t): void {
            $t->unsignedBigInteger('observation_id')->primary();
            $t->unsignedBigInteger('day_id')->nullable()->index();
            $t->string('policy_id', 64)->nullable();
            $t->json('policy_snapshot')->nullable();
            $t->string('device_uid', 120)->nullable();
            $t->string('error_code', 64)->nullable();
            $t->string('source_status', 64);
            $t->string('source_fingerprint', 64);
            $t->string('original_fingerprint', 64);
            $t->timestamp('linked_at')->useCurrent();
        });
        Schema::create('ec_gate_movements', function (Blueprint $t): void {
            $t->string('event_id', 64)->primary();
            $t->unsignedBigInteger('day_id')->index();
            $t->unsignedBigInteger('observation_id');
            $t->unsignedInteger('revision');
            $t->string('state', 16);
            $t->string('movement', 16);
            $t->json('payload');
            $t->string('projection_payload_hash', 64);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent();
        });
        Schema::create('ec_gate_movement_revisions', function (Blueprint $t): void {
            $t->id();
            $t->string('event_id', 64);
            $t->unsignedInteger('revision');
            $t->string('payload_hash', 64);
            $t->json('payload');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['event_id', 'revision'], 'ec_gate_movement_revision');
        });
        Schema::create('ec_gate_operator_assignments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('admin_id');
            $t->string('role', 32);
            $t->boolean('active')->default(false);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'school_id', 'admin_id', 'role'], 'ec_gate_operator_scope');
        });
        Schema::create('ec_gate_actions', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->unsignedBigInteger('day_id')->index();
            $t->unsignedBigInteger('actor_id');
            $t->string('role', 32);
            $t->string('kind', 32);
            $t->unsignedBigInteger('observation_id')->nullable();
            $t->string('movement_id', 64)->nullable();
            $t->string('reference_action_id', 64)->nullable();
            $t->string('reason_code', 64);
            $t->json('parameters');
            $t->string('status', 24);
            $t->unsignedInteger('basis_revision');
            $t->dateTime('expires_at')->nullable();
            $t->unsignedBigInteger('consumed_observation_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent();
        });
        Schema::create('ec_gate_mirror_bindings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('school_id')->unique();
            $t->unsignedBigInteger('connection_id');
            $t->uuid('source_id');
            $t->unsignedBigInteger('remote_school_id');
            $t->boolean('enabled')->default(false);
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('ec_gate_outbox', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('day_id');
            $t->string('event_id', 64);
            $t->unsignedInteger('revision');
            $t->string('destination', 64);
            $t->json('payload');
            $t->string('status', 24)->default('pending');
            $t->json('delivery_snapshot')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->uuid('lease_token')->nullable();
            $t->unsignedBigInteger('lease_until')->default(0);
            $t->timestamp('available_at')->useCurrent();
            $t->timestamp('sent_at')->nullable();
            $t->string('last_error', 96)->nullable();
            $t->unsignedBigInteger('notification_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['event_id', 'revision', 'destination'], 'ec_gate_delivery_identity');
            $t->index(['status', 'available_at'], 'ec_gate_delivery_queue');
        });
        Schema::create('ec_gate_parent_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('outbox_id');
            $t->unsignedBigInteger('parent_account_id');
            $t->string('status', 24);
            $t->string('reason_code', 64)->nullable();
            $t->unsignedBigInteger('notification_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['outbox_id', 'parent_account_id'], 'ec_gate_parent_delivery_identity');
        });
    }

    public function down(): void
    {
        foreach (['ec_gate_parent_deliveries', 'ec_gate_outbox', 'ec_gate_mirror_bindings', 'ec_gate_actions', 'ec_gate_operator_assignments',
            'ec_gate_movement_revisions', 'ec_gate_movements', 'ec_gate_observation_links', 'ec_gate_days', 'ec_gate_policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
