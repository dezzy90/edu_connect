<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_conversation_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('reply_to_message_id')->nullable()->after('thread_id');
            $table->index('reply_to_message_id', 'ec_conv_msg_reply_idx');
            $table->foreign('reply_to_message_id', 'ec_conv_msg_reply_fk')
                ->references('id')
                ->on('ec_conversation_messages')
                ->nullOnDelete();
        });

        Schema::table('ec_mobile_messages', function (Blueprint $table): void {
            $table->json('metadata')->nullable()->after('audience_filters');
        });

        Schema::create('ec_conversation_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 160);
            $table->string('kind', 30);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('source_system', 40)->nullable();
            $table->string('source_id', 120)->nullable();
            $table->string('sha256', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('message_id', 'ec_conv_att_msg_idx');
            $table->unique(['source_system', 'source_id'], 'ec_conv_att_source_uq');
            $table->foreign('message_id', 'ec_conv_att_msg_fk')
                ->references('id')
                ->on('ec_conversation_messages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_conversation_message_attachments');

        if (Schema::hasTable('ec_mobile_messages') && Schema::hasColumn('ec_mobile_messages', 'metadata')) {
            Schema::table('ec_mobile_messages', function (Blueprint $table): void {
                $table->dropColumn('metadata');
            });
        }

        if (Schema::hasTable('ec_conversation_messages') && Schema::hasColumn('ec_conversation_messages', 'reply_to_message_id')) {
            Schema::table('ec_conversation_messages', function (Blueprint $table): void {
                $table->dropForeign('ec_conv_msg_reply_fk');
                $table->dropIndex('ec_conv_msg_reply_idx');
                $table->dropColumn('reply_to_message_id');
            });
        }
    }
};
