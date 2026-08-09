<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConversationThread extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    public const TYPE_DIRECT = 'direct';
    public const TYPE_CLASS_GROUP = 'class_group';
    public const TYPE_SCHOOL_CHANNEL = 'school_channel';

    protected $table = 'ec_conversation_threads';

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(AcademicClass::class, 'class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class, 'thread_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'thread_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function realtimeChannel(): string
    {
        return "private-conversation.{$this->id}";
    }
}
