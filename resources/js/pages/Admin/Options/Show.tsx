import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { ArrowLeft, Edit, Trash2, GraduationCap, BookOpen, Target } from 'lucide-react';
import { Admin, Option } from '@/types';

interface ShowOptionProps {
    admin: Admin;
    option: Option & {
        section: {
            id: number;
            name: string;
        };
        levels: Array<{
            id: number;
            name: string;
            order: number;
            classes_count: number;
            students_count: number;
        }>;
        statistics: {
            total_levels: number;
            total_classes: number;
            total_students: number;
        };
    };
}

export default function ShowOption({ admin, option }: ShowOptionProps) {
    return (
        <AdminLayout admin={admin}>
            <Head title={`Option - ${option.name}`} />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <Link href="/admin/options">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Options
                            </Button>
                        </Link>
                        <div className="flex gap-2">
                            <Link href={`/admin/options/${option.id}/edit`}>
                                <Button variant="outline" size="sm">
                                    <Edit className="h-4 w-4 mr-2" />
                                    Edit
                                </Button>
                            </Link>
                        </div>
                    </div>
                    <div className="flex items-center gap-4">
                        <div className="p-3 bg-blue-100 rounded-lg">
                            <GraduationCap className="h-8 w-8 text-blue-600" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">{option.name}</h1>
                            <p className="text-gray-600">Academic Option Details</p>
                        </div>
                        <Badge variant={option.is_active ? "success" : "secondary"} className="ml-auto">
                            {option.is_active ? 'Active' : 'Inactive'}
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
                                        <Target className="h-5 w-5 text-blue-600" />
                                        <span className="font-medium text-blue-900">Levels</span>
                                    </div>
                                    <span className="text-2xl font-bold text-blue-600">
                                        {option.statistics.total_levels}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <BookOpen className="h-5 w-5 text-green-600" />
                                        <span className="font-medium text-green-900">Classes</span>
                                    </div>
                                    <span className="text-2xl font-bold text-green-600">
                                        {option.statistics.total_classes}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <GraduationCap className="h-5 w-5 text-purple-600" />
                                        <span className="font-medium text-purple-900">Students</span>
                                    </div>
                                    <span className="text-2xl font-bold text-purple-600">
                                        {option.statistics.total_students}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Details */}
                    <div className="lg:col-span-2 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Option Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">Section</h3>
                                    <p className="text-gray-600">{option.section.name}</p>
                                </div>
                                {option.description && (
                                    <div>
                                        <h3 className="font-medium text-gray-900 mb-1">Description</h3>
                                        <p className="text-gray-600">{option.description}</p>
                                    </div>
                                )}
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">Status</h3>
                                    <Badge variant={option.is_active ? "success" : "secondary"}>
                                        {option.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Levels */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle>Academic Levels</CardTitle>
                                        <CardDescription>
                                            Levels within this option
                                        </CardDescription>
                                    </div>
                                    <Link href={`/admin/levels?option_id=${option.id}`}>
                                        <Button size="sm">
                                            View All Levels
                                        </Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {option.levels.length > 0 ? (
                                    <div className="space-y-3">
                                        {option.levels.map((level, index) => (
                                            <div key={level.id}>
                                                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                                    <div>
                                                        <h4 className="font-medium text-gray-900">{level.name}</h4>
                                                        <p className="text-sm text-gray-600">
                                                            Order: {level.order} • 
                                                            {level.classes_count} classes • 
                                                            {level.students_count} students
                                                        </p>
                                                    </div>
                                                    <Link href={`/admin/levels/${level.id}`}>
                                                        <Button variant="outline" size="sm">
                                                            View
                                                        </Button>
                                                    </Link>
                                                </div>
                                                {index < option.levels.length - 1 && (
                                                    <Separator className="my-3" />
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="text-center py-8 text-gray-500">
                                        <Target className="h-12 w-12 mx-auto mb-4 text-gray-300" />
                                        <p>No levels found for this option.</p>
                                        <Link href="/admin/levels/create" className="mt-2 inline-block">
                                            <Button size="sm">
                                                Create First Level
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