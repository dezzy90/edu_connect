<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Concerns\BelongsToSchool;
use App\Concerns\HasParentLinking;

class Student extends Model
{
    use HasFactory, SoftDeletes, BelongsToSchool, HasParentLinking;

    protected $fillable = [
        'school_id',
        'class_id',
        'section_id',
        'level_id', 
        'option_id',
        'student_number',
        'first_name',
        'last_name',
        'middle_name',
        'date_of_birth',
        'gender',
        'address',
        'phone',
        'email',
        'emergency_contact',
        'emergency_contact_name',
        'emergency_contact_phone',
        'medical_info',
        'photo',
        'photo_base64',
        'biometric_id',
        'is_active',
        'enrollment_date',
        'graduation_date',
        'guardian_name',
        'guardian_phone',
        'parent_link_code',
        'parent_link_code_expires_at',
        'parent_link_enabled',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'parent_link_enabled' => 'boolean',
        'date_of_birth' => 'date',
        'enrollment_date' => 'date',
        'graduation_date' => 'date',
        'parent_link_code_expires_at' => 'datetime',
    ];

    protected $appends = [
        'full_name',
        'age',
    ];

    /**
     * Get the school that owns the student.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the class the student belongs to.
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Get the class the student belongs to (alias for consistency).
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Get the section the student belongs to.
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /**
     * Get the level the student belongs to.
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'level_id');
    }

    /**
     * Get the option the student belongs to.
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class, 'option_id');
    }

    /**
     * Get the parents associated with the student.
     */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(SchoolParent::class, 'parent_student', 'student_id', 'parent_id')
            ->withPivot(['link_code', 'relationship_type', 'is_primary', 'linked_at'])
            ->withTimestamps();
    }

    /**
     * Get all student logs (check-ins/check-outs) for this student.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(StudentLog::class);
    }

    /**
     * Get all student logs (alias for consistency).
     */
    public function studentLogs(): HasMany
    {
        return $this->hasMany(StudentLog::class);
    }

    /**
     * Get the full name attribute.
     */
    public function getFullNameAttribute(): string
    {
        $name = trim($this->first_name . ' ' . ($this->middle_name ?? '') . ' ' . $this->last_name);
        return preg_replace('/\s+/', ' ', $name);
    }

    /**
     * Get the age attribute.
     */
    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth ? $this->date_of_birth->diffInYears(now()) : null;
    }

    /**
     * Get today's logs for this student.
     */
    public function getTodaysLogs()
    {
        return $this->logs()
            ->whereDate('created_at', today())
            ->orderBy('created_at');
    }

    /**
     * Get the latest check-in for today.
     */
    public function getTodaysCheckIn()
    {
        return $this->getTodaysLogs()
            ->where('event_type', 'check_in')
            ->latest()
            ->first();
    }

    /**
     * Get the latest check-out for today.
     */
    public function getTodaysCheckOut()
    {
        return $this->getTodaysLogs()
            ->where('event_type', 'check_out')
            ->latest()
            ->first();
    }

    /**
     * Check if the student is currently checked in.
     */
    public function isCurrentlyCheckedIn(): bool
    {
        $checkIn = $this->getTodaysCheckIn();
        $checkOut = $this->getTodaysCheckOut();

        if (!$checkIn) {
            return false;
        }

        return !$checkOut || $checkIn->created_at > $checkOut->created_at;
    }

    /**
     * Get the student's current status.
     */
    public function getCurrentStatus(): string
    {
        if ($this->isCurrentlyCheckedIn()) {
            return 'checked_in';
        }

        if ($this->getTodaysCheckIn()) {
            return 'checked_out';
        }

        return 'not_arrived';
    }

    /**
     * Scope to get only active students.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by class.
     */
    public function scopeInClass($query, $classId)
    {
        return $query->where('class_id', $classId);
    }

    /**
     * Scope to search by name or student number.
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('student_number', 'like', "%{$search}%");
        });
    }

    /**
     * Generate a new parent link code for the student.
     */
    public function generateParentLinkCode(int $validityDays = 30): string
    {
        do {
            $code = $this->generateRandomCode();
        } while (static::where('parent_link_code', $code)->where('school_id', $this->school_id)->exists());

        $this->update([
            'parent_link_code' => $code,
            'parent_link_code_expires_at' => now()->addDays($validityDays),
            'parent_link_enabled' => true,
        ]);

        return $code;
    }

    /**
     * Generate a random alphanumeric code.
     */
    private function generateRandomCode(): string
    {
        // Generate 12-character code with mixed case letters and numbers
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';
        
        for ($i = 0; $i < 12; $i++) {
            $code .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $code;
    }

    /**
     * Check if the parent link code is valid and not expired.
     */
    public function isParentLinkCodeValid(): bool
    {
        if (!$this->parent_link_enabled || !$this->parent_link_code) {
            return false;
        }

        if ($this->parent_link_code_expires_at && $this->parent_link_code_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Disable the parent link code.
     */
    public function disableParentLinkCode(): void
    {
        $this->update([
            'parent_link_enabled' => false,
        ]);
    }

    /**
     * Find student by valid parent link code.
     */
    public static function findByValidLinkCode(string $linkCode, int $schoolId): ?static
    {
        return static::where('parent_link_code', $linkCode)
            ->where('school_id', $schoolId)
            ->where('parent_link_enabled', true)
            ->where(function ($query) {
                $query->whereNull('parent_link_code_expires_at')
                    ->orWhere('parent_link_code_expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Get formatted link code for display (e.g., XXXX-XXXX-XXXX).
     */
    public function getFormattedLinkCodeAttribute(): ?string
    {
        if (!$this->parent_link_code) {
            return null;
        }

        return substr($this->parent_link_code, 0, 4) . '-' . 
               substr($this->parent_link_code, 4, 4) . '-' . 
               substr($this->parent_link_code, 8, 4);
    }
}