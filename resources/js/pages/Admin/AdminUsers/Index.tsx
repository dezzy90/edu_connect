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
import { 
    Search, 
    Plus, 
    MoreHorizontal, 
    Edit, 
    Trash2, 
    Eye, 
    Users, 
    Shield, 
    ShieldCheck,
    Clock
} from 'lucide-react';

interface School {
    id: number;
    name: string;
}

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: 'super_admin' | 'school_admin';
    school?: School;
    is_active: boolean;
    last_login_at?: string;
    created_at: string;
}

interface Props {
    admins: {
        data: AdminUser[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    schools: School[];
    filters: {
        search?: string;
        role?: string;
        school_id?: string;
        status?: string;
    };
    admin: any;
}

export default function AdminUsersIndex({ admins, schools, filters, admin }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [roleFilter, setRoleFilter] = useState(filters.role || '');
    const [schoolFilter, setSchoolFilter] = useState(filters.school_id || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin/admin-users', {
            search,
            role: roleFilter,
            school_id: schoolFilter,
            status: statusFilter,
        });
    };

    const handleDelete = (adminUser: AdminUser) => {
        if (confirm(`Are you sure you want to delete admin ${adminUser.name}?`)) {
            router.delete(`/admin/admin-users/${adminUser.id}`);
        }
    };

    const handleToggleStatus = (adminUser: AdminUser) => {
        const action = adminUser.is_active ? 'deactivate' : 'activate';
        if (confirm(`Are you sure you want to ${action} ${adminUser.name}?`)) {
            router.patch(`/admin/admin-users/${adminUser.id}/toggle-status`);
        }
    };

    const getRoleBadge = (role: string) => {
        if (role === 'super_admin') {
            return <Badge variant="default" className="bg-red-500"><ShieldCheck className="w-3 h-3 mr-1" />Super Admin</Badge>;
        }
        return <Badge variant="secondary"><Shield className="w-3 h-3 mr-1" />School Admin</Badge>;
    };

    const getStatusBadge = (isActive: boolean) => {
        return (
            <Badge variant={isActive ? 'default' : 'secondary'}>
                {isActive ? 'Active' : 'Inactive'}
            </Badge>
        );
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Admin Users" />
            
            <div className="space-y-6">
                {/* Header */}
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Admin Users</h1>
                        <p className="text-muted-foreground">
                            Manage system administrators and school admins
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/admin/admin-users/create">
                            <Plus className="w-4 h-4 mr-2" />
                            Add Admin
                        </Link>
                    </Button>
                </div>

                {/* Stats Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Admins</CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{admins?.total || 0}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Super Admins</CardTitle>
                            <ShieldCheck className="h-4 w-4 text-red-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {admins?.data?.filter(a => a.role === 'super_admin').length || 0}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">School Admins</CardTitle>
                            <Shield className="h-4 w-4 text-blue-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {admins?.data?.filter(a => a.role === 'school_admin').length || 0}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Active Admins</CardTitle>
                            <Users className="h-4 w-4 text-green-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {admins?.data?.filter(a => a.is_active).length || 0}
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
                                        placeholder="Search admins..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="pl-8"
                                    />
                                </div>
                            </div>
                            
                            <select
                                value={roleFilter}
                                onChange={(e) => setRoleFilter(e.target.value)}
                                className="px-3 py-2 border border-input bg-background rounded-md text-sm"
                            >
                                <option value="">All Roles</option>
                                <option value="super_admin">Super Admin</option>
                                <option value="school_admin">School Admin</option>
                            </select>
                            
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

                {/* Admins Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Admin Users ({admins?.total || 0})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>School</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Last Login</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {!admins?.data || admins.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center py-8">
                                                No admin users found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        admins.data.map((adminUser) => (
                                            <TableRow key={adminUser.id}>
                                                <TableCell>
                                                    <div className="font-medium">{adminUser.name}</div>
                                                </TableCell>
                                                <TableCell>{adminUser.email}</TableCell>
                                                <TableCell>
                                                    {getRoleBadge(adminUser.role)}
                                                </TableCell>
                                                <TableCell>
                                                    {adminUser.school ? adminUser.school.name : 'N/A'}
                                                </TableCell>
                                                <TableCell>
                                                    {getStatusBadge(adminUser.is_active)}
                                                </TableCell>
                                                <TableCell>
                                                    {adminUser.last_login_at ? (
                                                        <div className="flex items-center gap-1 text-sm">
                                                            <Clock className="w-3 h-3" />
                                                            {new Date(adminUser.last_login_at).toLocaleDateString()}
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground text-sm">Never</span>
                                                    )}
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
                                                                <Link href={`/admin/admin-users/${adminUser.id}`}>
                                                                    <Eye className="mr-2 h-4 w-4" />
                                                                    View
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem asChild>
                                                                <Link href={`/admin/admin-users/${adminUser.id}/edit`}>
                                                                    <Edit className="mr-2 h-4 w-4" />
                                                                    Edit
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem 
                                                                onClick={() => handleToggleStatus(adminUser)}
                                                            >
                                                                {adminUser.is_active ? (
                                                                    <>
                                                                        <Users className="mr-2 h-4 w-4" />
                                                                        Deactivate
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <Users className="mr-2 h-4 w-4" />
                                                                        Activate
                                                                    </>
                                                                )}
                                                            </DropdownMenuItem>
                                                            {adminUser.role !== 'super_admin' && (
                                                                <DropdownMenuItem 
                                                                    onClick={() => handleDelete(adminUser)}
                                                                    className="text-destructive"
                                                                >
                                                                    <Trash2 className="mr-2 h-4 w-4" />
                                                                    Delete
                                                                </DropdownMenuItem>
                                                            )}
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
                        {admins?.last_page > 1 && (
                            <div className="flex items-center justify-between px-2 pt-4">
                                <div className="text-sm text-muted-foreground">
                                    Showing {((admins.current_page - 1) * admins.per_page) + 1} to{' '}
                                    {Math.min(admins.current_page * admins.per_page, admins.total)} of{' '}
                                    {admins.total} results
                                </div>
                                <div className="flex gap-2">
                                    {admins.current_page > 1 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/admin/admin-users', {
                                                ...filters,
                                                page: admins.current_page - 1
                                            })}
                                        >
                                            Previous
                                        </Button>
                                    )}
                                    {admins.current_page < admins.last_page && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/admin/admin-users', {
                                                ...filters,
                                                page: admins.current_page + 1
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
