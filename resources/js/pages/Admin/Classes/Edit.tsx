import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Save } from 'lucide-react';
import { Admin, SchoolClass, Level } from '@/types';

interface EditClassProps {
    admin: Admin;
    class: SchoolClass;
    levels: Level[];
}

export default function EditClass({ admin, class: schoolClass, levels }: EditClassProps) {
    const { data, setData, put, errors, processing } = useForm({
        name: schoolClass.name,
        code: schoolClass.code,
        level_id: schoolClass.level_id.toString(),
        capacity: schoolClass.capacity.toString(),
        academic_year: schoolClass.academic_year,
        is_active: schoolClass.is_active,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/admin/classes/${schoolClass.id}`);
    };

    const currentYear = new Date().getFullYear();
    const academicYears = [
        `${currentYear-1}-${currentYear}`,
        `${currentYear}-${currentYear+1}`,
        `${currentYear+1}-${currentYear+2}`
    ];

    return (
        <AdminLayout admin={admin}>
            <Head title={`Edit Class - ${schoolClass.name}`} />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center gap-4 mb-4">
                        <Link href="/admin/classes">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Classes
                            </Button>
                        </Link>
                    </div>
                    <h1 className="text-2xl font-bold text-gray-900">Edit Class</h1>
                    <p className="text-gray-600">Update the details for {schoolClass.name}.</p>
                </div>

                <div className="max-w-2xl">
                    <Card>
                        <CardHeader>
                            <CardTitle>Class Information</CardTitle>
                            <CardDescription>
                                Update the details for this class.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Class Name *</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Class A, Section 1"
                                        className={errors.name ? 'border-red-500' : ''}
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-red-600">{errors.name}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div className="space-y-2">
                                        <Label htmlFor="code">Class Code *</Label>
                                        <Input
                                            id="code"
                                            value={data.code}
                                            onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                            placeholder="e.g., 1A, 2B"
                                            maxLength={10}
                                            className={errors.code ? 'border-red-500' : ''}
                                        />
                                        <p className="text-xs text-gray-500">
                                            Short unique code for the class (max 10 characters)
                                        </p>
                                        {errors.code && (
                                            <p className="text-sm text-red-600">{errors.code}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="capacity">Capacity *</Label>
                                        <Input
                                            id="capacity"
                                            type="number"
                                            value={data.capacity}
                                            onChange={(e) => setData('capacity', e.target.value)}
                                            placeholder="e.g., 30, 40"
                                            className={errors.capacity ? 'border-red-500' : ''}
                                        />
                                        {errors.capacity && (
                                            <p className="text-sm text-red-600">{errors.capacity}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div className="space-y-2">
                                        <Label htmlFor="level_id">Level *</Label>
                                        <Select value={data.level_id} onValueChange={(value) => setData('level_id', value)}>
                                            <SelectTrigger className={errors.level_id ? 'border-red-500' : ''}>
                                                <SelectValue placeholder="Select a level" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {levels.map((level) => (
                                                    <SelectItem key={level.id} value={level.id.toString()}>
                                                        {level.name} ({level.option?.name} - {level.option?.section?.name})
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.level_id && (
                                            <p className="text-sm text-red-600">{errors.level_id}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="academic_year">Academic Year *</Label>
                                        <Select value={data.academic_year} onValueChange={(value) => setData('academic_year', value)}>
                                            <SelectTrigger className={errors.academic_year ? 'border-red-500' : ''}>
                                                <SelectValue placeholder="Select academic year" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {academicYears.map((year) => (
                                                    <SelectItem key={year} value={year}>
                                                        {year}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.academic_year && (
                                            <p className="text-sm text-red-600">{errors.academic_year}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="flex justify-end gap-4 pt-6 border-t">
                                    <Link href="/admin/classes">
                                        <Button variant="outline" type="button">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        <Save className="h-4 w-4 mr-2" />
                                        {processing ? 'Updating...' : 'Update Class'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}