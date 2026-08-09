<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncItem extends Model
{
    protected $table = 'ec_integration_sync_items';

    protected $guarded = ['id'];

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(IntegrationSyncRun::class, 'sync_run_id');
    }
}
