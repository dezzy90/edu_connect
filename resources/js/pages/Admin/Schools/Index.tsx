import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { 
    Plus, 
    Search, 
    Users, 
    Smartphone, 
    Eye, 
    Edit, 
    Trash2,
    Building2,
    UserCheck
} from 'lucide-react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface School {
    id: number;
    name: string;
    address: string;
    phone: string;
    email: string;
    director_name: string;
    is_active: boolean;
    students_count: number;
    biometric_devices_count: number;
    admin_users: Array<{
        id: number;
        name: string;
        email: string;
        role: string;
    }>;
    created_at: string;
}

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
}

interface Props {
    schools: {
        data: School[];
        links: any[];
        meta: any;
    };
    admin: AdminUser;
}

export default function SchoolsIndex({ schools, admin }: Props) {
    const [search, setSearch] = useState('');

    const handleSearch = () => {
        router.get('/admin/schools', { search }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = (school: School) => {
        if (confirm(`Are you sure you want to delete ${school.name}?`)) {
            router.delete(`/admin/schools/${school.id}`, {
                onSuccess: () => {
                    // Success message will be handled by the backend
                }
            });
        }
    };

    const toggleStatus = (school: School) => {
        const action = school.is_active ? 'deactivate' : 'activate';
        if (confirm(`Are you sure you want to ${action} ${school.name}?`)) {
            router.post(`/admin/schools/${school.id}/toggle-status`, {}, {
                preserveState: true,
                preserveScroll: true,
            });
        }
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Schools Management" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex justify-between items-start">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Schools Management</h1>
                        <p className="text-gray-600">Manage all schools in the system</p>
                    </div>
                    <Link href="/admin/schools/create">
                        <Button className="flex items-center gap-2">
                            <Plus className="h-4 w-4" />
                            Add School
                        </Button>
                    </Link>
                </div>

                {/* Search and Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle>Search Schools</CardTitle>
                        <CardDescription>
                            Find schools by name, email, or director name
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex gap-4">
                            <div className="flex-1">
                                <Input
                                    placeholder="Search schools..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                />
                            </div>
                            <Button onClick={handleSearch} className="flex items-center gap-2">
                                <Search className="h-4 w-4" />
                                Search
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Schools Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Building2 className="h-5 w-5" />
                            Schools ({schools.meta?.total || 0})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {schools.data.length === 0 ? (
                            <div className="text-center py-8">
                                <Building2 className="mx-auto h-12 w-12 text-gray-400" />
                                <h3 className="mt-2 text-sm font-medium text-gray-900">No schools</h3>
                                <p className="mt-1 text-sm text-gray-500">
                                    Get started by creating a new school.
                                </p>
                                <div className="mt-6">
                                    <Link href="/admin/schools/create">
                                        <Button>
                                            <Plus className="h-4 w-4 mr-2" />
                                            Add School
                                        </Button>
                                    </Link>
                                </div>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>School</TableHead>
                                            <TableHead>Contact</TableHead>
                                            <TableHead>Statistics</TableHead>
                                            <TableHead>Admin Users</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {schools.data.map((school) => (
                                            <TableRow key={school.id}>
                                                <TableCell>
                                                    <div>
                                                        <p className="font-medium text-gray-900">
                                                            {school.name}
                                                        </p>
                                                        {school.director_name && (
                                                            <p className="text-sm text-gray-500">
                                                                Director: {school.director_name}
                                                            </p>
                                                        )}
                                                        {school.address && (
                                                            <p className="text-xs text-gray-500 mt-1">
                                                                {school.address}
                                                            </p>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="text-sm">
                                                        {school.email && (
                                                            <p className="text-gray-900">{school.email}</p>
                                                        )}
                                                        {school.phone && (
                                                            <p className="text-gray-500">{school.phone}</p>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-col gap-1">
                                                        <div className="flex items-center gap-1 text-sm">
                                                            <Users className="h-3 w-3" />
                                                            {school.students_count} students
                                                        </div>
                                                        <div className="flex items-center gap-1 text-sm">
                                                            <Smartphone className="h-3 w-3" />
                                                            {school.biometric_devices_count} devices
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-col gap-1">
                                                        {school.admin_users.map((adminUser) => (
                                                            <Badge key={adminUser.id} variant="outline" className="text-xs">
                                                                {adminUser.name}
                                                            </Badge>
                                                        ))}
                                                        {school.admin_users.length === 0 && (
                                                            <span className="text-xs text-gray-500">No admin</span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge 
                                                        variant={school.is_active ? "default" : "destructive"}
                                                        className="cursor-pointer"
                                                        onClick={() => toggleStatus(school)}
                                                    >
                                                        {school.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <Link href={`/admin/schools/${school.id}`}>
                                                            <Button variant="ghost" size="sm">
                                                                <Eye className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                        <Link href={`/admin/schools/${school.id}/edit`}>
                                                            <Button variant="ghost" size="sm">
                                                                <Edit className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                        <Button 
                                                            variant="ghost" 
                                                            size="sm" 
                                                            onClick={() => handleDelete(school)}
                                                            className="text-red-600 hover:text-red-700"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        {/* Pagination */}
                        {schools.meta?.last_page > 1 && (
                            <div className="mt-6 flex justify-between items-center">
                                <p className="text-sm text-gray-700">
                                    Showing {schools.meta?.from || 0} to {schools.meta?.to || 0} of {schools.meta?.total || 0} results
                                </p>
                                <div className="flex gap-2">
                                    {schools.links.map((link, index) => (
                                        <Button
                                            key={index}
                                            variant={link.active ? "default" : "outline"}
                                            size="sm"
                                            onClick={() => router.get(link.url)}
                                            disabled={!link.url}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}