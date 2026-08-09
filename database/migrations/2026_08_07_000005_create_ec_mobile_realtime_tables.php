<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_mobile_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('ec_academic_years')->nullOnDelete();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->string('sender_type')->default('system');
            $table->string('sender_name')->nullable();
            $table->string('category')->default('general')->index();
            $table->string('priority')->default('normal')->index();
            $table->string('title');
            $table->text('body');
            $table->string('audience_type')->default('parents')->index();
            $table->json('audience_filters')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'school_id', 'status']);
        });

        Schema::create('ec_mobile_message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('ec_mobile_messages')->cascadeOnDelete();
            $table->foreignId('parent_account_id')->nullable()->constrained('ec_parent_accounts')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('ec_students')->nullOnDelete();
            $table->string('recipient_phone')->nullable()->index();
            $table->string('delivery_status')->default('queued')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['parent_account_id', 'delivery_status'], 'ec_msg_recip_parent_delivery_idx');
            $table->index(['student_id', 'delivery_status'], 'ec_msg_recip_student_delivery_idx');
        });

        Schema::create('ec_mobile_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_account_id')->constrained('ec_parent_accounts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('ec_schools')->nullOnDelete();
            $table->string('type')->index();
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->string('priority')->default('normal')->index();
            $table->string('channel')->default('in_app')->index();
            $table->string('delivery_status')->default('queued')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['parent_account_id', 'read_at']);
            $table->index(['tenant_id', 'school_id', 'created_at']);
        });

        Schema::create('ec_mobile_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_account_id')->constrained('ec_parent_accounts')->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('provider')->index();
            $table->string('platform')->index();
            $table->string('device_name')->nullable();
            $table->string('app_version')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'token']);
            $table->index(['parent_account_id', 'revoked_at']);
        });

        Schema::create('ec_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('ec_mobile_notifications')->cascadeOnDelete();
            $table->foreignId('push_token_id')->nullable()->constrained('ec_mobile_push_tokens')->nullOnDelete();
            $table->string('provider')->index();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_account_id')->constrained('ec_parent_accounts')->cascadeOnDelete();
            $table->string('category')->index();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->timestamps();

            $table->unique(['parent_account_id', 'category'], 'ec_notif_prefs_parent_category_unique');
        });

        Schema::create('ec_realtime_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_account_id')->nullable()->constrained('ec_parent_accounts')->nullOnDelete();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('channel_name')->index();
            $table->string('socket_id')->index();
            $table->timestamp('connected_at')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_conversation_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('ec_schools')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('ec_students')->nullOnDelete();
            $table->string('type')->index();
            $table->string('title');
            $table->string('status')->default('open')->index();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('last_message_at')->nullable();
            $table->string('created_by_type');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'school_id', 'status']);
        });

        Schema::create('ec_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('ec_conversation_threads')->cascadeOnDelete();
            $table->string('participant_type')->index();
            $table->unsignedBigInteger('participant_id')->nullable()->index();
            $table->string('display_name');
            $table->string('role')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'participant_type', 'participant_id'], 'ec_conv_part_thread_type_id_idx');
        });

        Schema::create('ec_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('ec_conversation_threads')->cascadeOnDelete();
            $table->string('sender_type')->index();
            $table->unsignedBigInteger('sender_id')->nullable()->index();
            $table->string('sender_display_name');
            $table->string('message_type')->default('text')->index();
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('sent')->index();
            $table->timestamp('sent_at')->index();
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('ec_conversation_message_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('ec_conversation_messages')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('ec_conversation_participants')->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'participant_id'], 'ec_conv_receipt_message_participant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_conversation_message_receipts');
        Schema::dropIfExists('ec_conversation_messages');
        Schema::dropIfExists('ec_conversation_participants');
        Schema::dropIfExists('ec_conversation_threads');
        Schema::dropIfExists('ec_realtime_subscriptions');
        Schema::dropIfExists('ec_notification_preferences');
        Schema::dropIfExists('ec_notification_deliveries');
        Schema::dropIfExists('ec_mobile_push_tokens');
        Schema::dropIfExists('ec_mobile_notifications');
        Schema::dropIfExists('ec_mobile_message_recipients');
        Schema::dropIfExists('ec_mobile_messages');
    }
};
