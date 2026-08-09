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
import { Plus, Search, MoreHorizontal, Edit, Eye, Trash2, GraduationCap, Target, Building2, BookOpen } from 'lucide-react';
import { Admin, Option, Section, School } from '@/types';

interface OptionsIndexProps {
    admin: Admin;
    options: {
        data: (Option & {
            section: {
                id: number;
                name: string;
                school: {
                    id: number;
                    name: string;
                };
            };
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
    sections: Section[];
    schools: School[];
    filters: {
        search?: string;
        school_id?: string;
        section_id?: string;
        is_active?: string;
    };
}

export default function OptionsIndex({ admin, options, sections, schools, filters }: OptionsIndexProps) {
    const [search, setSearch] = useState(filters.search || '');
    const { delete: destroy } = useForm();
    const isSuper = admin.role === 'super_admin';

    const handleFilter = (key: string, value: string) => {
        router.get('/admin/options', {
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
        router.get('/admin/options', {
            ...filters,
            search,
            page: 1,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        router.get('/admin/options', {}, {
            preserveState: true,
            replace: true,
        });
        setSearch('');
    };

    const toggleStatus = (option: any) => {
        router.post(`/admin/options/${option.id}/toggle-status`, {}, {
            preserveState: true,
        });
    };

    const deleteOption = (option: any) => {
        if (confirm('Are you sure you want to delete this option?')) {
            router.delete(`/admin/options/${option.id}`);
        }
    };

    const filteredSections = filters.school_id 
        ? sections.filter(section => section.school_id.toString() === filters.school_id)
        : sections;

    return (
        <AdminLayout admin={admin}>
            <Head title="Academic Options" />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Academic Options</h1>
                            <p className="text-gray-600">Manage academic options within sections across {isSuper ? 'all schools' : 'your school'}</p>
                        </div>
                        <Link href="/admin/options/create">
                            <Button>
                                <Plus className="h-4 w-4 mr-2" />
                                Add Option
                            </Button>
                        </Link>
                    </div>

                    {/* Statistics */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-blue-100 rounded-lg">
                                        <GraduationCap className="h-5 w-5 text-blue-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Options</p>
                                        <p className="text-2xl font-bold text-gray-900">{options?.meta?.total || 0}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-green-100 rounded-lg">
                                        <Target className="h-5 w-5 text-green-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Levels</p>
                                        <p className="text-2xl font-bold text-gray-900">
                                            {options?.data?.reduce((sum, option) => sum + (option.levels_count || 0), 0) || 0}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-purple-100 rounded-lg">
                                        <Building2 className="h-5 w-5 text-purple-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Classes</p>
                                        <p className="text-2xl font-bold text-gray-900">
                                            {options?.data?.reduce((sum, option) => sum + (option.classes_count || 0), 0) || 0}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-orange-100 rounded-lg">
                                        <BookOpen className="h-5 w-5 text-orange-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Students</p>
                                        <p className="text-2xl font-bold text-gray-900">
                                            {options?.data?.reduce((sum, option) => sum + (option.students_count || 0), 0) || 0}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Filters */}
                    <Card>
                        <CardContent className="p-4">
                            <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                                <form onSubmit={handleSearch} className="md:col-span-2">
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                                        <Input
                                            placeholder="Search options..."
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

                                <Select 
                                    value={filters.section_id || 'all'} 
                                    onValueChange={(value) => handleFilter('section_id', value === 'all' ? '' : value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Sections" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Sections</SelectItem>
                                        {filteredSections.map((section) => (
                                            <SelectItem key={section.id} value={section.id.toString()}>
                                                {section.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

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

                {/* Options Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Options</CardTitle>
                        <CardDescription>
                            Showing {options?.data?.length || 0} of {options?.meta?.total || 0} options
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Section</TableHead>
                                    {isSuper && <TableHead>School</TableHead>}
                                    <TableHead className="text-center">Levels</TableHead>
                                    <TableHead className="text-center">Classes</TableHead>
                                    <TableHead className="text-center">Students</TableHead>
                                    <TableHead className="text-center">Status</TableHead>
                                    <TableHead className="w-12"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {options?.data && options.data.length > 0 ? (
                                    options.data.map((option) => (
                                        <TableRow key={option.id}>
                                            <TableCell>
                                                <div>
                                                    <div className="font-medium text-gray-900">{option.name}</div>
                                                    {option.description && (
                                                        <div className="text-sm text-gray-500 truncate max-w-xs">
                                                            {option.description}
                                                        </div>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-gray-900">{option.section?.name || 'N/A'}</span>
                                            </TableCell>
                                            {isSuper && (
                                                <TableCell>
                                                    <span className="text-gray-600">{option.section?.school?.name || 'N/A'}</span>
                                                </TableCell>
                                            )}
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{option.levels_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{option.classes_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{option.students_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant={option.is_active ? "success" : "secondary"}>
                                                    {option.is_active ? 'Active' : 'Inactive'}
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
                                                            <Link href={`/admin/options/${option.id}`}>
                                                                <Eye className="h-4 w-4 mr-2" />
                                                                View
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem asChild>
                                                            <Link href={`/admin/options/${option.id}/edit`}>
                                                                <Edit className="h-4 w-4 mr-2" />
                                                                Edit
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() => toggleStatus(option)}
                                                        >
                                                            <Edit className="h-4 w-4 mr-2" />
                                                            {option.is_active ? 'Deactivate' : 'Activate'}
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() => deleteOption(option)}
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
                                                <GraduationCap className="h-12 w-12 text-gray-300" />
                                                <div>
                                                    <p className="font-medium">No options found</p>
                                                    <p className="text-sm">Get started by creating your first option</p>
                                                </div>
                                                <Link href="/admin/options/create">
                                                    <Button>Create Option</Button>
                                                </Link>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        {options?.meta && options.meta.last_page > 1 && (
                            <div className="flex items-center justify-between mt-6">
                                <p className="text-sm text-gray-600">
                                    Showing {((options?.meta?.current_page || 1) - 1) * (options?.meta?.per_page || 10) + 1} to{' '}
                                    {Math.min((options?.meta?.current_page || 1) * (options?.meta?.per_page || 10), options?.meta?.total || 0)} of{' '}
                                    {options?.meta?.total || 0} results
                                </p>
                                <div className="flex gap-2">
                                    {options?.meta && options.meta.current_page > 1 && (
                                        <Link
                                            href={`/admin/options?${new URLSearchParams({
                                                ...filters,
                                                page: ((options?.meta?.current_page || 1) - 1).toString(),
                                            })}`}
                                        >
                                            <Button variant="outline" size="sm">Previous</Button>
                                        </Link>
                                    )}
                                    {options?.meta && options.meta.current_page < options.meta.last_page && (
                                        <Link
                                            href={`/admin/options?${new URLSearchParams({
                                                ...filters,
                                                page: ((options?.meta?.current_page || 1) + 1).toString(),
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