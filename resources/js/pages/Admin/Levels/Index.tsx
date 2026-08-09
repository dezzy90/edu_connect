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
import { Plus, Search, Filter, MoreHorizontal, Edit, Eye, Trash2, Target, BookOpen, GraduationCap } from 'lucide-react';
import { Admin, Level, Option, Section } from '@/types';

interface LevelsIndexProps {
    admin: Admin;
    levels: {
        data?: (Level & {
            option: {
                id: number;
                name: string;
                section: {
                    id: number;
                    name: string;
                };
            };
            classes_count: number;
            students_count: number;
        })[];
        meta?: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
    };
    sections: Section[];
    options: Option[];
    filters: {
        search?: string;
        section_id?: string;
        option_id?: string;
        is_active?: string;
    };
}

export default function LevelsIndex({ admin, levels, sections, options, filters }: LevelsIndexProps) {
    const [search, setSearch] = useState(filters.search || '');
    const { delete: destroy } = useForm();

    const handleFilter = (key: string, value: string) => {
        router.get('/admin/levels', {
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
        router.get('/admin/levels', {
            ...filters,
            search,
            page: 1,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        router.get('/admin/levels', {}, {
            preserveState: true,
            replace: true,
        });
        setSearch('');
    };

    const deleteLevel = (id: number) => {
        if (confirm('Are you sure you want to delete this level?')) {
            destroy(`/admin/levels/${id}`);
        }
    };

    const filteredOptions = filters.section_id 
        ? (options || []).filter(option => option.section_id.toString() === filters.section_id)
        : (options || []);

    return (
        <AdminLayout admin={admin}>
            <Head title="Academic Levels" />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Academic Levels</h1>
                            <p className="text-gray-600">Manage educational levels within options</p>
                        </div>
                        <Link href="/admin/levels/create">
                            <Button>
                                <Plus className="h-4 w-4 mr-2" />
                                Add Level
                            </Button>
                        </Link>
                    </div>

                    {/* Statistics */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-blue-100 rounded-lg">
                                        <Target className="h-5 w-5 text-blue-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Levels</p>
                                        <p className="text-2xl font-bold text-gray-900">{levels.meta?.total || 0}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-green-100 rounded-lg">
                                        <BookOpen className="h-5 w-5 text-green-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Classes</p>
                                        <p className="text-2xl font-bold text-gray-900">
                                            {levels.data?.reduce((sum, level) => sum + level.classes_count, 0) || 0}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-purple-100 rounded-lg">
                                        <GraduationCap className="h-5 w-5 text-purple-600" />
                                    </div>
                                    <div>
                                        <p className="text-sm text-gray-600">Total Students</p>
                                        <p className="text-2xl font-bold text-gray-900">
                                            {levels.data?.reduce((sum, level) => sum + level.students_count, 0) || 0}
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
                                            placeholder="Search levels..."
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            className="pl-10"
                                        />
                                    </div>
                                </form>
                                
                                <Select 
                                    value={filters.section_id || 'all'} 
                                    onValueChange={(value) => handleFilter('section_id', value === 'all' ? '' : value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Sections" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Sections</SelectItem>
                                        {(sections || []).map((section) => (
                                            <SelectItem key={section.id} value={section.id.toString()}>
                                                {section.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select 
                                    value={filters.option_id || 'all'} 
                                    onValueChange={(value) => handleFilter('option_id', value === 'all' ? '' : value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Options" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Options</SelectItem>
                                        {(filteredOptions || []).map((option) => (
                                            <SelectItem key={option.id} value={option.id.toString()}>
                                                {option.name}
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

                {/* Levels Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Levels</CardTitle>
                        <CardDescription>
                            <p className="text-gray-600">
                            Showing {levels.data?.length || 0} of {levels.meta?.total || 0} levels
                        </p>
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Option</TableHead>
                                    <TableHead>Section</TableHead>
                                    <TableHead>Order</TableHead>
                                    <TableHead className="text-center">Classes</TableHead>
                                    <TableHead className="text-center">Students</TableHead>
                                    <TableHead className="text-center">Status</TableHead>
                                    <TableHead className="w-12"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(levels.data?.length || 0) > 0 ? (
                                    levels.data?.map((level) => (
                                        <TableRow key={level.id}>
                                            <TableCell>
                                                <div>
                                                    <div className="font-medium text-gray-900">{level.name}</div>
                                                    {level.description && (
                                                        <div className="text-sm text-gray-500 truncate max-w-xs">
                                                            {level.description}
                                                        </div>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-gray-900">{level.option.name}</span>
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-gray-600">{level.option.section.name}</span>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{level.order}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{level.classes_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant="secondary">{level.students_count}</Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Badge variant={level.is_active ? "success" : "secondary"}>
                                                    {level.is_active ? 'Active' : 'Inactive'}
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
                                                            <Link href={`/admin/levels/${level.id}`}>
                                                                <Eye className="h-4 w-4 mr-2" />
                                                                View
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem asChild>
                                                            <Link href={`/admin/levels/${level.id}/edit`}>
                                                                <Edit className="h-4 w-4 mr-2" />
                                                                Edit
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() => deleteLevel(level.id)}
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
                                        <TableCell colSpan={8} className="text-center py-8">
                                            <div className="flex flex-col items-center gap-4 text-gray-500">
                                                <Target className="h-12 w-12 text-gray-300" />
                                                <div>
                                                    <p className="font-medium">No levels found</p>
                                                    <p className="text-sm">Get started by creating your first level</p>
                                                </div>
                                                <Link href="/admin/levels/create">
                                                    <Button>Create Level</Button>
                                                </Link>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        {(levels.meta?.last_page || 1) > 1 && (
                            <div className="flex items-center justify-between mt-6">
                                <p className="text-sm text-gray-600">
                                    Showing {(((levels.meta?.current_page || 1) - 1) * (levels.meta?.per_page || 10)) + 1} to{' '}
                                    {Math.min((levels.meta?.current_page || 1) * (levels.meta?.per_page || 10), levels.meta?.total || 0)} of{' '}
                                    {levels.meta?.total || 0} results
                                </p>
                                <div className="flex gap-2">
                                    {(levels.meta?.current_page || 1) > 1 && (
                                        <Link
                                            href={`/admin/levels?${new URLSearchParams({
                                                ...filters,
                                                page: ((levels.meta?.current_page || 1) - 1).toString(),
                                            })}`}
                                        >
                                            <Button variant="outline" size="sm">Previous</Button>
                                        </Link>
                                    )}
                                    {(levels.meta?.current_page || 1) < (levels.meta?.last_page || 1) && (
                                        <Link
                                            href={`/admin/levels?${new URLSearchParams({
                                                ...filters,
                                                page: ((levels.meta?.current_page || 1) + 1).toString(),
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