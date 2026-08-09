import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { 
    User, 
    Shield, 
    Database, 
    Server, 
    Mail,
    Bell,
    Palette,
    HardDrive,
    Trash2,
    RefreshCw
} from 'lucide-react';

interface School {
    id: number;
    name: string;
    address?: string;
    phone?: string;
    email?: string;
}

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
    school?: School;
    created_at: string;
    last_login_at?: string;
}

interface Props {
    admin: AdminUser;
    school?: School;
    isSuper: boolean;
    systemInfo: {
        php_version: string;
        laravel_version: string;
        database_connection: string;
        cache_driver: string;
        queue_driver: string;
        mail_driver: string;
        storage_disk_usage: {
            used: number;
            total: number;
            percentage: number;
        };
    };
}

export default function Settings({ admin, school, isSuper, systemInfo }: Props) {
    // Profile form
    const profileForm = useForm({
        name: admin.name,
        email: admin.email,
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    // School form (for school admins)
    const schoolForm = useForm({
        name: school?.name || '',
        address: school?.address || '',
        phone: school?.phone || '',
        email: school?.email || '',
    });

    // System settings form (for super admins)
    const systemForm = useForm({
        app_name: 'RodConnect',
        app_url: 'http://localhost:8000',
        mail_from_address: 'admin@rodconnect.com',
        mail_from_name: 'RodConnect Admin',
    });

    const submitProfile = (e: React.FormEvent) => {
        e.preventDefault();
        profileForm.put('/admin/settings/profile');
    };

    const submitSchool = (e: React.FormEvent) => {
        e.preventDefault();
        schoolForm.put('/admin/settings/school');
    };

    const submitSystem = (e: React.FormEvent) => {
        e.preventDefault();
        systemForm.put('/admin/settings/system');
    };

    const handleClearCache = () => {
        if (confirm('Clear application cache?')) {
            // This would trigger a cache clear endpoint
            window.location.href = '/admin/settings/clear-cache';
        }
    };

    const handleOptimize = () => {
        if (confirm('Optimize application performance?')) {
            // This would trigger an optimization endpoint
            window.location.href = '/admin/settings/optimize';
        }
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Settings" />
            
            <div className="space-y-6">
                {/* Header */}
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Settings</h1>
                    <p className="text-muted-foreground">
                        Manage your account and system preferences
                    </p>
                </div>

                <Tabs defaultValue="profile" className="space-y-6">
                    <TabsList className="grid w-full max-w-md grid-cols-4">
                        <TabsTrigger value="profile" className="flex items-center gap-2">
                            <User className="w-4 h-4" />
                            Profile
                        </TabsTrigger>
                        {!isSuper && school && (
                            <TabsTrigger value="school" className="flex items-center gap-2">
                                <Shield className="w-4 h-4" />
                                School
                            </TabsTrigger>
                        )}
                        {isSuper && (
                            <>
                                <TabsTrigger value="system" className="flex items-center gap-2">
                                    <Server className="w-4 h-4" />
                                    System
                                </TabsTrigger>
                                <TabsTrigger value="maintenance" className="flex items-center gap-2">
                                    <Database className="w-4 h-4" />
                                    Maintenance
                                </TabsTrigger>
                            </>
                        )}
                    </TabsList>

                    {/* Profile Settings */}
                    <TabsContent value="profile" className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Profile Information</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submitProfile} className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="name">Full Name</Label>
                                            <Input
                                                id="name"
                                                type="text"
                                                value={profileForm.data.name}
                                                onChange={(e) => profileForm.setData('name', e.target.value)}
                                            />
                                            {profileForm.errors.name && (
                                                <p className="text-sm text-destructive">{profileForm.errors.name}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="email">Email Address</Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                value={profileForm.data.email}
                                                onChange={(e) => profileForm.setData('email', e.target.value)}
                                            />
                                            {profileForm.errors.email && (
                                                <p className="text-sm text-destructive">{profileForm.errors.email}</p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="border-t pt-4">
                                        <h3 className="text-lg font-medium mb-4">Change Password</h3>
                                        <div className="space-y-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="current_password">Current Password</Label>
                                                <Input
                                                    id="current_password"
                                                    type="password"
                                                    value={profileForm.data.current_password}
                                                    onChange={(e) => profileForm.setData('current_password', e.target.value)}
                                                />
                                                {profileForm.errors.current_password && (
                                                    <p className="text-sm text-destructive">{profileForm.errors.current_password}</p>
                                                )}
                                            </div>

                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="password">New Password</Label>
                                                    <Input
                                                        id="password"
                                                        type="password"
                                                        value={profileForm.data.password}
                                                        onChange={(e) => profileForm.setData('password', e.target.value)}
                                                    />
                                                    {profileForm.errors.password && (
                                                        <p className="text-sm text-destructive">{profileForm.errors.password}</p>
                                                    )}
                                                </div>

                                                <div className="space-y-2">
                                                    <Label htmlFor="password_confirmation">Confirm New Password</Label>
                                                    <Input
                                                        id="password_confirmation"
                                                        type="password"
                                                        value={profileForm.data.password_confirmation}
                                                        onChange={(e) => profileForm.setData('password_confirmation', e.target.value)}
                                                    />
                                                    {profileForm.errors.password_confirmation && (
                                                        <p className="text-sm text-destructive">{profileForm.errors.password_confirmation}</p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex justify-end">
                                        <Button type="submit" disabled={profileForm.processing}>
                                            {profileForm.processing ? 'Updating...' : 'Update Profile'}
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>

                        {/* Account Info */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Account Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <Label className="text-sm font-medium">Role</Label>
                                        <div className="mt-1">
                                            <Badge variant={admin.role === 'super_admin' ? 'default' : 'secondary'}>
                                                {admin.role === 'super_admin' ? 'Super Admin' : 'School Admin'}
                                            </Badge>
                                        </div>
                                    </div>

                                    {admin.school && (
                                        <div>
                                            <Label className="text-sm font-medium">School</Label>
                                            <p className="mt-1 text-sm text-muted-foreground">{admin.school.name}</p>
                                        </div>
                                    )}

                                    <div>
                                        <Label className="text-sm font-medium">Member Since</Label>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {new Date(admin.created_at).toLocaleDateString()}
                                        </p>
                                    </div>

                                    {admin.last_login_at && (
                                        <div>
                                            <Label className="text-sm font-medium">Last Login</Label>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {new Date(admin.last_login_at).toLocaleString()}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* School Settings (for school admins) */}
                    {!isSuper && school && (
                        <TabsContent value="school" className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle>School Information</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={submitSchool} className="space-y-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="school_name">School Name</Label>
                                            <Input
                                                id="school_name"
                                                type="text"
                                                value={schoolForm.data.name}
                                                onChange={(e) => schoolForm.setData('name', e.target.value)}
                                            />
                                            {schoolForm.errors.name && (
                                                <p className="text-sm text-destructive">{schoolForm.errors.name}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="school_address">Address</Label>
                                            <textarea
                                                id="school_address"
                                                value={schoolForm.data.address}
                                                onChange={(e) => schoolForm.setData('address', e.target.value)}
                                                className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm min-h-[80px] resize-none"
                                            />
                                            {schoolForm.errors.address && (
                                                <p className="text-sm text-destructive">{schoolForm.errors.address}</p>
                                            )}
                                        </div>

                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="school_phone">Phone</Label>
                                                <Input
                                                    id="school_phone"
                                                    type="text"
                                                    value={schoolForm.data.phone}
                                                    onChange={(e) => schoolForm.setData('phone', e.target.value)}
                                                />
                                                {schoolForm.errors.phone && (
                                                    <p className="text-sm text-destructive">{schoolForm.errors.phone}</p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="school_email">Email</Label>
                                                <Input
                                                    id="school_email"
                                                    type="email"
                                                    value={schoolForm.data.email}
                                                    onChange={(e) => schoolForm.setData('email', e.target.value)}
                                                />
                                                {schoolForm.errors.email && (
                                                    <p className="text-sm text-destructive">{schoolForm.errors.email}</p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex justify-end">
                                            <Button type="submit" disabled={schoolForm.processing}>
                                                {schoolForm.processing ? 'Updating...' : 'Update School Info'}
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    )}

                    {/* System Settings (for super admins) */}
                    {isSuper && (
                        <TabsContent value="system" className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle>System Information</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <Label className="text-sm font-medium">PHP Version</Label>
                                            <p className="mt-1 text-sm text-muted-foreground">{systemInfo?.php_version || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <Label className="text-sm font-medium">Laravel Version</Label>
                                            <p className="mt-1 text-sm text-muted-foreground">{systemInfo?.laravel_version || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <Label className="text-sm font-medium">Database</Label>
                                            <p className="mt-1 text-sm text-muted-foreground">{systemInfo?.database_connection || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <Label className="text-sm font-medium">Cache Driver</Label>
                                            <p className="mt-1 text-sm text-muted-foreground">{systemInfo?.cache_driver || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <Label className="text-sm font-medium">Queue Driver</Label>
                                            <p className="mt-1 text-sm text-muted-foreground">{systemInfo?.queue_driver || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <Label className="text-sm font-medium">Mail Driver</Label>
                                            <p className="mt-1 text-sm text-muted-foreground">{systemInfo?.mail_driver || 'N/A'}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Storage Usage</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        <div className="flex justify-between text-sm">
                                            <span>Disk Usage</span>
                                            <span>{systemInfo?.storage_disk_usage?.percentage || 0}%</span>
                                        </div>
                                        <div className="w-full bg-gray-200 rounded-full h-2">
                                            <div 
                                                className="bg-blue-600 h-2 rounded-full" 
                                            style={{ width: `${systemInfo?.storage_disk_usage?.percentage || 0}%` }}
                                            ></div>
                                        </div>
                                        <div className="flex justify-between text-xs text-muted-foreground">
                                            <span>{systemInfo?.storage_disk_usage?.used || 0} GB used</span>
                                            <span>{systemInfo?.storage_disk_usage?.total || 0} GB total</span>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    )}

                    {/* Maintenance (for super admins) */}
                    {isSuper && (
                        <TabsContent value="maintenance" className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle>System Maintenance</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label className="text-sm font-medium">Cache Management</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Clear application cache to free up storage and ensure fresh data.
                                            </p>
                                            <Button 
                                                variant="outline" 
                                                onClick={handleClearCache}
                                                className="w-full"
                                            >
                                                <Trash2 className="w-4 h-4 mr-2" />
                                                Clear Cache
                                            </Button>
                                        </div>

                                        <div className="space-y-2">
                                            <Label className="text-sm font-medium">Performance Optimization</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Optimize application configuration and routes for better performance.
                                            </p>
                                            <Button 
                                                variant="outline" 
                                                onClick={handleOptimize}
                                                className="w-full"
                                            >
                                                <RefreshCw className="w-4 h-4 mr-2" />
                                                Optimize System
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    )}
                </Tabs>
            </div>
        </AdminLayout>
    );
}