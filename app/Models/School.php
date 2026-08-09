<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'email',
        'logo',
        'timezone',
        'is_active',
        'subscription_expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'subscription_expires_at' => 'datetime',
    ];

    /**
     * Get all sections for this school.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /**
     * Get all options for this school.
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class);
    }

    /**
     * Get all levels for this school.
     */
    public function levels(): HasMany
    {
        return $this->hasMany(Level::class);
    }

    /**
     * Get all classes for this school.
     */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * Get all students for this school.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * Get all biometric devices for this school.
     */
    public function biometricDevices(): HasMany
    {
        return $this->hasMany(BiometricDevice::class);
    }

    /**
     * Get all users (staff) for this school.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get all admin users for this school.
     */
    public function adminUsers(): HasMany
    {
        return $this->hasMany(AdminUser::class);
    }

    /**
     * Get all student logs for this school through students.
     */
    public function studentLogs()
    {
        return $this->hasManyThrough(StudentLog::class, Student::class);
    }

    /**
     * Check if the school is active and subscription is valid.
     */
    public function isSubscriptionActive(): bool
    {
        return $this->is_active && 
               (!$this->subscription_expires_at || $this->subscription_expires_at->isFuture());
    }

    /**
     * Scope to get only active schools.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}