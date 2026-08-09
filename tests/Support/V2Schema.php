<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class V2Schema
{
    private const MIGRATIONS = [
        'database/migrations/2026_08_07_000001_create_ec_core_tables.php',
        'database/migrations/2026_08_07_000002_create_ec_academic_tables.php',
        'database/migrations/2026_08_07_000003_create_ec_people_tables.php',
        'database/migrations/2026_08_07_000004_create_ec_device_attendance_tables.php',
        'database/migrations/2026_08_07_000005_create_ec_mobile_realtime_tables.php',
        'database/migrations/2026_08_07_000006_create_ec_integration_tables.php',
        'database/migrations/2026_08_07_000007_create_ec_integration_audit_events_table.php',
        'database/migrations/2026_08_07_000008_add_class_context_to_ec_conversation_threads_table.php',
        'database/migrations/2026_08_07_000009_add_provider_response_to_ec_notification_deliveries_table.php',
        'database/migrations/2026_08_08_000012_relax_parent_link_phone_uniqueness.php',
        'database/migrations/2026_08_09_000013_create_ec_student_mobile_profiles_table.php',
    ];

    public static function migrate(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('admin_users');

        foreach (array_reverse(self::MIGRATIONS) as $path) {
            (require base_path($path))->down();
        }

        Schema::enableForeignKeyConstraints();

        self::createAdminUsersTable();
        self::createPersonalAccessTokensTable();

        foreach (self::MIGRATIONS as $path) {
            (require base_path($path))->up();
        }
    }

    private static function createAdminUsersTable(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('school_admin')->index();
            $table->unsignedBigInteger('school_id')->nullable()->index();
            $table->string('avatar')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    private static function createPersonalAccessTokensTable(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }
}
