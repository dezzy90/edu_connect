<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SchoolScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Only apply scope if we have a current school context and it's not null
        if (app()->has('current_school')) {
            $currentSchool = app('current_school');
            if ($currentSchool && isset($currentSchool->id)) {
                $builder->where($model->getTable() . '.school_id', $currentSchool->id);
            }
        }
    }
}