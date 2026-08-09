<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Concerns\BelongsToSchool;

class Section extends Model
{
    use HasFactory, SoftDeletes, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the school that owns the section.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get all options in this section.
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class);
    }

    /**
     * Get all levels in this section (through options).
     */
    public function levels()
    {
        return Level::whereIn('option_id', $this->options()->pluck('id'));
    }

    /**
     * Get all classes in this section (through options and levels).
     */
    public function classes()
    {
        $levelIds = Level::whereIn('option_id', $this->options()->pluck('id'))->pluck('id');
        return SchoolClass::whereIn('level_id', $levelIds);
    }

    /**
     * Scope to get only active sections.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}