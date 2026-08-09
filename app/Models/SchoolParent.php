<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolParent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'parents';

    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'email',
        'address',
        'occupation',
        'workplace',
        'emergency_contact',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'full_name',
    ];

    /**
     * Get the students associated with the parent.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_id', 'student_id')
            ->withPivot(['link_code', 'relationship_type', 'is_primary', 'linked_at'])
            ->withTimestamps();
    }

    /**
     * Get the full name attribute.
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    /**
     * Get primary children (where is_primary = true).
     */
    public function primaryChildren()
    {
        return $this->students()->wherePivot('is_primary', true);
    }

    /**
     * Check if parent can be linked to more students.
     * A parent can be linked to maximum 5 students.
     */
    public function canLinkMoreStudents(): bool
    {
        return $this->students()->count() < 5;
    }

    /**
     * Scope to get only active parents.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to search by name, phone, or email.
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        });
    }
}