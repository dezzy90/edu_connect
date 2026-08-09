<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_integration_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('mode')->default('standalone')->index();
            $table->string('base_url')->nullable();
            $table->string('api_version')->default('v1');
            $table->string('remote_tenant_id')->nullable()->index();
            $table->string('status')->default('inactive')->index();
            $table->json('scopes')->nullable();
            $table->json('feature_flags')->nullable();
            $table->text('encrypted_access_token')->nullable();
            $table->text('encrypted_refresh_token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamp('last_failed_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('ec_integration_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('ec_integration_connections')->cascadeOnDelete();
            $table->string('local_type');
            $table->unsignedBigInteger('local_id');
            $table->string('external_type');
            $table->string('external_id');
            $table->timestamp('external_updated_at')->nullable();
            $table->string('checksum')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'local_type', 'local_id'], 'ec_mapping_local_unique');
            $table->unique(['connection_id', 'external_type', 'external_id'], 'ec_mapping_external_unique');
        });

        Schema::create('ec_integration_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('ec_integration_connections')->cascadeOnDelete();
            $table->string('sync_type')->index();
            $table->string('direction')->index();
            $table->string('status')->default('running')->index();
            $table->text('cursor_before')->nullable();
            $table->text('cursor_after')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->string('triggered_by_type')->nullable()->index();
            $table->unsignedBigInteger('triggered_by_id')->nullable()->index();
            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_deleted')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_integration_sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('ec_integration_sync_runs')->cascadeOnDelete();
            $table->string('local_type')->nullable();
            $table->unsignedBigInteger('local_id')->nullable();
            $table->string('external_type')->nullable();
            $table->string('external_id')->nullable();
            $table->string('action')->index();
            $table->string('status')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_integration_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('ec_integration_connections')->cascadeOnDelete();
            $table->string('event_type')->index();
            $table->string('event_key')->unique();
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_integration_inbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('ec_integration_connections')->cascadeOnDelete();
            $table->string('event_type')->index();
            $table->string('event_key')->unique();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_integration_inbox_events');
        Schema::dropIfExists('ec_integration_outbox_events');
        Schema::dropIfExists('ec_integration_sync_items');
        Schema::dropIfExists('ec_integration_sync_runs');
        Schema::dropIfExists('ec_integration_mappings');
        Schema::dropIfExists('ec_integration_connections');
    }
};
