<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_notification_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('ec_notification_deliveries', 'provider_response')) {
                $table->json('provider_response')->nullable()->after('provider_message_id');
            }

            if (!Schema::hasColumn('ec_notification_deliveries', 'next_attempt_at')) {
                $table->timestamp('next_attempt_at')->nullable()->index()->after('failed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ec_notification_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('ec_notification_deliveries', 'next_attempt_at')) {
                $table->dropIndex(['next_attempt_at']);
                $table->dropColumn('next_attempt_at');
            }

            if (Schema::hasColumn('ec_notification_deliveries', 'provider_response')) {
                $table->dropColumn('provider_response');
            }
        });
    }
};
