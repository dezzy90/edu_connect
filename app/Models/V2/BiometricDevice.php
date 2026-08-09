<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BiometricDevice extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'ec_biometric_devices';

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'last_heartbeat_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'device_id');
    }
}
