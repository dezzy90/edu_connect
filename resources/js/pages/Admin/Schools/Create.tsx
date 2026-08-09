import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { ArrowLeft, Building2 } from 'lucide-react';

interface Props {
    generatedCode: string;
    timezones: Record<string, string>;
    admin: {
        id: number;
        name: string;
        email: string;
        role: string;
    };
}

export default function SchoolsCreate({ generatedCode, timezones, admin }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        code: generatedCode,
        address: '',
        phone: '',
        email: '',
        logo: null as File | null,
        timezone: 'Africa/Douala',
        is_active: true,
        subscription_expires_at: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/admin/schools');
    };

    const handleLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files[0]) {
            setData('logo', e.target.files[0]);
        }
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Add School" />
            
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/admin/schools">
                            <ArrowLeft className="w-4 h-4 mr-2" />
                            Back to Schools
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight flex items-center gap-2">
                            <Building2 className="h-8 w-8" />
                            Add New School
                        </h1>
                        <p className="text-muted-foreground">
                            Create a new school in the system
                        </p>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-6 md:grid-cols-2">
                        {/* Basic Information */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Basic Information</CardTitle>
                                <CardDescription>
                                    Enter the school's basic details
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="name">School Name *</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Enter school name"
                                        required
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-destructive">{errors.name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="code">School Code *</Label>
                                    <Input
                                        id="code"
                                        type="text"
                                        value={data.code}
                                        onChange={(e) => setData('code', e.target.value)}
                                        placeholder="Auto-generated code"
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        A unique code has been generated. You can modify it if needed.
                                    </p>
                                    {errors.code && (
                                        <p className="text-sm text-destructive">{errors.code}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="school@example.com"
                                    />
                                    {errors.email && (
                                        <p className="text-sm text-destructive">{errors.email}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="phone">Phone Number</Label>
                                    <Input
                                        id="phone"
                                        type="text"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        placeholder="+237 XXX XXX XXX"
                                    />
                                    {errors.phone && (
                                        <p className="text-sm text-destructive">{errors.phone}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="address">Address</Label>
                                    <textarea
                                        id="address"
                                        value={data.address}
                                        onChange={(e) => setData('address', e.target.value)}
                                        placeholder="Enter school address"
                                        className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm min-h-[100px] resize-none"
                                    />
                                    {errors.address && (
                                        <p className="text-sm text-destructive">{errors.address}</p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Additional Settings */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Additional Settings</CardTitle>
                                <CardDescription>
                                    Configure timezone, logo, and subscription
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="timezone">Timezone *</Label>
                                    <select
                                        id="timezone"
                                        value={data.timezone}
                                        onChange={(e) => setData('timezone', e.target.value)}
                                        className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm"
                                        required
                                    >
                                        {Object.entries(timezones).map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.timezone && (
                                        <p className="text-sm text-destructive">{errors.timezone}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="logo">School Logo</Label>
                                    <Input
                                        id="logo"
                                        type="file"
                                        accept="image/jpeg,image/png,image/jpg,image/gif"
                                        onChange={handleLogoChange}
                                        className="cursor-pointer"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Accepted formats: JPEG, PNG, JPG, GIF (Max: 2MB)
                                    </p>
                                    {errors.logo && (
                                        <p className="text-sm text-destructive">{errors.logo}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="subscription_expires_at">Subscription Expiry Date</Label>
                                    <Input
                                        id="subscription_expires_at"
                                        type="date"
                                        value={data.subscription_expires_at}
                                        onChange={(e) => setData('subscription_expires_at', e.target.value)}
                                        min={new Date().toISOString().split('T')[0]}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Leave empty for unlimited subscription
                                    </p>
                                    {errors.subscription_expires_at && (
                                        <p className="text-sm text-destructive">{errors.subscription_expires_at}</p>
                                    )}
                                </div>

                                <div className="flex items-center space-x-2 pt-4">
                                    <input
                                        id="is_active"
                                        type="checkbox"
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                        className="rounded border-input h-4 w-4"
                                    />
                                    <Label htmlFor="is_active" className="cursor-pointer">
                                        Active School
                                    </Label>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Inactive schools cannot be accessed by their users
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Submit Buttons */}
                    <div className="flex gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create School'}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/admin/schools">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
