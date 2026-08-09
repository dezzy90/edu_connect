import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { ArrowLeft, Edit, School, GraduationCap, Target, BookOpen, Users } from 'lucide-react';
import { Admin, Section } from '@/types';

interface ShowSectionProps {
    admin: Admin;
    section: Section & {
        school: {
            id: number;
            name: string;
            address?: string;
        };
        options: Array<{
            id: number;
            name: string;
            code: string;
            type?: string;
            is_active: boolean;
            levels_count?: number;
            classes_count?: number;
            students_count?: number;
        }>;
    };
    stats: {
        total_options: number;
        active_options: number;
        total_levels: number;
        total_classes: number;
    };
}

export default function ShowSection({ admin, section, stats }: ShowSectionProps) {
    return (
        <AdminLayout admin={admin}>
            <Head title={`Section - ${section.name}`} />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <Link href="/admin/sections">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Sections
                            </Button>
                        </Link>
                        <div className="flex gap-2">
                            <Link href={`/admin/sections/${section.id}/edit`}>
                                <Button variant="outline" size="sm">
                                    <Edit className="h-4 w-4 mr-2" />
                                    Edit
                                </Button>
                            </Link>
                        </div>
                    </div>
                    <div className="flex items-center gap-4">
                        <div className="p-3 bg-blue-100 rounded-lg">
                            <School className="h-8 w-8 text-blue-600" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">{section.name}</h1>
                            <p className="text-gray-600">Academic Section Details</p>
                        </div>
                        <Badge variant={section.is_active ? "success" : "secondary"} className="ml-auto">
                            {section.is_active ? 'Active' : 'Inactive'}
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
                                        <GraduationCap className="h-5 w-5 text-blue-600" />
                                        <span className="font-medium text-blue-900">Options</span>
                                    </div>
                                    <span className="text-2xl font-bold text-blue-600">
                                        {stats.total_options}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Target className="h-5 w-5 text-purple-600" />
                                        <span className="font-medium text-purple-900">Levels</span>
                                    </div>
                                    <span className="text-2xl font-bold text-purple-600">
                                        {stats.total_levels}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <BookOpen className="h-5 w-5 text-green-600" />
                                        <span className="font-medium text-green-900">Classes</span>
                                    </div>
                                    <span className="text-2xl font-bold text-green-600">
                                        {stats.total_classes}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-orange-50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Users className="h-5 w-5 text-orange-600" />
                                        <span className="font-medium text-orange-900">Active Options</span>
                                    </div>
                                    <span className="text-2xl font-bold text-orange-600">
                                        {stats.active_options}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Details */}
                    <div className="lg:col-span-2 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Section Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">School</h3>
                                    <div className="flex items-center gap-2">
                                        <p className="text-gray-600">{section.school.name}</p>
                                        {admin.role === 'super_admin' && (
                                            <Link href={`/admin/schools/${section.school.id}`}>
                                                <Button variant="link" size="sm" className="h-auto p-0 text-blue-600">
                                                    View School
                                                </Button>
                                            </Link>
                                        )}
                                    </div>
                                    {section.school.address && (
                                        <p className="text-sm text-gray-500 mt-1">{section.school.address}</p>
                                    )}
                                </div>
                                <Separator />
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">Section Code</h3>
                                    <Badge variant="outline">{section.code}</Badge>
                                </div>
                                {section.description && (
                                    <>
                                        <Separator />
                                        <div>
                                            <h3 className="font-medium text-gray-900 mb-1">Description</h3>
                                            <p className="text-gray-600">{section.description}</p>
                                        </div>
                                    </>
                                )}
                                <Separator />
                                <div>
                                    <h3 className="font-medium text-gray-900 mb-1">Status</h3>
                                    <Badge variant={section.is_active ? "success" : "secondary"}>
                                        {section.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Options */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle>Academic Options</CardTitle>
                                        <CardDescription>
                                            Options within this section
                                        </CardDescription>
                                    </div>
                                    <Link href={`/admin/options/create?section_id=${section.id}`}>
                                        <Button size="sm">
                                            Add Option
                                        </Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {section.options && section.options.length > 0 ? (
                                    <div className="space-y-3">
                                        {section.options.map((option, index) => (
                                            <div key={option.id}>
                                                <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                                    <div className="flex-1">
                                                        <div className="flex items-center gap-3 mb-2">
                                                            <h4 className="font-medium text-gray-900">{option.name}</h4>
                                                            <Badge variant="outline" className="text-xs">
                                                                {option.code}
                                                            </Badge>
                                                            {option.type && (
                                                                <Badge variant="secondary" className="text-xs">
                                                                    {option.type}
                                                                </Badge>
                                                            )}
                                                            <Badge variant={option.is_active ? "success" : "secondary"} className="text-xs">
                                                                {option.is_active ? 'Active' : 'Inactive'}
                                                            </Badge>
                                                        </div>
                                                        <div className="flex items-center gap-4 text-sm text-gray-600">
                                                            <span className="flex items-center gap-1">
                                                                <Target className="h-4 w-4" />
                                                                {option.levels_count || 0} levels
                                                            </span>
                                                            <span className="flex items-center gap-1">
                                                                <BookOpen className="h-4 w-4" />
                                                                {option.classes_count || 0} classes
                                                            </span>
                                                            <span className="flex items-center gap-1">
                                                                <Users className="h-4 w-4" />
                                                                {option.students_count || 0} students
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2 ml-4">
                                                        <Link href={`/admin/options/${option.id}`}>
                                                            <Button variant="outline" size="sm">
                                                                View Details
                                                            </Button>
                                                        </Link>
                                                    </div>
                                                </div>
                                                {index < section.options.length - 1 && (
                                                    <Separator className="my-3" />
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="text-center py-8 text-gray-500">
                                        <GraduationCap className="h-12 w-12 mx-auto mb-4 text-gray-300" />
                                        <p className="font-medium mb-1">No options found for this section</p>
                                        <p className="text-sm mb-4">Get started by creating your first option</p>
                                        <Link href={`/admin/options/create?section_id=${section.id}`}>
                                            <Button size="sm">
                                                Create First Option
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
