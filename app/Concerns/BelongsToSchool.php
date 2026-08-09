<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\School;
use App\Scopes\SchoolScope;

trait BelongsToSchool
{
    /**
     * Boot the trait.
     */
    public static function bootBelongsToSchool()
    {
        static::addGlobalScope(new SchoolScope);

        // Automatically set school_id when creating
        static::creating(function (Model $model) {
            if (empty($model->school_id) && app()->has('current_school')) {
                $currentSchool = app('current_school');
                if ($currentSchool && isset($currentSchool->id)) {
                    $model->school_id = $currentSchool->id;
                }
            }
        });
    }

    /**
     * Get the school that owns the model.
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Scope to filter by school.
     */
    public function scopeForSchool(Builder $query, School|int $school): Builder
    {
        $schoolId = $school instanceof School ? $school->id : $school;
        return $query->where('school_id', $schoolId);
    }

    /**
     * Remove the school scope temporarily.
     */
    public function scopeWithoutSchoolScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SchoolScope::class);
    }
}