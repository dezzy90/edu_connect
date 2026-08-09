import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Save } from 'lucide-react';
import { Admin, School } from '@/types';

interface CreateSectionProps {
    admin: Admin;
    schools: School[];
}

export default function CreateSection({ admin, schools }: CreateSectionProps) {
    const isSuper = admin.role === 'super_admin';
    
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        code: '',
        description: '',
        school_id: isSuper ? '' : admin.school_id?.toString() || '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/admin/sections');
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Create Section" />
            
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
                    <h1 className="text-2xl font-bold text-gray-900">Create New Section</h1>
                    <p className="text-gray-600">Add a new academic section to organize options and levels.</p>
                </div>

                <div className="max-w-2xl">
                    <Card>
                        <CardHeader>
                            <CardTitle>Section Information</CardTitle>
                            <CardDescription>
                                Enter the details for the new academic section. Sections are used to organize 
                                different academic streams within your school.
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
                                            <SelectTrigger className={errors.school_id ? 'border-red-500' : ''}>
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
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Science, Arts, Commerce"
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
                                        value={data.code}
                                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                        placeholder="e.g., SCI, ART, COM"
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
                                        placeholder="Describe this academic section..."
                                        rows={3}
                                        className={errors.description ? 'border-red-500' : ''}
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-red-600">{errors.description}</p>
                                    )}
                                </div>

                                {/* Actions */}
                                <div className="flex justify-end gap-4 pt-6 border-t">
                                    <Link href="/admin/sections">
                                        <Button variant="outline" type="button">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        <Save className="h-4 w-4 mr-2" />
                                        {processing ? 'Creating...' : 'Create Section'}
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