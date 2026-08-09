<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    public const TYPE_PARENT = 'parent_account';
    public const TYPE_ADMIN = 'admin_user';
    public const TYPE_SYSTEM = 'system';

    protected $table = 'ec_conversation_participants';

    protected $guarded = ['id'];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'muted_until' => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ConversationThread::class, 'thread_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class, 'participant_id');
    }
}
