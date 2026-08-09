import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { 
    ArrowLeft, 
    Edit, 
    Trash2, 
    User, 
    Mail, 
    Phone, 
    MapPin, 
    Calendar,
    School,
    Users,
    Activity,
    FileText,
    Link2,
    RefreshCw
} from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

interface Student {
    id: number;
    student_number: string;
    first_name: string;
    last_name: string;
    middle_name?: string;
    full_name: string;
    email?: string;
    phone?: string;
    date_of_birth: string;
    gender: string;
    address?: string;
    photo?: string;
    biometric_id: string;
    enrollment_date: string;
    is_active: boolean;
    emergency_contact_name?: string;
    emergency_contact_phone?: string;
    guardian_name?: string;
    guardian_phone?: string;
    medical_info?: string;
    parent_link_code?: string;
    parent_link_code_expires_at?: string;
    parent_link_enabled: boolean;
    formatted_link_code?: string;
    school: {
        id: number;
        name: string;
    };
    school_class?: {
        id: number;
        name: string;
    };
    section?: {
        id: number;
        name: string;
    };
    level?: {
        id: number;
        name: string;
    };
    option?: {
        id: number;
        name: string;
    };
    parents?: Array<{
        id: number;
        name: string;
        email: string;
        phone: string;
    }>;
    student_logs?: Array<{
        id: number;
        event_type: string;
        created_at: string;
        biometric_device?: {
            id: number;
            name: string;
        };
    }>;
}

interface AttendanceStats {
    total_logs: number;
    check_ins_today: number;
    check_outs_today: number;
    this_month_attendance: number;
}

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
}

interface Props {
    student: Student;
    attendanceStats: AttendanceStats;
    admin: AdminUser;
    flash?: {
        success?: string;
        warning?: string;
        error?: string;
    };
}

export default function StudentShow({ student, attendanceStats, admin, flash }: Props) {
    const handleDelete = () => {
        if (confirm(`Are you sure you want to delete ${student.full_name}?`)) {
            router.delete(`/admin/students/${student.id}`);
        }
    };

    const handleSync = () => {
        router.post(`/admin/students/${student.id}/sync`, {}, {
            preserveState: true,
        });
    };

    return (
        <AdminLayout admin={admin}>
            <Head title={`Student: ${student.full_name}`} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href="/admin/students">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Students
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-3xl font-bold">{student.full_name}</h1>
                            <p className="text-gray-500">Student Number: {student.student_number}</p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={handleSync}>
                            <RefreshCw className="h-4 w-4 mr-2" />
                            Sync to Devices
                        </Button>
                        <Link href={`/admin/students/${student.id}/edit`}>
                            <Button variant="outline" size="sm">
                                <Edit className="h-4 w-4 mr-2" />
                                Edit
                            </Button>
                        </Link>
                        <Button variant="destructive" size="sm" onClick={handleDelete}>
                            <Trash2 className="h-4 w-4 mr-2" />
                            Delete
                        </Button>
                    </div>
                </div>

                {/* Success/Warning/Error Messages */}
                {flash?.success && (
                    <Alert className="bg-green-50 border-green-200">
                        <AlertDescription className="text-green-800">
                            {flash.success}
                        </AlertDescription>
                    </Alert>
                )}
                {flash?.warning && (
                    <Alert className="bg-yellow-50 border-yellow-200">
                        <AlertDescription className="text-yellow-800">
                            {flash.warning}
                        </AlertDescription>
                    </Alert>
                )}
                {flash?.error && (
                    <Alert className="bg-red-50 border-red-200">
                        <AlertDescription className="text-red-800">
                            {flash.error}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Status Badge */}
                <div>
                    <Badge variant={student.is_active ? "default" : "secondary"}>
                        {student.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Attendance Stats */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Today's Check-ins</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{attendanceStats.check_ins_today}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Today's Check-outs</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{attendanceStats.check_outs_today}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">This Month</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{attendanceStats.this_month_attendance} days</p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Personal Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <User className="h-5 w-5" />
                                Personal Information
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {student.photo && (
                                <div className="flex justify-center mb-4">
                                    <img 
                                        src={`/storage/${student.photo}`} 
                                        alt={student.full_name}
                                        className="w-32 h-32 rounded-full object-cover border-4 border-gray-200"
                                    />
                                </div>
                            )}
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <p className="text-sm text-gray-500">First Name</p>
                                    <p className="font-medium">{student.first_name}</p>
                                </div>
                                {student.middle_name && (
                                    <div>
                                        <p className="text-sm text-gray-500">Middle Name</p>
                                        <p className="font-medium">{student.middle_name}</p>
                                    </div>
                                )}
                                <div>
                                    <p className="text-sm text-gray-500">Last Name</p>
                                    <p className="font-medium">{student.last_name}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-500">Gender</p>
                                    <p className="font-medium capitalize">{student.gender}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-500">Date of Birth</p>
                                    <p className="font-medium">{new Date(student.date_of_birth).toLocaleDateString()}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-500">Enrollment Date</p>
                                    <p className="font-medium">{new Date(student.enrollment_date).toLocaleDateString()}</p>
                                </div>
                            </div>
                            {student.email && (
                                <div className="flex items-center gap-2 pt-2">
                                    <Mail className="h-4 w-4 text-gray-500" />
                                    <span>{student.email}</span>
                                </div>
                            )}
                            {student.phone && (
                                <div className="flex items-center gap-2">
                                    <Phone className="h-4 w-4 text-gray-500" />
                                    <span>{student.phone}</span>
                                </div>
                            )}
                            {student.address && (
                                <div className="flex items-start gap-2">
                                    <MapPin className="h-4 w-4 text-gray-500 mt-1" />
                                    <span>{student.address}</span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Academic Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <School className="h-5 w-5" />
                                Academic Information
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm text-gray-500">School</p>
                                <p className="font-medium">{student.school.name}</p>
                            </div>
                            {student.section && (
                                <div>
                                    <p className="text-sm text-gray-500">Section</p>
                                    <p className="font-medium">{student.section.name}</p>
                                </div>
                            )}
                            {student.option && (
                                <div>
                                    <p className="text-sm text-gray-500">Option</p>
                                    <p className="font-medium">{student.option.name}</p>
                                </div>
                            )}
                            {student.level && (
                                <div>
                                    <p className="text-sm text-gray-500">Level</p>
                                    <p className="font-medium">{student.level.name}</p>
                                </div>
                            )}
                            {student.school_class && (
                                <div>
                                    <p className="text-sm text-gray-500">Class</p>
                                    <p className="font-medium">{student.school_class.name}</p>
                                </div>
                            )}
                            <div>
                                <p className="text-sm text-gray-500">Biometric ID</p>
                                <p className="font-mono text-sm">{student.biometric_id}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Guardian Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Users className="h-5 w-5" />
                                Guardian & Emergency Contact
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {student.guardian_name && (
                                <div>
                                    <p className="text-sm text-gray-500">Guardian Name</p>
                                    <p className="font-medium">{student.guardian_name}</p>
                                </div>
                            )}
                            {student.guardian_phone && (
                                <div>
                                    <p className="text-sm text-gray-500">Guardian Phone</p>
                                    <p className="font-medium">{student.guardian_phone}</p>
                                </div>
                            )}
                            {student.emergency_contact_name && (
                                <div className="pt-4 border-t">
                                    <p className="text-sm text-gray-500">Emergency Contact</p>
                                    <p className="font-medium">{student.emergency_contact_name}</p>
                                </div>
                            )}
                            {student.emergency_contact_phone && (
                                <div>
                                    <p className="text-sm text-gray-500">Emergency Phone</p>
                                    <p className="font-medium">{student.emergency_contact_phone}</p>
                                </div>
                            )}
                            {student.medical_info && (
                                <div className="pt-4 border-t">
                                    <p className="text-sm text-gray-500">Medical Information</p>
                                    <p className="font-medium">{student.medical_info}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Parent Link Code */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Link2 className="h-5 w-5" />
                                Parent Link Code
                            </CardTitle>
                            <CardDescription>
                                Parents can use this code to link their accounts
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {student.parent_link_code && (
                                <>
                                    <div className="bg-gray-50 p-4 rounded-lg text-center">
                                        <p className="text-2xl font-mono font-bold tracking-wider">
                                            {student.formatted_link_code || student.parent_link_code}
                                        </p>
                                    </div>
                                    <div className="text-sm text-gray-600">
                                        <p>Status: <Badge variant={student.parent_link_enabled ? "default" : "secondary"}>
                                            {student.parent_link_enabled ? 'Active' : 'Disabled'}
                                        </Badge></p>
                                        {student.parent_link_code_expires_at && (
                                            <p className="mt-2">
                                                Expires: {new Date(student.parent_link_code_expires_at).toLocaleDateString()}
                                            </p>
                                        )}
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Attendance Logs */}
                {student.student_logs && student.student_logs.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Activity className="h-5 w-5" />
                                Recent Attendance Logs
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {student.student_logs.map((log) => (
                                    <div key={log.id} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div className="flex items-center gap-3">
                                            <Badge variant={log.event_type === 'check_in' ? 'default' : 'secondary'}>
                                                {log.event_type === 'check_in' ? 'Check In' : 'Check Out'}
                                            </Badge>
                                            <span className="text-sm">
                                                {new Date(log.created_at).toLocaleString()}
                                            </span>
                                        </div>
                                        {log.biometric_device && (
                                            <span className="text-sm text-gray-600">
                                                {log.biometric_device.name}
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AdminLayout>
    );
}
