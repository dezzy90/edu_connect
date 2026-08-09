import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Save } from 'lucide-react';
import { Admin, Option, Section } from '@/types';

interface EditOptionProps {
    admin: Admin;
    option: Option;
    sections: Section[];
}

export default function EditOption({ admin, option, sections }: EditOptionProps) {
    const { data, setData, put, errors, processing } = useForm({
        name: option.name,
        section_id: option.section_id.toString(),
        description: option.description || '',
        is_active: option.is_active,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/admin/options/${option.id}`);
    };

    return (
        <AdminLayout admin={admin}>
            <Head title={`Edit Option - ${option.name}`} />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center gap-4 mb-4">
                        <Link href="/admin/options">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Options
                            </Button>
                        </Link>
                    </div>
                    <h1 className="text-2xl font-bold text-gray-900">Edit Option</h1>
                    <p className="text-gray-600">Update the details for {option.name}.</p>
                </div>

                <div className="max-w-2xl">
                    <Card>
                        <CardHeader>
                            <CardTitle>Option Information</CardTitle>
                            <CardDescription>
                                Update the details for this option.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Option Name *</Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="e.g., Mathematics, Literature"
                                            className={errors.name ? 'border-red-500' : ''}
                                        />
                                        {errors.name && (
                                            <p className="text-sm text-red-600">{errors.name}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="section_id">Section *</Label>
                                        <Select value={data.section_id} onValueChange={(value) => setData('section_id', value)}>
                                            <SelectTrigger className={errors.section_id ? 'border-red-500' : ''}>
                                                <SelectValue placeholder="Select a section" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {sections.map((section) => (
                                                    <SelectItem key={section.id} value={section.id.toString()}>
                                                        {section.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.section_id && (
                                            <p className="text-sm text-red-600">{errors.section_id}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="description">Description</Label>
                                    <Textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Describe this option..."
                                        rows={3}
                                        className={errors.description ? 'border-red-500' : ''}
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-red-600">{errors.description}</p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-4 pt-6 border-t">
                                    <Link href="/admin/options">
                                        <Button variant="outline" type="button">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        <Save className="h-4 w-4 mr-2" />
                                        {processing ? 'Updating...' : 'Update Option'}
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