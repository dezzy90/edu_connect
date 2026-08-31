<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessageAttachment extends Model
{
    protected $table = 'ec_conversation_message_attachments';

    protected $guarded = ['id'];

    protected $casts = [
        'size_bytes' => 'integer',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'message_id');
    }
}
