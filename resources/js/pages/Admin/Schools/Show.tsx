import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { 
    Table, 
    TableBody, 
    TableCell, 
    TableHead, 
    TableHeader, 
    TableRow 
} from '@/components/ui/table';
import { 
    ArrowLeft, 
    Edit, 
    Trash2, 
    Users, 
    Smartphone, 
    UserCheck,
    Building2,
    Mail,
    Phone,
    MapPin,
    Calendar,
    Clock,
    Activity
} from 'lucide-react';

interface School {
    id: number;
    name: string;
    code: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    logo: string | null;
    timezone: string;
    is_active: boolean;
    subscription_expires_at: string | null;
    created_at: string;
    students: Array<{
        id: number;
        student_number: string;
        first_name: string;
        last_name: string;
        is_active: boolean;
        created_at: string;
    }>;
    biometric_devices: Array<{
        id: number;
        name: string;
        device_id: string;
        is_active: boolean;
        last_heartbeat: string | null;
    }>;
    admin_users: Array<{
        id: number;
        name: string;
        email: string;
        role: string;
        is_active: boolean;
        last_login_at: string | null;
    }>;
}

interface Stats {
    total_students: number;
    active_students: number;
    total_devices: number;
    active_devices: number;
    online_devices: number;
    admin_users: number;
    total_logs_today: number;
}

interface RecentActivity {
    id: number;
    student: {
        id: number;
        first_name: string;
        last_name: string;
        student_number: string;
    };
    biometric_device: {
        id: number;
        name: string;
    } | null;
    check_in: string | null;
    check_out: string | null;
    created_at: string;
}

interface Props {
    school: School;
    stats: Stats;
    recentActivity: RecentActivity[];
    admin: {
        id: number;
        name: string;
        email: string;
        role: string;
    };
}

export default function SchoolsShow({ school, stats, recentActivity, admin }: Props) {
    const handleDelete = () => {
        if (confirm(`Are you sure you want to delete ${school.name}? This action cannot be undone.`)) {
            router.delete(`/admin/schools/${school.id}`);
        }
    };

    const formatDate = (dateString: string | null) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const formatDateTime = (dateString: string | null) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    return (
        <AdminLayout admin={admin}>
            <Head title={school.name} />
            
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-4">
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/admin/schools">
                                <ArrowLeft className="w-4 h-4 mr-2" />
                                Back
                            </Link>
                        </Button>
                        <div>
                            <div className="flex items-center gap-3">
                                {school.logo && (
                                    <img 
                                        src={`/storage/${school.logo}`} 
                                        alt={school.name}
                                        className="h-12 w-12 object-contain rounded border"
                                    />
                                )}
                                <div>
                                    <h1 className="text-3xl font-bold tracking-tight flex items-center gap-2">
                                        <Building2 className="h-8 w-8" />
                                        {school.name}
                                    </h1>
                                    <p className="text-muted-foreground">
                                        Code: {school.code}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={`/admin/schools/${school.id}/edit`}>
                                <Edit className="w-4 h-4 mr-2" />
                                Edit
                            </Link>
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            <Trash2 className="w-4 h-4 mr-2" />
                            Delete
                        </Button>
                    </div>
                </div>

                {/* Status Badge */}
                <div>
                    <Badge variant={school.is_active ? 'default' : 'destructive'} className="text-sm">
                        {school.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                </div>

                {/* Stats Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Students</CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.total_students}</div>
                            <p className="text-xs text-muted-foreground">
                                {stats.active_students} active
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Devices</CardTitle>
                            <Smartphone className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.total_devices}</div>
                            <p className="text-xs text-muted-foreground">
                                {stats.online_devices} online
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Admin Users</CardTitle>
                            <UserCheck className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.admin_users}</div>
                            <p className="text-xs text-muted-foreground">
                                Active administrators
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Today's Logs</CardTitle>
                            <Activity className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.total_logs_today}</div>
                            <p className="text-xs text-muted-foreground">
                                Attendance records
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    {/* School Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle>School Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {school.email && (
                                <div className="flex items-start gap-3">
                                    <Mail className="h-5 w-5 text-muted-foreground mt-0.5" />
                                    <div>
                                        <p className="text-sm font-medium">Email</p>
                                        <p className="text-sm text-muted-foreground">{school.email}</p>
                                    </div>
                                </div>
                            )}
                            {school.phone && (
                                <div className="flex items-start gap-3">
                                    <Phone className="h-5 w-5 text-muted-foreground mt-0.5" />
                                    <div>
                                        <p className="text-sm font-medium">Phone</p>
                                        <p className="text-sm text-muted-foreground">{school.phone}</p>
                                    </div>
                                </div>
                            )}
                            {school.address && (
                                <div className="flex items-start gap-3">
                                    <MapPin className="h-5 w-5 text-muted-foreground mt-0.5" />
                                    <div>
                                        <p className="text-sm font-medium">Address</p>
                                        <p className="text-sm text-muted-foreground">{school.address}</p>
                                    </div>
                                </div>
                            )}
                            <div className="flex items-start gap-3">
                                <Clock className="h-5 w-5 text-muted-foreground mt-0.5" />
                                <div>
                                    <p className="text-sm font-medium">Timezone</p>
                                    <p className="text-sm text-muted-foreground">{school.timezone}</p>
                                </div>
                            </div>
                            {school.subscription_expires_at && (
                                <div className="flex items-start gap-3">
                                    <Calendar className="h-5 w-5 text-muted-foreground mt-0.5" />
                                    <div>
                                        <p className="text-sm font-medium">Subscription Expires</p>
                                        <p className="text-sm text-muted-foreground">
                                            {formatDate(school.subscription_expires_at)}
                                        </p>
                                    </div>
                                </div>
                            )}
                            <div className="flex items-start gap-3">
                                <Calendar className="h-5 w-5 text-muted-foreground mt-0.5" />
                                <div>
                                    <p className="text-sm font-medium">Created</p>
                                    <p className="text-sm text-muted-foreground">
                                        {formatDate(school.created_at)}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Admin Users */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Admin Users ({school.admin_users.length})</CardTitle>
                            <CardDescription>School administrators</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {school.admin_users.length === 0 ? (
                                <p className="text-sm text-muted-foreground text-center py-4">
                                    No admin users assigned
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {school.admin_users.map((adminUser) => (
                                        <div key={adminUser.id} className="flex items-center justify-between p-3 border rounded-lg">
                                            <div>
                                                <p className="font-medium">{adminUser.name}</p>
                                                <p className="text-sm text-muted-foreground">{adminUser.email}</p>
                                            </div>
                                            <Badge variant="outline">{adminUser.role}</Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Students */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Recent Students</CardTitle>
                                <CardDescription>Latest 10 students enrolled</CardDescription>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/admin/students?school_id=${school.id}`}>
                                    View All
                                </Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {school.students.length === 0 ? (
                            <p className="text-sm text-muted-foreground text-center py-4">
                                No students enrolled yet
                            </p>
                        ) : (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Student ID</TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Enrolled</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {school.students.map((student) => (
                                            <TableRow key={student.id}>
                                                <TableCell className="font-medium">{student.student_number}</TableCell>
                                                <TableCell>{student.first_name} {student.last_name}</TableCell>
                                                <TableCell>
                                                    <Badge variant={student.is_active ? 'default' : 'secondary'}>
                                                        {student.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>{formatDate(student.created_at)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Biometric Devices */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Biometric Devices</CardTitle>
                                <CardDescription>Registered attendance devices</CardDescription>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/admin/devices?school_id=${school.id}`}>
                                    View All
                                </Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {school.biometric_devices.length === 0 ? (
                            <p className="text-sm text-muted-foreground text-center py-4">
                                No devices registered yet
                            </p>
                        ) : (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Device Name</TableHead>
                                            <TableHead>Device ID</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Last Heartbeat</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {school.biometric_devices.map((device) => (
                                            <TableRow key={device.id}>
                                                <TableCell className="font-medium">{device.name}</TableCell>
                                                <TableCell>{device.device_id}</TableCell>
                                                <TableCell>
                                                    <Badge variant={device.is_active ? 'default' : 'secondary'}>
                                                        {device.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>{formatDateTime(device.last_heartbeat)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Recent Activity */}
                <Card>
                    <CardHeader>
                        <CardTitle>Recent Activity</CardTitle>
                        <CardDescription>Latest attendance logs</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {recentActivity.length === 0 ? (
                            <p className="text-sm text-muted-foreground text-center py-4">
                                No recent activity
                            </p>
                        ) : (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Student</TableHead>
                                            <TableHead>Device</TableHead>
                                            <TableHead>Check In</TableHead>
                                            <TableHead>Check Out</TableHead>
                                            <TableHead>Date</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentActivity.map((activity) => (
                                            <TableRow key={activity.id}>
                                                <TableCell>
                                                    <div>
                                                        <p className="font-medium">
                                                            {activity.student.first_name} {activity.student.last_name}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {activity.student.student_number}
                                                        </p>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {activity.biometric_device?.name || 'N/A'}
                                                </TableCell>
                                                <TableCell>
                                                    {activity.check_in ? formatDateTime(activity.check_in) : '-'}
                                                </TableCell>
                                                <TableCell>
                                                    {activity.check_out ? formatDateTime(activity.check_out) : '-'}
                                                </TableCell>
                                                <TableCell>{formatDate(activity.created_at)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
