<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConversationMessage extends Model
{
    use SoftDeletes;

    public const SENDER_PARENT = 'parent_account';
    public const SENDER_ADMIN = 'admin_user';
    public const SENDER_SYSTEM = 'system';

    protected $table = 'ec_conversation_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ConversationThread::class, 'thread_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ConversationMessageReceipt::class, 'message_id');
    }
}
