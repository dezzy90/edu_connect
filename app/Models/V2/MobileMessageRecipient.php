<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileMessageRecipient extends Model
{
    protected $table = 'ec_mobile_message_recipients';

    protected $guarded = ['id'];

    protected $casts = [
        'read_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(MobileMessage::class, 'message_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->forceFill([
                'delivery_status' => 'read',
                'delivered_at' => $this->delivered_at ?? now(),
                'read_at' => now(),
            ])->save();
        }
    }
}
