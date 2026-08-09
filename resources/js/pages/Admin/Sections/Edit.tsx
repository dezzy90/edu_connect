import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Save } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface Section {
    id: number;
    name: string;
    code: string;
    description: string;
    school_id: number;
}

interface School {
    id: number;
    name: string;
}

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
    school_id?: number;
}

interface Props {
    section: Section;
    schools: School[];
    isSuper: boolean;
    admin: AdminUser;
}

export default function Edit({ section, schools, isSuper, admin }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: section.name,
        code: section.code,
        description: section.description || '',
        school_id: section.school_id.toString(),
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/admin/sections/${section.id}`);
    };

    return (
        <AdminLayout admin={admin}>
            <Head title={`Edit Section - ${section.name}`} />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center gap-4 mb-4">
                        <Link href="/admin/sections">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Sections
                            </Button>
                        </Link>
                    </div>
                    <h1 className="text-2xl font-bold text-gray-900">Edit Section: {section.name}</h1>
                    <p className="text-gray-600">Update the details for this academic section.</p>
                </div>

                <div className="max-w-2xl">
                    <Card>
                        <CardHeader>
                            <CardTitle>Section Information</CardTitle>
                            <CardDescription>
                                Update the details for this academic section.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* School Selection (Super Admin Only) */}
                                {isSuper && (
                                    <div className="space-y-2">
                                        <Label htmlFor="school_id">School *</Label>
                                        <Select 
                                            value={data.school_id} 
                                            onValueChange={(value) => setData('school_id', value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select a school" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {schools.map((school) => (
                                                    <SelectItem key={school.id} value={school.id.toString()}>
                                                        {school.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.school_id && (
                                            <p className="text-sm text-red-600">{errors.school_id}</p>
                                        )}
                                    </div>
                                )}

                                {/* Section Name */}
                                <div className="space-y-2">
                                    <Label htmlFor="name">Section Name *</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., General Education, Technical Education"
                                        className={errors.name ? 'border-red-500' : ''}
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-red-600">{errors.name}</p>
                                    )}
                                </div>

                                {/* Section Code */}
                                <div className="space-y-2">
                                    <Label htmlFor="code">Section Code *</Label>
                                    <Input
                                        id="code"
                                        type="text"
                                        value={data.code}
                                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                        placeholder="e.g., GEN, TECH"
                                        maxLength={10}
                                        className={errors.code ? 'border-red-500' : ''}
                                    />
                                    <p className="text-xs text-gray-500">
                                        Short unique code for the section (max 10 characters)
                                    </p>
                                    {errors.code && (
                                        <p className="text-sm text-red-600">{errors.code}</p>
                                    )}
                                </div>

                                {/* Description */}
                                <div className="space-y-2">
                                    <Label htmlFor="description">Description</Label>
                                    <Textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('description', e.target.value)}
                                        placeholder="Optional description of the section..."
                                        rows={3}
                                        className={errors.description ? 'border-red-500' : ''}
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-red-600">{errors.description}</p>
                                    )}
                                </div>

                                {/* Submit Buttons */}
                                <div className="flex items-center justify-end gap-4 pt-6 border-t">
                                    <Link href={`/admin/sections/${section.id}`}>
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        <Save className="h-4 w-4 mr-2" />
                                        {processing ? 'Updating...' : 'Update Section'}
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