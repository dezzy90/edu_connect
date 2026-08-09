import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { 
    Table, 
    TableBody, 
    TableCell, 
    TableHead, 
    TableHeader, 
    TableRow 
} from '@/components/ui/table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Search, Plus, MoreHorizontal, Edit, Trash2, Eye, Users } from 'lucide-react';

interface School {
    id: number;
    name: string;
}

interface Student {
    id: number;
    student_id: string;
    first_name: string;
    last_name: string;
    email?: string;
    phone?: string;
    date_of_birth?: string;
    gender: 'male' | 'female';
    school: School;
    school_class?: {
        name: string;
    };
    section?: {
        name: string;
    };
    level?: {
        name: string;
    };
    option?: {
        name: string;
    };
    is_active: boolean;
    created_at: string;
}

interface Props {
    students: {
        data: Student[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    schools: School[];
    filters: {
        search?: string;
        school_id?: string;
        status?: string;
    };
    isSuper: boolean;
    admin: {
        id: number;
        name: string;
        email: string;
        role: string;
        school?: {
            id: number;
            name: string;
        };
    };
}

export default function StudentsIndex({ students, schools, filters, isSuper, admin }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [schoolFilter, setSchoolFilter] = useState(filters.school_id || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin/students', {
            search,
            school_id: schoolFilter,
            status: statusFilter,
        });
    };

    const handleDelete = (student: Student) => {
        if (confirm(`Are you sure you want to delete ${student.first_name} ${student.last_name}?`)) {
            router.delete(`/admin/students/${student.id}`);
        }
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Students" />
            
            <div className="space-y-6">
                {/* Header */}
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Students</h1>
                        <p className="text-muted-foreground">
                            Manage student records and enrollment
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/admin/students/import">
                                <Users className="w-4 h-4 mr-2" />
                                Import Students
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/admin/students/create">
                                <Plus className="w-4 h-4 mr-2" />
                                Add Student
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Stats Cards */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Students</CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{students.total}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Active Students</CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {students.data.filter(s => s.is_active).length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Inactive Students</CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {students.data.filter(s => !s.is_active).length}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={handleSearch} className="flex gap-4 flex-wrap">
                            <div className="flex-1 min-w-[200px]">
                                <div className="relative">
                                    <Search className="absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Search students..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="pl-8"
                                    />
                                </div>
                            </div>
                            
                            {isSuper && (
                                <select
                                    value={schoolFilter}
                                    onChange={(e) => setSchoolFilter(e.target.value)}
                                    className="px-3 py-2 border border-input bg-background rounded-md text-sm"
                                >
                                    <option value="">All Schools</option>
                                    {schools.map(school => (
                                        <option key={school.id} value={school.id}>
                                            {school.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            
                            <select
                                value={statusFilter}
                                onChange={(e) => setStatusFilter(e.target.value)}
                                className="px-3 py-2 border border-input bg-background rounded-md text-sm"
                            >
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            
                            <Button type="submit">Search</Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Students Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Students ({students.total})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Student ID</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Class</TableHead>
                                        {isSuper && <TableHead>School</TableHead>}
                                        <TableHead>Status</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {students.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell 
                                                colSpan={isSuper ? 7 : 6} 
                                                className="text-center py-8"
                                            >
                                                No students found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        students.data.map((student) => (
                                            <TableRow key={student.id}>
                                                <TableCell className="font-medium">
                                                    {student.student_id}
                                                </TableCell>
                                                <TableCell>
                                                    <div>
                                                        <div className="font-medium">
                                                            {student.first_name} {student.last_name}
                                                        </div>
                                                        <div className="text-sm text-muted-foreground">
                                                            {student.gender}
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>{student.email || 'N/A'}</TableCell>
                                                <TableCell>
                                                    <div className="text-sm">
                                                        {student.school_class?.name || 'N/A'}
                                                        {student.section && ` - ${student.section.name}`}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {student.level?.name}
                                                        {student.option && ` • ${student.option.name}`}
                                                    </div>
                                                </TableCell>
                                                {isSuper && (
                                                    <TableCell>{student.school.name}</TableCell>
                                                )}
                                                <TableCell>
                                                    <Badge variant={student.is_active ? 'default' : 'secondary'}>
                                                        {student.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="ghost" className="h-8 w-8 p-0">
                                                                <MoreHorizontal className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem asChild>
                                                                <Link href={`/admin/students/${student.id}`}>
                                                                    <Eye className="mr-2 h-4 w-4" />
                                                                    View
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem asChild>
                                                                <Link href={`/admin/students/${student.id}/edit`}>
                                                                    <Edit className="mr-2 h-4 w-4" />
                                                                    Edit
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem 
                                                                onClick={() => handleDelete(student)}
                                                                className="text-destructive"
                                                            >
                                                                <Trash2 className="mr-2 h-4 w-4" />
                                                                Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Pagination */}
                        {students.last_page > 1 && (
                            <div className="flex items-center justify-between px-2 pt-4">
                                <div className="text-sm text-muted-foreground">
                                    Showing {((students.current_page - 1) * students.per_page) + 1} to{' '}
                                    {Math.min(students.current_page * students.per_page, students.total)} of{' '}
                                    {students.total} results
                                </div>
                                <div className="flex gap-2">
                                    {students.current_page > 1 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/admin/students', {
                                                ...filters,
                                                page: students.current_page - 1
                                            })}
                                        >
                                            Previous
                                        </Button>
                                    )}
                                    {students.current_page < students.last_page && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/admin/students', {
                                                ...filters,
                                                page: students.current_page + 1
                                            })}
                                        >
                                            Next
                                        </Button>
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