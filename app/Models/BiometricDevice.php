<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Concerns\BelongsToSchool;

class BiometricDevice extends Model
{
    use HasFactory, SoftDeletes, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'name',
        'device_id',
        'mac_address',
        'ip_address',
        'location',
        'device_type',
        'firmware_version',
        'is_active',
        'last_heartbeat',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_heartbeat' => 'datetime',
    ];

    /**
     * Get the school that owns the device.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get all student logs from this device.
     */
    public function studentLogs(): HasMany
    {
        return $this->hasMany(StudentLog::class, 'device_id');
    }

    /**
     * Check if the device is online (heartbeat within last 5 minutes).
     */
    public function isOnline(): bool
    {
        return $this->last_heartbeat && 
               $this->last_heartbeat->diffInMinutes(now()) <= 5;
    }

    /**
     * Update the device heartbeat.
     */
    public function updateHeartbeat(): void
    {
        $this->update(['last_heartbeat' => now()]);
    }

    /**
     * Scope to get only active devices.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only online devices.
     */
    public function scopeOnline($query)
    {
        return $query->where('last_heartbeat', '>=', now()->subMinutes(5));
    }
}