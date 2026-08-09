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
    Smartphone, 
    Wifi, 
    WifiOff, 
    RefreshCw, 
    Users, 
    Settings 
} from 'lucide-react';

interface School {
    id: number;
    name: string;
}

interface Device {
    id: number;
    device_id: string;
    name: string;
    mac_address: string;
    ip_address?: string;
    model?: string;
    firmware_version?: string;
    school: School;
    is_active: boolean;
    last_heartbeat?: string;
    created_at: string;
}

interface Props {
    devices: {
        data: Device[];
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

export default function DevicesIndex({ devices, schools, filters, isSuper, admin }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [schoolFilter, setSchoolFilter] = useState(filters.school_id || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin/devices', {
            search,
            school_id: schoolFilter,
            status: statusFilter,
        });
    };

    const handleDelete = (device: Device) => {
        if (confirm(`Are you sure you want to delete device ${device.name}?`)) {
            router.delete(`/admin/devices/${device.id}`);
        }
    };

    const handleSync = (device: Device) => {
        if (confirm(`Sync all students to device ${device.name}?`)) {
            router.post(`/admin/devices/${device.id}/sync-students`);
        }
    };

    const isDeviceOnline = (device: Device) => {
        if (!device.last_heartbeat) return false;
        const lastHeartbeat = new Date(device.last_heartbeat);
        const now = new Date();
        const diffMinutes = (now.getTime() - lastHeartbeat.getTime()) / (1000 * 60);
        return diffMinutes <= 5;
    };

    const getStatusBadge = (device: Device) => {
        if (!device.is_active) {
            return <Badge variant="secondary">Inactive</Badge>;
        }
        
        if (isDeviceOnline(device)) {
            return <Badge variant="default" className="bg-green-500">Online</Badge>;
        }
        
        return <Badge variant="destructive">Offline</Badge>;
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Devices" />
            
            <div className="space-y-6">
                {/* Header */}
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Biometric Devices</h1>
                        <p className="text-muted-foreground">
                            Manage attendance devices and synchronization
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/admin/devices/create">
                            <Plus className="w-4 h-4 mr-2" />
                            Add Device
                        </Link>
                    </Button>
                </div>

                {/* Stats Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Devices</CardTitle>
                            <Smartphone className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{devices.total}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Online</CardTitle>
                            <Wifi className="h-4 w-4 text-green-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {devices.data.filter(d => isDeviceOnline(d)).length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Offline</CardTitle>
                            <WifiOff className="h-4 w-4 text-red-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {devices.data.filter(d => d.is_active && !isDeviceOnline(d)).length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Inactive</CardTitle>
                            <Settings className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {devices.data.filter(d => !d.is_active).length}
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
                                        placeholder="Search devices..."
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
                                <option value="online">Online</option>
                                <option value="offline">Offline</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            
                            <Button type="submit">Search</Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Devices Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Devices ({devices.total})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Device ID</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>MAC Address</TableHead>
                                        <TableHead>IP Address</TableHead>
                                        {isSuper && <TableHead>School</TableHead>}
                                        <TableHead>Status</TableHead>
                                        <TableHead>Last Heartbeat</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {devices.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell 
                                                colSpan={isSuper ? 8 : 7} 
                                                className="text-center py-8"
                                            >
                                                No devices found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        devices.data.map((device) => (
                                            <TableRow key={device.id}>
                                                <TableCell className="font-medium">
                                                    {device.device_id}
                                                </TableCell>
                                                <TableCell>
                                                    <div>
                                                        <div className="font-medium">{device.name}</div>
                                                        {device.model && (
                                                            <div className="text-sm text-muted-foreground">
                                                                {device.model}
                                                            </div>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {device.mac_address}
                                                </TableCell>
                                                <TableCell>
                                                    {device.ip_address || 'N/A'}
                                                </TableCell>
                                                {isSuper && (
                                                    <TableCell>{device.school.name}</TableCell>
                                                )}
                                                <TableCell>
                                                    {getStatusBadge(device)}
                                                </TableCell>
                                                <TableCell>
                                                    {device.last_heartbeat ? (
                                                        <div className="text-sm">
                                                            {new Date(device.last_heartbeat).toLocaleString()}
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground">Never</span>
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
                                                                <Link href={`/admin/devices/${device.id}`}>
                                                                    <Eye className="mr-2 h-4 w-4" />
                                                                    View
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem asChild>
                                                                <Link href={`/admin/devices/${device.id}/edit`}>
                                                                    <Edit className="mr-2 h-4 w-4" />
                                                                    Edit
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem 
                                                                onClick={() => handleSync(device)}
                                                            >
                                                                <RefreshCw className="mr-2 h-4 w-4" />
                                                                Sync Students
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem 
                                                                onClick={() => handleDelete(device)}
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
                        {devices.last_page > 1 && (
                            <div className="flex items-center justify-between px-2 pt-4">
                                <div className="text-sm text-muted-foreground">
                                    Showing {((devices.current_page - 1) * devices.per_page) + 1} to{' '}
                                    {Math.min(devices.current_page * devices.per_page, devices.total)} of{' '}
                                    {devices.total} results
                                </div>
                                <div className="flex gap-2">
                                    {devices.current_page > 1 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/admin/devices', {
                                                ...filters,
                                                page: devices.current_page - 1
                                            })}
                                        >
                                            Previous
                                        </Button>
                                    )}
                                    {devices.current_page < devices.last_page && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/admin/devices', {
                                                ...filters,
                                                page: devices.current_page + 1
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