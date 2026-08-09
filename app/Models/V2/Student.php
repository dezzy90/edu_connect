<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use App\Models\V2\Concerns\HasSourceIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use BelongsToTenant;
    use HasSourceIdentity;
    use SoftDeletes;

    public const MOBILE_VISIBLE_STATUSES = ['active', 'enrolled'];

    protected $table = 'ec_students';

    protected $guarded = ['id'];

    protected $casts = [
        'date_of_birth' => 'date',
        'device_sync_enabled' => 'boolean',
        'mobile_visible' => 'boolean',
        'source_updated_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(AcademicClass::class, 'class_id');
    }

    public function parentLinks(): HasMany
    {
        return $this->hasMany(ParentStudentLink::class);
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function mobileMessageRecipients(): HasMany
    {
        return $this->hasMany(MobileMessageRecipient::class);
    }

    public function conversationThreads(): HasMany
    {
        return $this->hasMany(ConversationThread::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->middle_name} {$this->last_name}"));
    }

    public function isAvailableInMobile(): bool
    {
        return $this->mobile_visible && in_array($this->status, self::MOBILE_VISIBLE_STATUSES, true);
    }
}
