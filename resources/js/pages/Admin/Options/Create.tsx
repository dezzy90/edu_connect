import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Save } from 'lucide-react';
import { Admin, Section } from '@/types';

interface CreateOptionProps {
    admin: Admin;
    sections: Section[];
}

export default function CreateOption({ admin, sections }: CreateOptionProps) {
    const { data, setData, post, errors, processing } = useForm({
        section_id: '',
        name: '',
        code: '',
        type: '',
        description: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/admin/options');
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Create Option" />
            
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
                    <h1 className="text-2xl font-bold text-gray-900">Create New Option</h1>
                    <p className="text-gray-600">Add a new academic option to a section.</p>
                </div>

                <div className="max-w-2xl">
                    <Card>
                        <CardHeader>
                            <CardTitle>Option Information</CardTitle>
                            <CardDescription>
                                First select the section, then enter the option details.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* Step 1: Select Section */}
                                <div className="space-y-2">
                                    <Label htmlFor="section_id">Section *</Label>
                                    <Select value={data.section_id} onValueChange={(value) => setData('section_id', value)}>
                                        <SelectTrigger className={errors.section_id ? 'border-red-500' : ''}>
                                            <SelectValue placeholder="Select a section" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {sections.map((section) => (
                                                <SelectItem key={section.id} value={section.id.toString()}>
                                                    {section.name} ({section.code})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-gray-500">
                                        Select the section this option belongs to
                                    </p>
                                    {errors.section_id && (
                                        <p className="text-sm text-red-600">{errors.section_id}</p>
                                    )}
                                </div>

                                {/* Step 2: Option Details */}
                                <div className="space-y-2">
                                    <Label htmlFor="name">Option Name *</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Mathematics, Biology, Literature"
                                        className={errors.name ? 'border-red-500' : ''}
                                        disabled={!data.section_id}
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-red-600">{errors.name}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div className="space-y-2">
                                        <Label htmlFor="code">Option Code *</Label>
                                        <Input
                                            id="code"
                                            value={data.code}
                                            onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                            placeholder="e.g., MATH, BIO, LIT"
                                            maxLength={10}
                                            className={errors.code ? 'border-red-500' : ''}
                                            disabled={!data.section_id}
                                        />
                                        <p className="text-xs text-gray-500">
                                            Short unique code (max 10 characters)
                                        </p>
                                        {errors.code && (
                                            <p className="text-sm text-red-600">{errors.code}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="type">Type</Label>
                                        <Input
                                            id="type"
                                            value={data.type}
                                            onChange={(e) => setData('type', e.target.value)}
                                            placeholder="e.g., Core, Elective"
                                            className={errors.type ? 'border-red-500' : ''}
                                            disabled={!data.section_id}
                                        />
                                        <p className="text-xs text-gray-500">
                                            Optional classification
                                        </p>
                                        {errors.type && (
                                            <p className="text-sm text-red-600">{errors.type}</p>
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
                                        disabled={!data.section_id}
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
                                    <Button type="submit" disabled={processing || !data.section_id}>
                                        <Save className="h-4 w-4 mr-2" />
                                        {processing ? 'Creating...' : 'Create Option'}
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
