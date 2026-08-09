<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Concerns\BelongsToSchool;

class SchoolClass extends Model
{
    use HasFactory, SoftDeletes, BelongsToSchool;

    protected $table = 'school_classes';

    protected $fillable = [
        'school_id',
        'level_id',
        'name',
        'code',
        'academic_year',
        'capacity',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
    ];

    /**
     * Get the school that owns the class.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the level this class belongs to.
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * Get the option this class belongs to (through level).
     */
    public function option(): BelongsTo
    {
        return $this->level->option();
    }

    /**
     * Get the section this class belongs to (through level->option).
     */
    public function section(): BelongsTo
    {
        return $this->level->option->section();
    }

    /**
     * Get all students in this class.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    /**
     * Get the current student count.
     */
    public function getCurrentStudentCount(): int
    {
        return $this->students()->count();
    }

    /**
     * Check if the class has reached capacity.
     */
    public function hasReachedCapacity(): bool
    {
        return $this->capacity && $this->getCurrentStudentCount() >= $this->capacity;
    }

    /**
     * Get the class display name (combining section, option, level, name).
     */
    public function getDisplayNameAttribute(): string
    {
        $parts = [];
        
        if ($this->level && $this->level->option && $this->level->option->section) {
            $parts[] = $this->level->option->section->name;
        }
        
        if ($this->level && $this->level->option) {
            $parts[] = $this->level->option->name;
        }
        
        if ($this->level) {
            $parts[] = $this->level->name;
        }
        
        $parts[] = $this->name;
        
        return implode(' - ', $parts);
    }

    /**
     * Scope to get only active classes.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by academic year.
     */
    public function scopeForAcademicYear($query, $year)
    {
        return $query->where('academic_year', $year);
    }
}