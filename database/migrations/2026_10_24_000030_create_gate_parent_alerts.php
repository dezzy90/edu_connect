<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ec_gate_parent_alert_settings', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('tenant_id'); $t->unsignedBigInteger('school_id');
            $t->boolean('arrivals_enabled')->default(false); $t->boolean('departures_enabled')->default(false);
            $t->timestamp('effective_from'); $t->unsignedInteger('revision'); $t->unsignedBigInteger('updated_by'); $t->timestamps();
            $t->unique(['tenant_id', 'school_id']);
        });
        Schema::create('ec_gate_parent_alert_changes', function (Blueprint $t): void {
            $t->string('request_id', 64)->primary(); $t->string('request_hash', 64);
            $t->unsignedBigInteger('tenant_id'); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('actor_id');
            $t->unsignedInteger('revision'); $t->json('configuration'); $t->timestamp('created_at');
        });
        Schema::create('ec_gate_push_leases', function (Blueprint $t): void {
            $t->unsignedBigInteger('delivery_id')->primary(); $t->uuid('token'); $t->unsignedBigInteger('expires_at')->default(0);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('ec_gate_push_leases'); Schema::dropIfExists('ec_gate_parent_alert_changes'); Schema::dropIfExists('ec_gate_parent_alert_settings');
    }
};
