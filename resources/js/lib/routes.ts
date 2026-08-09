// Helper function to mimic Laravel's route() helper
export function route(name: string, params?: any): string {
    // Handle routes with parameters
    if (params) {
        // If params is a number or string, treat it as an ID
        const id = typeof params === 'object' ? params.id || params : params;
        
        // Map route names to URL patterns
        const routePatterns: Record<string, string> = {
            // Sections
            'admin.sections.index': '/admin/sections',
            'admin.sections.create': '/admin/sections/create',
            'admin.sections.store': '/admin/sections',
            'admin.sections.show': `/admin/sections/${id}`,
            'admin.sections.edit': `/admin/sections/${id}/edit`,
            'admin.sections.update': `/admin/sections/${id}`,
            'admin.sections.destroy': `/admin/sections/${id}`,
            'admin.sections.toggle-status': `/admin/sections/${id}/toggle-status`,
            
            // Options
            'admin.options.index': '/admin/options',
            'admin.options.create': '/admin/options/create',
            'admin.options.store': '/admin/options',
            'admin.options.show': `/admin/options/${id}`,
            'admin.options.edit': `/admin/options/${id}/edit`,
            'admin.options.update': `/admin/options/${id}`,
            'admin.options.destroy': `/admin/options/${id}`,
            'admin.options.toggle-status': `/admin/options/${id}/toggle-status`,
            
            // Levels
            'admin.levels.index': '/admin/levels',
            'admin.levels.create': '/admin/levels/create',
            'admin.levels.store': '/admin/levels',
            'admin.levels.show': `/admin/levels/${id}`,
            'admin.levels.edit': `/admin/levels/${id}/edit`,
            'admin.levels.update': `/admin/levels/${id}`,
            'admin.levels.destroy': `/admin/levels/${id}`,
            'admin.levels.toggle-status': `/admin/levels/${id}/toggle-status`,
            
            // Classes
            'admin.classes.index': '/admin/classes',
            'admin.classes.create': '/admin/classes/create',
            'admin.classes.store': '/admin/classes',
            'admin.classes.show': `/admin/classes/${id}`,
            'admin.classes.edit': `/admin/classes/${id}/edit`,
            'admin.classes.update': `/admin/classes/${id}`,
            'admin.classes.destroy': `/admin/classes/${id}`,
            'admin.classes.toggle-status': `/admin/classes/${id}/toggle-status`,
            
            // Schools
            'admin.schools.index': '/admin/schools',
            'admin.schools.create': '/admin/schools/create',
            'admin.schools.store': '/admin/schools',
            'admin.schools.show': `/admin/schools/${id}`,
            'admin.schools.edit': `/admin/schools/${id}/edit`,
            'admin.schools.update': `/admin/schools/${id}`,
            'admin.schools.destroy': `/admin/schools/${id}`,
            'admin.schools.toggle-status': `/admin/schools/${id}/toggle-status`,
            
            // Students
            'admin.students.index': '/admin/students',
            'admin.students.create': '/admin/students/create',
            'admin.students.store': '/admin/students',
            'admin.students.show': `/admin/students/${id}`,
            'admin.students.edit': `/admin/students/${id}/edit`,
            'admin.students.update': `/admin/students/${id}`,
            'admin.students.destroy': `/admin/students/${id}`,
            'admin.students.sync': `/admin/students/${id}/sync`,
            
            // Devices
            'admin.devices.index': '/admin/devices',
            'admin.devices.create': '/admin/devices/create',
            'admin.devices.store': '/admin/devices',
            'admin.devices.show': `/admin/devices/${id}`,
            'admin.devices.edit': `/admin/devices/${id}/edit`,
            'admin.devices.update': `/admin/devices/${id}`,
            'admin.devices.destroy': `/admin/devices/${id}`,
            'admin.devices.sync-students': `/admin/devices/${id}/sync-students`,
            'admin.devices.test-connection': `/admin/devices/${id}/test-connection`,
            'admin.devices.toggle-status': `/admin/devices/${id}/toggle-status`,
            
            // Attendance
            'admin.attendance.index': '/admin/attendance',
            
            // Admin Users
            'admin.admin-users.index': '/admin/admin-users',
            'admin.admin-users.create': '/admin/admin-users/create',
            'admin.admin-users.store': '/admin/admin-users',
            'admin.admin-users.show': `/admin/admin-users/${id}`,
            'admin.admin-users.edit': `/admin/admin-users/${id}/edit`,
            'admin.admin-users.update': `/admin/admin-users/${id}`,
            'admin.admin-users.destroy': `/admin/admin-users/${id}`,
            
            // Dashboard
            'admin.dashboard': '/admin/dashboard',
            
            // Settings
            'admin.settings': '/admin/settings',
        };
        
        return routePatterns[name] || name;
    }
    
    // Routes without parameters
    const simpleRoutes: Record<string, string> = {
        // Sections
        'admin.sections.index': '/admin/sections',
        'admin.sections.create': '/admin/sections/create',
        'admin.sections.store': '/admin/sections',
        
        // Options
        'admin.options.index': '/admin/options',
        'admin.options.create': '/admin/options/create',
        'admin.options.store': '/admin/options',
        
        // Levels
        'admin.levels.index': '/admin/levels',
        'admin.levels.create': '/admin/levels/create',
        'admin.levels.store': '/admin/levels',
        
        // Classes
        'admin.classes.index': '/admin/classes',
        'admin.classes.create': '/admin/classes/create',
        'admin.classes.store': '/admin/classes',
        
        // Schools
        'admin.schools.index': '/admin/schools',
        'admin.schools.create': '/admin/schools/create',
        'admin.schools.store': '/admin/schools',
        
        // Students
        'admin.students.index': '/admin/students',
        'admin.students.create': '/admin/students/create',
        'admin.students.store': '/admin/students',
        
        // Devices
        'admin.devices.index': '/admin/devices',
        'admin.devices.create': '/admin/devices/create',
        'admin.devices.store': '/admin/devices',
        
        // Attendance
        'admin.attendance.index': '/admin/attendance',
        
        // Admin Users
        'admin.admin-users.index': '/admin/admin-users',
        'admin.admin-users.create': '/admin/admin-users/create',
        'admin.admin-users.store': '/admin/admin-users',
        
        // Dashboard
        'admin.dashboard': '/admin/dashboard',
        
        // Settings
        'admin.settings': '/admin/settings',
        
        // Auth
        'admin.login': '/admin/login',
        'admin.logout': '/admin/logout',
    };
    
    return simpleRoutes[name] || name;
}

// Export default for convenience
export default route;
