import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ArrowLeft, Edit, Building2, Users, GraduationCap, Calendar, UserPlus } from 'lucide-react';
import { Admin, SchoolClass } from '@/types';

interface ShowClassProps {
    admin: Admin;
    class: SchoolClass & {
        level: {
            id: number;
            name: string;
            option: {
                id: number;
                name: string;
                section: {
                    id: number;
                    name: string;
                };
            };
        };
        students: Array<{
            id: number;
            first_name: string;
            last_name: string;
            student_number: string;
            email?: string;
            phone?: string;
            enrollment_date: string;
        }>;
    };
    stats: {
        total_students: number;
        active_students: number;
        capacity_used: number;
        has_capacity_limit: boolean;
    };
}

export default function ShowClass({ admin, class: schoolClass, stats }: ShowClassProps) {
    return (
        <AdminLayout admin={admin}>
            <Head title={`Class - ${schoolClass.name}`} />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <Link href="/admin/classes">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Classes
                            </Button>
                        </Link>
                        <div className="flex gap-2">
                            <Link href={`/admin/classes/${schoolClass.id}/edit`}>
                                <Button variant="outline" size="sm">
                                    <Edit className="h-4 w-4 mr-2" />
                                    Edit
                                </Button>
                            </Link>
                        </div>
                    </div>
                    <div className="flex items-center gap-4">
                        <div className="p-3 bg-blue-100 rounded-lg">
                            <Building2 className="h-8 w-8 text-blue-600" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">{schoolClass.name}</h1>
                            <p className="text-gray-600">Class Details and Student Management</p>
                        </div>
                        <Badge variant={schoolClass.is_active ? "success" : "secondary"} className="ml-auto">
                            {schoolClass.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Statistics */}
                    <div className="lg:col-span-1">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-lg">Statistics</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Users className="h-5 w-5 text-blue-600" />
                                        <span className="font-medium text-blue-900">Students</span>
                                    </div>
                                    <span className="text-2xl font-bold text-blue-600">
                                        {stats.total_students}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Building2 className="h-5 w-5 text-green-600" />
                                        <span className="font-medium text-green-900">Capacity</span>
                                    </div>
                                    <span className="text-2xl font-bold text-green-600">
                                        {schoolClass.capacity}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-orange-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <GraduationCap className="h-5 w-5 text-orange-600" />
                                        <span className="font-medium text-orange-900">Utilization</span>
                                    </div>
                                    <span className="text-2xl font-bold text-orange-600">
                                        {Math.round(stats.capacity_used)}%
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Users className="h-5 w-5 text-purple-600" />
                                        <span className="font-medium text-purple-900">Active</span>
                                    </div>
                                    <span className="text-2xl font-bold text-purple-600">
                                        {stats.active_students}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Details */}
                    <div className="lg:col-span-2 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Class Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <h3 className="font-medium text-gray-900 mb-1">Level</h3>
                                        <p className="text-gray-600">{schoolClass.level.name}</p>
                                    </div>
                                    <div>
                                        <h3 className="font-medium text-gray-900 mb-1">Option</h3>
                                        <p className="text-gray-600">{schoolClass.level.option.name}</p>
                                    </div>
                                    <div>
                                        <h3 className="font-medium text-gray-900 mb-1">Section</h3>
                                        <p className="text-gray-600">{schoolClass.level.option.section.name}</p>
                                    </div>
                                    <div>
                                        <h3 className="font-medium text-gray-900 mb-1">Academic Year</h3>
                                        <Badge variant="outline">
                                            <Calendar className="h-3 w-3 mr-1" />
                                            {schoolClass.academic_year}
                                        </Badge>
                                    </div>
                                </div>
                                <Separator />
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <h3 className="font-medium text-gray-900 mb-1">Class Code</h3>
                                        <Badge variant="outline">{schoolClass.code}</Badge>
                                    </div>
                                    <div>
                                        <h3 className="font-medium text-gray-900 mb-1">Status</h3>
                                        <Badge variant={schoolClass.is_active ? "success" : "secondary"}>
                                            {schoolClass.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Students */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle>Students</CardTitle>
                                        <CardDescription>
                                            Students enrolled in this class
                                        </CardDescription>
                                    </div>
                                    <Link href={`/admin/students/create?class_id=${schoolClass.id}`}>
                                        <Button size="sm">
                                            <UserPlus className="h-4 w-4 mr-2" />
                                            Add Student
                                        </Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {schoolClass.students && schoolClass.students.length > 0 ? (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Name</TableHead>
                                                <TableHead>Student Number</TableHead>
                                                <TableHead>Contact</TableHead>
                                                <TableHead>Enrollment Date</TableHead>
                                                <TableHead className="w-12"></TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {schoolClass.students.map((student) => (
                                                <TableRow key={student.id}>
                                                    <TableCell>
                                                        <div className="font-medium text-gray-900">
                                                            {student.first_name} {student.last_name}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline">{student.student_number}</Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="text-sm">
                                                            {student.email && (
                                                                <div className="text-gray-600">{student.email}</div>
                                                            )}
                                                            {student.phone && (
                                                                <div className="text-gray-600">{student.phone}</div>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <span className="text-sm text-gray-600">
                                                            {new Date(student.enrollment_date).toLocaleDateString()}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Link href={`/admin/students/${student.id}`}>
                                                            <Button variant="outline" size="sm">
                                                                View
                                                            </Button>
                                                        </Link>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <div className="text-center py-8 text-gray-500">
                                        <Users className="h-12 w-12 mx-auto mb-4 text-gray-300" />
                                        <p className="font-medium mb-1">No students enrolled yet</p>
                                        <p className="text-sm mb-4">Get started by adding your first student</p>
                                        <Link href={`/admin/students/create?class_id=${schoolClass.id}`}>
                                            <Button size="sm">
                                                <UserPlus className="h-4 w-4 mr-2" />
                                                Add First Student
                                            </Button>
                                        </Link>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
