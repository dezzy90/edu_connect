<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'ec_attendance_events';

    protected $guarded = ['id'];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'raw_payload' => 'array',
        'event_time' => 'datetime',
        'edu_admin_synced_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }
}
