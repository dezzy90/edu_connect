<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_integration_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('ec_tenants')->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('ec_integration_connections')->nullOnDelete();
            $table->string('category')->index();
            $table->string('event_type')->index();
            $table->string('severity')->default('info')->index();
            $table->string('status')->nullable()->index();
            $table->string('summary');
            $table->json('metadata')->nullable();
            $table->string('actor_type')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('related_type')->nullable()->index();
            $table->unsignedBigInteger('related_id')->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'occurred_at'], 'ec_audit_tenant_category_time_idx');
            $table->index(['connection_id', 'category', 'occurred_at'], 'ec_audit_connection_category_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_integration_audit_events');
    }
};
