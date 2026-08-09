<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\School;

class SetSchoolContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $school = $this->resolveSchool($request);
        
        if ($school) {
            app()->instance('current_school', $school);
            
            // Share school with views
            view()->share('currentSchool', $school);
            
            // Set timezone if available
            if ($school->timezone) {
                config(['app.timezone' => $school->timezone]);
                date_default_timezone_set($school->timezone);
            }
        }

        return $next($request);
    }

    /**
     * Resolve the current school based on various methods.
     */
    private function resolveSchool(Request $request): ?School
    {
        // Method 1: From authenticated user's school
        if ($request->user() && $request->user()->school_id) {
            return $request->user()->school;
        }

        // Method 2: From subdomain (if using subdomain-based tenancy)
        $host = $request->getHost();
        $subdomain = $this->extractSubdomain($host);
        
        if ($subdomain && $subdomain !== 'www') {
            $school = School::where('code', $subdomain)->active()->first();
            if ($school) {
                return $school;
            }
        }

        // Method 3: From request parameter (useful for API calls)
        $schoolCode = $request->header('X-School-Code') ?? $request->get('school_code');
        if ($schoolCode) {
            return School::where('code', $schoolCode)->active()->first();
        }

        // Method 4: From session (fallback for web interface)
        $schoolId = $request->session()->get('current_school_id');
        if ($schoolId) {
            return School::find($schoolId);
        }

        return null;
    }

    /**
     * Extract subdomain from host.
     */
    private function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);
        
        // If we have at least 3 parts (subdomain.domain.tld)
        if (count($parts) >= 3) {
            return $parts[0];
        }

        return null;
    }
}
