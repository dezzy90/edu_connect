import { Head } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import AdminLayout from '@/layouts/AdminLayout';
import { 
    Users, 
    School, 
    Smartphone, 
    UserCheck, 
    TrendingUp, 
    Activity,
    Calendar,
    Clock,
    CheckCircle,
    AlertCircle
} from 'lucide-react';
import { format } from 'date-fns';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
    school?: {
        id: number;
        name: string;
    };
}

interface Stats {
    total_schools?: number;
    total_students: number;
    active_devices: number;
    total_attendance_today: number;
    total_attendance_month: number;
    total_admin_users?: number;
    online_devices: number;
    present_students_today?: number;
}

interface Activity {
    id: number;
    student_name: string;
    school_name: string;
    event_type: string;
    device_id: string;
    similarity: number;
    created_at: string;
    formatted_time: string;
}

interface School {
    id: number;
    name: string;
    students_count: number;
}

interface DashboardProps {
    admin: AdminUser;
    stats: Stats;
    recentActivities: Activity[];
    schools?: School[];
    isSuper: boolean;
}

export default function Dashboard({ admin, stats, recentActivities, schools, isSuper }: DashboardProps) {
    const StatCard = ({ 
        title, 
        value, 
        description, 
        icon: Icon, 
        trend, 
        color = "blue" 
    }: { 
        title: string; 
        value: number | string; 
        description: string; 
        icon: any; 
        trend?: string; 
        color?: string; 
    }) => (
        <Card className="relative overflow-hidden">
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                <Icon className={`h-4 w-4 text-${color}-600`} />
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
                <p className="text-xs text-muted-foreground">
                    {description}
                </p>
                {trend && (
                    <div className={`text-xs text-${color}-600 mt-1 flex items-center`}>
                        <TrendingUp className="h-3 w-3 mr-1" />
                        {trend}
                    </div>
                )}
            </CardContent>
        </Card>
    );

    return (
        <AdminLayout admin={admin}>
            <Head title="Admin Dashboard" />

            <div className="space-y-6">
                {/* Welcome Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            Welcome back, {admin.name}!
                        </h1>
                        <p className="text-gray-600">
                            {isSuper ? 'Super Administrator' : `${admin.school?.name} Administrator`}
                        </p>
                    </div>
                    <div className="mt-4 sm:mt-0">
                        <Badge variant={isSuper ? "default" : "secondary"} className="text-xs">
                            {isSuper ? 'Super Admin' : 'School Admin'}
                        </Badge>
                    </div>
                </div>

                {/* Stats Grid */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    {isSuper && (
                        <StatCard
                            title="Total Schools"
                            value={stats.total_schools || 0}
                            description="Active schools in system"
                            icon={School}
                            color="purple"
                        />
                    )}
                    
                    <StatCard
                        title="Total Students"
                        value={stats.total_students}
                        description={isSuper ? "Across all schools" : "In your school"}
                        icon={Users}
                        color="blue"
                    />
                    
                    <StatCard
                        title="Active Devices"
                        value={stats.active_devices}
                        description={`${stats.online_devices} online now`}
                        icon={Smartphone}
                        color="green"
                    />
                    
                    <StatCard
                        title="Today's Attendance"
                        value={stats.total_attendance_today}
                        description="Check-ins today"
                        icon={UserCheck}
                        color="orange"
                    />
                </div>

                {/* Additional Stats for School Admin */}
                {!isSuper && (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <StatCard
                            title="Present Students"
                            value={stats.present_students_today || 0}
                            description="Students present today"
                            icon={CheckCircle}
                            color="green"
                        />
                        
                        <StatCard
                            title="This Month"
                            value={stats.total_attendance_month}
                            description="Total attendance records"
                            icon={Calendar}
                            color="indigo"
                        />
                        
                        <StatCard
                            title="Device Status"
                            value={`${stats.online_devices}/${stats.active_devices}`}
                            description="Online/Total devices"
                            icon={Activity}
                            color="teal"
                        />
                    </div>
                )}

                <div className="grid gap-6 md:grid-cols-1 lg:grid-cols-3">
                    {/* Recent Activities */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center">
                                <Clock className="mr-2 h-5 w-5" />
                                Recent Activities
                            </CardTitle>
                            <CardDescription>
                                Latest attendance records and system activities
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                {recentActivities.map((activity) => (
                                    <div key={activity.id} className="flex items-center justify-between p-3 border rounded-lg bg-gray-50">
                                        <div className="flex items-center space-x-3">
                                            <div className={`p-2 rounded-full ${
                                                activity.event_type === 'check_in' 
                                                    ? 'bg-green-100 text-green-600' 
                                                    : 'bg-orange-100 text-orange-600'
                                            }`}>
                                                {activity.event_type === 'check_in' ? 
                                                    <CheckCircle className="h-4 w-4" /> : 
                                                    <AlertCircle className="h-4 w-4" />
                                                }
                                            </div>
                                            <div>
                                                <p className="font-medium text-sm">{activity.student_name}</p>
                                                <p className="text-xs text-gray-600">{activity.school_name}</p>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <Badge variant={activity.event_type === 'check_in' ? 'default' : 'secondary'} className="text-xs">
                                                {activity.event_type === 'check_in' ? 'Check In' : 'Check Out'}
                                            </Badge>
                                            <p className="text-xs text-gray-500 mt-1">{activity.formatted_time}</p>
                                        </div>
                                    </div>
                                ))}
                                {recentActivities.length === 0 && (
                                    <div className="text-center py-6 text-gray-500">
                                        <Activity className="mx-auto h-8 w-8 mb-2" />
                                        <p>No recent activities</p>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Schools Overview (Super Admin only) */}
                    {isSuper && schools && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center">
                                    <School className="mr-2 h-5 w-5" />
                                    Schools Overview
                                </CardTitle>
                                <CardDescription>
                                    Quick overview of all schools
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    {schools.slice(0, 5).map((school) => (
                                        <div key={school.id} className="flex items-center justify-between p-2 border rounded">
                                            <div>
                                                <p className="font-medium text-sm">{school.name}</p>
                                                <p className="text-xs text-gray-600">{school.students_count} students</p>
                                            </div>
                                            <Badge variant="outline" className="text-xs">
                                                Active
                                            </Badge>
                                        </div>
                                    ))}
                                    {schools.length > 5 && (
                                        <div className="text-center pt-2">
                                            <p className="text-xs text-gray-500">
                                                +{schools.length - 5} more schools
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* System Status (School Admin) */}
                    {!isSuper && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center">
                                    <Activity className="mr-2 h-5 w-5" />
                                    System Status
                                </CardTitle>
                                <CardDescription>
                                    Your school's system overview
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm">Device Connection</span>
                                        <Badge variant={stats.online_devices > 0 ? "default" : "destructive"}>
                                            {stats.online_devices > 0 ? "Online" : "Offline"}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm">System Status</span>
                                        <Badge variant="default">Active</Badge>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm">Last Update</span>
                                        <span className="text-xs text-gray-600">
                                            {format(new Date(), 'MMM dd, yyyy')}
                                        </span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}