<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationOutboxEvent extends Model
{
    protected $table = 'ec_integration_outbox_events';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }
}
