<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $table = 'ec_notification_deliveries';

    protected $guarded = ['id'];

    protected $casts = [
        'provider_response' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(MobileNotification::class, 'notification_id');
    }

    public function pushToken(): BelongsTo
    {
        return $this->belongsTo(MobilePushToken::class, 'push_token_id');
    }
}
