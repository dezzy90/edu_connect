<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_integration_connections', function (Blueprint $table): void {
            $table->json('enabled_school_ids')->nullable()->after('remote_tenant_id');
            $table->string('provisioning_request_id')->nullable()->after('enabled_school_ids')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ec_integration_connections')) {
            return;
        }

        Schema::table('ec_integration_connections', function (Blueprint $table): void {
            if (Schema::hasIndex('ec_integration_connections', ['provisioning_request_id'])) {
                $table->dropIndex(['provisioning_request_id']);
            }

            if (Schema::hasColumn('ec_integration_connections', 'enabled_school_ids')) {
                $table->dropColumn('enabled_school_ids');
            }

            if (Schema::hasColumn('ec_integration_connections', 'provisioning_request_id')) {
                $table->dropColumn('provisioning_request_id');
            }
        });
    }
};
