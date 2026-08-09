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
    MoreHorizontal, 
    Eye, 
    Download, 
    Calendar, 
    Clock, 
    Users, 
    CheckCircle, 
    XCircle,
    AlertCircle
} from 'lucide-react';

interface School {
    id: number;
    name: string;
}

interface Student {
    id: number;
    student_number: string;
    first_name: string;
    last_name: string;
    school: School;
}

interface Device {
    id: number;
    device_id: string;
    name: string;
}

interface AttendanceRecord {
    id: number;
    student: Student;
    device: Device;
    event_type: string;
    confidence_score: number;
    created_at: string;
}

interface Props {
    attendance: {
        data: AttendanceRecord[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    schools: School[];
    devices: Device[];
    filters: {
        search?: string;
        school_id?: string;
        device_id?: string;
        date_from?: string;
        date_to?: string;
        status?: string;
    };
    isSuper: boolean;
    stats: {
        total_today: number;
        verified_today: number;
        unverified_today: number;
        devices_active: number;
    };
    admin: any;
}

export default function AttendanceIndex({ attendance, schools, devices, filters, isSuper, stats, admin }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [schoolFilter, setSchoolFilter] = useState(filters.school_id || '');
    const [deviceFilter, setDeviceFilter] = useState(filters.device_id || '');
    const [dateFromFilter, setDateFromFilter] = useState(filters.date_from || '');
    const [dateToFilter, setDateToFilter] = useState(filters.date_to || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin/attendance', {
            search,
            school_id: schoolFilter,
            device_id: deviceFilter,
            date_from: dateFromFilter,
            date_to: dateToFilter,
            status: statusFilter,
        });
    };

    const handleExport = () => {
        const params = new URLSearchParams({
            search,
            school_id: schoolFilter,
            device_id: deviceFilter,
            date_from: dateFromFilter,
            date_to: dateToFilter,
            status: statusFilter,
            format: 'csv'
        });
        
        window.location.href = `/admin/attendance/export?${params.toString()}`;
    };

    const getStatusBadge = (record: AttendanceRecord) => {
        if (record.confidence_score >= 80) {
            return <Badge variant="default" className="bg-green-500">High Confidence</Badge>;
        } else if (record.confidence_score >= 60) {
            return <Badge variant="secondary" className="bg-yellow-500">Medium Confidence</Badge>;
        } else if (record.confidence_score > 0) {
            return <Badge variant="destructive">Low Confidence</Badge>;
        } else {
            return <Badge variant="secondary">Unknown</Badge>;
        }
    };

    const getConfidenceColor = (confidence: number) => {
        if (confidence >= 80) return 'text-green-600';
        if (confidence >= 60) return 'text-yellow-600';
        return 'text-red-600';
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Attendance" />
            
            <div className="space-y-6">
                {/* Header */}
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Attendance Records</h1>
                        <p className="text-muted-foreground">
                            Monitor and analyze student attendance data
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={handleExport}>
                            <Download className="w-4 h-4 mr-2" />
                            Export
                        </Button>
                    </div>
                </div>

                {/* Stats Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Today's Records</CardTitle>
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.total_today}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Verified Today</CardTitle>
                            <CheckCircle className="h-4 w-4 text-green-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600">{stats.verified_today}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Failed Today</CardTitle>
                            <XCircle className="h-4 w-4 text-red-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600">{stats.unverified_today}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Active Devices</CardTitle>
                            <AlertCircle className="h-4 w-4 text-blue-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.devices_active}</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={handleSearch} className="space-y-4">
                            <div className="flex gap-4 flex-wrap">
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
                                    value={deviceFilter}
                                    onChange={(e) => setDeviceFilter(e.target.value)}
                                    className="px-3 py-2 border border-input bg-background rounded-md text-sm"
                                >
                                    <option value="">All Devices</option>
                                    {devices.map(device => (
                                        <option key={device.id} value={device.id}>
                                            {device.name}
                                        </option>
                                    ))}
                                </select>
                                
                                <select
                                    value={statusFilter}
                                    onChange={(e) => setStatusFilter(e.target.value)}
                                    className="px-3 py-2 border border-input bg-background rounded-md text-sm"
                                >
                                    <option value="">All Status</option>
                                    <option value="verified">Verified</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            
                            <div className="flex gap-4 flex-wrap items-end">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">From Date</label>
                                    <Input
                                        type="date"
                                        value={dateFromFilter}
                                        onChange={(e) => setDateFromFilter(e.target.value)}
                                        className="w-auto"
                                    />
                                </div>
                                
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">To Date</label>
                                    <Input
                                        type="date"
                                        value={dateToFilter}
                                        onChange={(e) => setDateToFilter(e.target.value)}
                                        className="w-auto"
                                    />
                                </div>
                                
                                <Button type="submit">Search</Button>
                                
                                {(search || schoolFilter || deviceFilter || dateFromFilter || dateToFilter || statusFilter) && (
                                    <Button 
                                        type="button" 
                                        variant="outline" 
                                        onClick={() => {
                                            setSearch('');
                                            setSchoolFilter('');
                                            setDeviceFilter('');
                                            setDateFromFilter('');
                                            setDateToFilter('');
                                            setStatusFilter('');
                                            router.get('/admin/attendance');
                                        }}
                                    >
                                        Clear Filters
                                    </Button>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {/* Attendance Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Attendance Records ({attendance.total})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Student</TableHead>
                                        <TableHead>Student ID</TableHead>
                                        {isSuper && <TableHead>School</TableHead>}
                                        <TableHead>Device</TableHead>
                                        <TableHead>Recorded Time</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Confidence</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {attendance.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell 
                                                colSpan={isSuper ? 8 : 7} 
                                                className="text-center py-8"
                                            >
                                                No attendance records found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        attendance.data.map((record) => (
                                            <TableRow key={record.id}>
                                                <TableCell>
                                                    <div className="font-medium">
                                                        {record.student.first_name} {record.student.last_name}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {record.student.student_number}
                                                </TableCell>
                                                {isSuper && (
                                                    <TableCell>{record.student.school?.name || 'N/A'}</TableCell>
                                                )}
                                                <TableCell>
                                                    <div>
                                                        <div className="font-medium">{record.device.name}</div>
                                                        <div className="text-sm text-muted-foreground">
                                                            {record.device.device_id}
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <Clock className="h-4 w-4 text-muted-foreground" />
                                                        <div>
                                                            <div className="font-medium">
                                                                {new Date(record.created_at).toLocaleDateString()}
                                                            </div>
                                                            <div className="text-sm text-muted-foreground">
                                                                {new Date(record.created_at).toLocaleTimeString()}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {getStatusBadge(record)}
                                                </TableCell>
                                                <TableCell>
                                                    <span className={`font-mono text-sm ${getConfidenceColor(record.confidence_score)}`}>
                                                        {record.confidence_score ? `${record.confidence_score}%` : 'N/A'}
                                                    </span>
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
                                                                <Link href={`/admin/attendance/${record.id}`}>
                                                                    <Eye className="mr-2 h-4 w-4" />
                                                                    View Details
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem asChild>
                                                                <Link href={`/admin/students/${record.student.id}`}>
                                                                    <Users className="mr-2 h-4 w-4" />
                                                                    View Student
                                                                </Link>
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
                        {attendance.last_page > 1 && (
                            <div className="flex items-center justify-between px-2 pt-4">
                                <div className="text-sm text-muted-foreground">
                                    Showing {((attendance.current_page - 1) * attendance.per_page) + 1} to{' '}
                                    {Math.min(attendance.current_page * attendance.per_page, attendance.total)} of{' '}
                                    {attendance.total} results
                                </div>
                                <div className="flex gap-2">
                                    {attendance.current_page > 1 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/admin/attendance', {
                                                ...filters,
                                                page: attendance.current_page - 1
                                            })}
                                        >
                                            Previous
                                        </Button>
                                    )}
                                    {attendance.current_page < attendance.last_page && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get('/admin/attendance', {
                                                ...filters,
                                                page: attendance.current_page + 1
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