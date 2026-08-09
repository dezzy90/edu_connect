<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\School;

class MultiTenancyServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register school resolver
        $this->app->singleton('current_school', function () {
            return null; // Will be set by middleware
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Add helper functions for multi-tenancy
        $this->registerHelpers();
    }

    /**
     * Register global helper functions.
     */
    private function registerHelpers(): void
    {
        if (!function_exists(__NAMESPACE__ . '\\current_school')) {
            /**
             * Get the current school instance.
             */
            function current_school(): ?School
            {
                return app()->has('current_school') ? app('current_school') : null;
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\school_id')) {
            /**
             * Get the current school ID.
             */
            function school_id(): ?int
            {
                $school = current_school();
                return $school ? $school->id : null;
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\with_school')) {
            /**
             * Execute a callback with a specific school context.
             */
            function with_school(School $school, callable $callback)
            {
                $originalSchool = app()->has('current_school') ? app('current_school') : null;
                
                app()->instance('current_school', $school);
                
                try {
                    return $callback($school);
                } finally {
                    if ($originalSchool) {
                        app()->instance('current_school', $originalSchool);
                    } else {
                        app()->forgetInstance('current_school');
                    }
                }
            }
        }
    }
}
