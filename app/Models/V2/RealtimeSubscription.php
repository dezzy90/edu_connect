<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealtimeSubscription extends Model
{
    protected $table = 'ec_realtime_subscriptions';

    protected $guarded = ['id'];

    protected $casts = [
        'connected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class);
    }
}
