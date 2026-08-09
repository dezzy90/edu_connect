import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus, Search, MoreHorizontal, Edit, Eye, Trash2, School, GraduationCap, Target, Building2 } from 'lucide-react';
import { Admin, Section, School as SchoolType } from '@/types';

interface SectionsIndexProps {
    admin: Admin;
    sections: {
        data: (Section & {
            school: {
                id: number;
                name: string;
            };
            options_count?: number;
            levels_count?: number;
            classes_count?: number;
            students_count?: number;
        })[];
        meta?: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
    };
    schools: SchoolType[];
    filters: {
        search?: string;
        school_id?: string;
        is_active?: string;
    };
}

export default function SectionsIndex({ admin, sections, schools, filters }: SectionsIndexProps) {
    const [search, setSearch] = useState(filters.search || '');
    const { delete: destroy } = useForm();
    const isSuper = admin.role === 'super_admin';

    const handleFilter = (key: string, value: string) => {
        router.get('/admin/sections', {
            ...filters,
            [key]: value,
            page: 1,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin/sections', {
            ...filters,
            search,
            page: 1,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        router.get('/admin/sections', {}, {
            preserveState: true,
            replace: true,
        });
        setSearch('');
    };

    const toggleStatus = (section: any) => {
        router.post(`/admin/sections/${section.id}/toggle-status`, {}, {
            preserveState: true,
        });
    };

    const deleteSection = (section: any) => {
        if (confirm('Are you sure you want to delete this section?')) {
            router.delete(`/admin/sections/${section.id}`);
        }
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Academic Sections" />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Academic Sections</h1>
                            <p className="text-gray-600">Manage academic sections across {isSuper ? 'all schools' : 'your school'}</p>
                        </div>
                        <Link href="/admin/sections/create">
                            <Button>
                                <Plus className="h-4 w-4 mr-2" />
                                Add Section
                            </Button>
                        </Link>
                    </div>

                    {/* Statistics */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-blue-100 rounded-lg">
                                        <School className="h-5 w-5 text-blue-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Sections</p>
                                        <p className="text-2xl font-bold text-gray-900">{sections?.meta?.total || 0}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-green-100 rounded-lg">
                                        <GraduationCap className="h-5 w-5 text-green-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Options</p>
                                        <p className="text-2xl font-bold text-gray-900">
                                            {sections?.data?.reduce((sum, section) => sum + (section.options_count || 0), 0) || 0}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-purple-100 rounded-lg">
                                        <Target className="h-5 w-5 text-purple-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Levels</p>
                                        <p className="text-2xl font-bold text-gray-900">
                                            {sections?.data?.reduce((sum, section) => sum + (section.levels_count || 0), 0) || 0}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-orange-100 rounded-lg">
                                        <Building2 className="h-5 w-5 text-orange-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Classes</p>
                                        <p className="text-2xl font-bold text-gray-900">
                                            {sections?.data?.reduce((sum, section) => sum + (section.classes_count || 0), 0) || 0}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Filters */}
                    <Card>
                        <CardContent className="p-4">
                            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <form onSubmit={handleSearch} className="md:col-span-2">
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                                        <Input
                                            placeholder="Search sections..."
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            className="pl-10"
                                        />
                                    </div>
                                </form>
                                
                                {isSuper && (
                                    <Select 
                                        value={filters.school_id || 'all'} 
                                        onValueChange={(value) => handleFilter('school_id', value === 'all' ? '' : value)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="All Schools" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Schools</SelectItem>
                                            {schools.map((school) => (
                                                <SelectItem key={school.id} value={school.id.toString()}>
                                                    {school.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}

                                <Button 
                                    variant="outline" 
                                    onClick={clearFilters}
                                    className="w-full"
                                >
                                    Clear Filters
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Sections Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Sections</CardTitle>
                        <CardDescription>
                            Showing {sections?.data?.length || 0} of {sections?.meta?.total || 0} sections
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    {isSuper && <TableHead>School</TableHead>}
                                    <TableHead className="text-center">Options</TableHead>
                                    <TableHead className="text-center">Levels</TableHead>
                                    <TableHead className="text-center">Classes</TableHead>
                                    <TableHead className="text-center">Students</TableHead>
                                    <TableHead className="text-center">Status</TableHead>
                                    <TableHead className="w-12"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sections?.data && sections.data.length > 0 ? (
                                    sections.data.map((section) => (
                                        <TableRow key={section.id}>
                                            <TableCell>
                                                <div>
                                                    <div className="font-medium text-gray-900">{section.name}</div>
                                                    {section.description && (
                                                        <div className="text-sm text-gray-500 truncate max-w-xs">
                                                            {section.description}
                                                        </div>
                                                    )}
                                                </div>
                                            </TableCell>
                                            {isSuper && (
                                                <TableCell>
                                                    <span className="text-gray-900">{section.school.name}</span>
                                                </TableCell>
                                            )}
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{section.options_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{section.levels_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{section.classes_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{section.students_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant={section.is_active ? "success" : "secondary"}>
                                                    {section.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="sm">
                                                            <MoreHorizontal className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem asChild>
                                                            <Link href={`/admin/sections/${section.id}`}>
                                                                <Eye className="h-4 w-4 mr-2" />
                                                                View
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem asChild>
                                                            <Link href={`/admin/sections/${section.id}/edit`}>
                                                                <Edit className="h-4 w-4 mr-2" />
                                                                Edit
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() => toggleStatus(section)}
                                                        >
                                                            <Edit className="h-4 w-4 mr-2" />
                                                            {section.is_active ? 'Deactivate' : 'Activate'}
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() => deleteSection(section)}
                                                            className="text-red-600"
                                                        >
                                                            <Trash2 className="h-4 w-4 mr-2" />
                                                            Delete
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell colSpan={isSuper ? 8 : 7} className="text-center py-8">
                                            <div className="flex flex-col items-center gap-4 text-gray-500">
                                                <School className="h-12 w-12 text-gray-300" />
                                                <div>
                                                    <p className="font-medium">No sections found</p>
                                                    <p className="text-sm">Get started by creating your first section</p>
                                                </div>
                                                <Link href="/admin/sections/create">
                                                    <Button>Create Section</Button>
                                                </Link>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        {sections?.meta && sections.meta.last_page > 1 && (
                            <div className="flex items-center justify-between mt-6">
                                <p className="text-sm text-gray-600">
                                    Showing {((sections?.meta?.current_page || 1) - 1) * (sections?.meta?.per_page || 10) + 1} to{' '}
                                    {Math.min((sections?.meta?.current_page || 1) * (sections?.meta?.per_page || 10), sections?.meta?.total || 0)} of{' '}
                                    {sections?.meta?.total || 0} results
                                </p>
                                <div className="flex gap-2">
                                    {sections?.meta && sections.meta.current_page > 1 && (
                                        <Link
                                            href={`/admin/sections?${new URLSearchParams({
                                                ...filters,
                                                page: ((sections?.meta?.current_page || 1) - 1).toString(),
                                            })}`}
                                        >
                                            <Button variant="outline" size="sm">Previous</Button>
                                        </Link>
                                    )}
                                    {sections?.meta && sections.meta.current_page < sections.meta.last_page && (
                                        <Link
                                            href={`/admin/sections?${new URLSearchParams({
                                                ...filters,
                                                page: ((sections?.meta?.current_page || 1) + 1).toString(),
                                            })}`}
                                        >
                                            <Button variant="outline" size="sm">Next</Button>
                                        </Link>
                                    )}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}