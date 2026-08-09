<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessageReceipt extends Model
{
    protected $table = 'ec_conversation_message_receipts';

    protected $guarded = ['id'];

    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'message_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ConversationParticipant::class, 'participant_id');
    }
}
