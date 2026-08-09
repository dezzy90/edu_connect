<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationConnection extends Model
{
    use BelongsToTenant;

    protected $table = 'ec_integration_connections';

    protected $guarded = ['id'];

    protected $hidden = [
        'encrypted_access_token',
        'encrypted_refresh_token',
        'webhook_secret',
    ];

    protected $casts = [
        'scopes' => 'array',
        'feature_flags' => 'array',
        'last_successful_sync_at' => 'datetime',
        'last_failed_sync_at' => 'datetime',
    ];

    public function mappings(): HasMany
    {
        return $this->hasMany(IntegrationMapping::class, 'connection_id');
    }

    public function outboxEvents(): HasMany
    {
        return $this->hasMany(IntegrationOutboxEvent::class, 'connection_id');
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(IntegrationSyncRun::class, 'connection_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(IntegrationAuditEvent::class, 'connection_id');
    }
}
