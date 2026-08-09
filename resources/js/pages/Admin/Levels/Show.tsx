import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { ArrowLeft, Edit, Target, BookOpen, GraduationCap, Building2 } from 'lucide-react';
import { Admin, Level } from '@/types';

interface ShowLevelProps {
    admin: Admin;
    level: Level & {
        option: {
            id: number;
            name: string;
            section: {
                id: number;
                name: string;
            };
        };
        classes: Array<{
            id: number;
            name: string;
            capacity: number;
            students_count: number;
            academic_year: string;
        }>;
        statistics: {
            total_classes: number;
            total_students: number;
            capacity_utilization: number;
        };
    };
}

export default function ShowLevel({ admin, level }: ShowLevelProps) {
    return (
        <AdminLayout admin={admin}>
            <Head title={`Level - ${level.name}`} />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <Link href="/admin/levels">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Levels
                            </Button>
                        </Link>
                        <div className="flex gap-2">
                            <Link href={`/admin/levels/${level.id}/edit`}>
                                <Button variant="outline" size="sm">
                                    <Edit className="h-4 w-4 mr-2" />
                                    Edit
                                </Button>
                            </Link>
                        </div>
                    </div>
                    <div className="flex items-center gap-4">
                        <div className="p-3 bg-purple-100 rounded-lg">
                            <Target className="h-8 w-8 text-purple-600" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">{level.name}</h1>
                            <p className="text-gray-600">Academic Level Details</p>
                        </div>
                        <Badge variant={level.is_active ? "success" : "secondary"} className="ml-auto">
                            {level.is_active ? 'Active' : 'Inactive'}
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
                                        <BookOpen className="h-5 w-5 text-blue-600" />
                                        <span className="font-medium text-blue-900">Classes</span>
                                    </div>
                                    <span className="text-2xl font-bold text-blue-600">
                                        {level.statistics.total_classes}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <GraduationCap className="h-5 w-5 text-green-600" />
                                        <span className="font-medium text-green-900">Students</span>
                                    </div>
                                    <span className="text-2xl font-bold text-green-600">
                                        {level.statistics.total_students}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-orange-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Building2 className="h-5 w-5 text-orange-600" />
                                        <span className="font-medium text-orange-900">Capacity</span>
                                    </div>
                                    <span className="text-2xl font-bold text-orange-600">
                                        {level.statistics.capacity_utilization}%
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Details */}
                    <div className="lg:col-span-2 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Level Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">Option</h3>
                                    <p className="text-gray-600">{level.option.name}</p>
                                </div>
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">Section</h3>
                                    <p className="text-gray-600">{level.option.section.name}</p>
                                </div>
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">Order</h3>
                                    <Badge variant="outline">{level.order}</Badge>
                                </div>
                                {level.description && (
                                    <div>
                                        <h3 className="font-medium text-gray-900 mb-1">Description</h3>
                                        <p className="text-gray-600">{level.description}</p>
                                    </div>
                                )}
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">Status</h3>
                                    <Badge variant={level.is_active ? "success" : "secondary"}>
                                        {level.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Classes */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle>Classes</CardTitle>
                                        <CardDescription>
                                            Classes within this level
                                        </CardDescription>
                                    </div>
                                    <Link href={`/admin/classes?level_id=${level.id}`}>
                                        <Button size="sm">
                                            View All Classes
                                        </Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {level.classes.length > 0 ? (
                                    <div className="space-y-3">
                                        {level.classes.map((classItem, index) => (
                                            <div key={classItem.id}>
                                                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                                    <div>
                                                        <h4 className="font-medium text-gray-900">{classItem.name}</h4>
                                                        <p className="text-sm text-gray-600">
                                                            Academic Year: {classItem.academic_year} • 
                                                            Capacity: {classItem.capacity} • 
                                                            Students: {classItem.students_count}
                                                        </p>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Badge variant="outline">
                                                            {Math.round((classItem.students_count / classItem.capacity) * 100)}% Full
                                                        </Badge>
                                                        <Link href={`/admin/classes/${classItem.id}`}>
                                                            <Button variant="outline" size="sm">
                                                                View
                                                            </Button>
                                                        </Link>
                                                    </div>
                                                </div>
                                                {index < level.classes.length - 1 && (
                                                    <Separator className="my-3" />
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="text-center py-8 text-gray-500">
                                        <BookOpen className="h-12 w-12 mx-auto mb-4 text-gray-300" />
                                        <p>No classes found for this level.</p>
                                        <Link href="/admin/classes/create" className="mt-2 inline-block">
                                            <Button size="sm">
                                                Create First Class
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