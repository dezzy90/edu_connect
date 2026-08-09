<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class StudentLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'device_id',
        'event_type',
        'biometric_data',
        'confidence_score',
        'similarity',
        'verify_status',
        'notes',
        'processed_at',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'processed_at' => 'datetime',
        'biometric_data' => 'array',
    ];

    const EVENT_CHECK_IN = 'check_in';
    const EVENT_CHECK_OUT = 'check_out';

    /**
     * Get the school through student relationship.
     */
    public function school(): BelongsTo
    {
        return $this->student->school();
    }

    /**
     * Get the student this log belongs to.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the device that recorded this log.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    /**
     * Get the biometric device that recorded this log (alias for consistency).
     */
    public function biometricDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    /**
     * Check if this is a check-in event.
     */
    public function isCheckIn(): bool
    {
        return $this->event_type === self::EVENT_CHECK_IN;
    }

    /**
     * Check if this is a check-out event.
     */
    public function isCheckOut(): bool
    {
        return $this->event_type === self::EVENT_CHECK_OUT;
    }

    /**
     * Get the formatted time for display.
     */
    public function getFormattedTimeAttribute(): string
    {
        return $this->created_at->format('H:i:s');
    }

    /**
     * Get the event type label.
     */
    public function getEventTypeLabelAttribute(): string
    {
        return match($this->event_type) {
            self::EVENT_CHECK_IN => 'Check In',
            self::EVENT_CHECK_OUT => 'Check Out',
            default => 'Unknown'
        };
    }

    /**
     * Scope to filter by event type.
     */
    public function scopeEventType(Builder $query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope to get check-in events.
     */
    public function scopeCheckIns(Builder $query): Builder
    {
        return $query->where('event_type', self::EVENT_CHECK_IN);
    }

    /**
     * Scope to get check-out events.
     */
    public function scopeCheckOuts(Builder $query): Builder
    {
        return $query->where('event_type', self::EVENT_CHECK_OUT);
    }

    /**
     * Scope to filter by date.
     */
    public function scopeForDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('created_at', $date);
    }

    /**
     * Scope to get today's logs.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Scope to filter by student.
     */
    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    /**
     * Scope to filter by device.
     */
    public function scopeForDevice(Builder $query, int $deviceId): Builder
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope to order by latest first.
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Boot method to add model events.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            // Validate daily constraint before creating
            if (!static::canCreateLog($log->student_id, $log->event_type)) {
                throw new \Exception(
                    "Student can only have one {$log->event_type} per day."
                );
            }
        });
    }

    /**
     * Check if a log can be created for a student on a given day.
     */
    public static function canCreateLog(int $studentId, string $eventType, Carbon $date = null): bool
    {
        $date = $date ?? today();
        
        $existingLog = static::where('student_id', $studentId)
            ->where('event_type', $eventType)
            ->whereDate('created_at', $date)
            ->exists();

        return !$existingLog;
    }

    /**
     * Get the latest log for a student on a given date.
     */
    public static function getLatestForStudent(int $studentId, Carbon $date = null): ?static
    {
        $date = $date ?? today();
        
        return static::where('student_id', $studentId)
            ->whereDate('created_at', $date)
            ->latest()
            ->first();
    }

    /**
     * Create a check-in log if allowed.
     */
    public static function createCheckIn(int $studentId, int $deviceId, array $data = []): static
    {
        if (!static::canCreateLog($studentId, self::EVENT_CHECK_IN)) {
            throw new \Exception('Student already checked in today.');
        }

        return static::create(array_merge([
            'student_id' => $studentId,
            'device_id' => $deviceId,
            'event_type' => self::EVENT_CHECK_IN,
            'processed_at' => now(),
        ], $data));
    }

    /**
     * Create a check-out log if allowed.
     */
    public static function createCheckOut(int $studentId, int $deviceId, array $data = []): static
    {
        if (!static::canCreateLog($studentId, self::EVENT_CHECK_OUT)) {
            throw new \Exception('Student already checked out today.');
        }

        // Ensure student has checked in first
        $hasCheckedIn = static::where('student_id', $studentId)
            ->where('event_type', self::EVENT_CHECK_IN)
            ->whereDate('created_at', today())
            ->exists();

        if (!$hasCheckedIn) {
            throw new \Exception('Student must check in before checking out.');
        }

        return static::create(array_merge([
            'student_id' => $studentId,
            'device_id' => $deviceId,
            'event_type' => self::EVENT_CHECK_OUT,
            'processed_at' => now(),
        ], $data));
    }
}