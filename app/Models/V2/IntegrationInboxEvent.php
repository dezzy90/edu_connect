<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationInboxEvent extends Model
{
    protected $table = 'ec_integration_inbox_events';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }
}
