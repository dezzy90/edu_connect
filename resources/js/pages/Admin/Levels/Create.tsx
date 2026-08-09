import { Head, Link, useForm } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Save } from 'lucide-react';
import { Admin, School, Section, Option } from '@/types';
import axios from 'axios';

interface CreateLevelProps {
    admin: Admin;
    schools?: School[];
    sections: Section[];
    options: Option[];
}

export default function CreateLevel({ admin, schools, sections: initialSections, options: initialOptions }: CreateLevelProps) {
    const isSuper = admin.role === 'super_admin';
    
    const { data, setData, post, errors, processing } = useForm({
        school_id: isSuper ? '' : admin.school_id?.toString() || '',
        section_id: '',
        option_id: '',
        name: '',
        code: '',
        order: '1',
        description: '',
    });

    const [sections, setSections] = useState<Section[]>(initialSections);
    const [options, setOptions] = useState<Option[]>(initialOptions);
    const [loadingSections, setLoadingSections] = useState(false);
    const [loadingOptions, setLoadingOptions] = useState(false);

    // Load sections when school changes
    useEffect(() => {
        if (isSuper && data.school_id) {
            setLoadingSections(true);
            axios.get(`/api/cascading/sections?school_id=${data.school_id}`)
                .then(response => {
                    setSections(response.data);
                    setData('section_id', '');
                    setData('option_id', '');
                    setOptions([]);
                })
                .catch(error => console.error('Error loading sections:', error))
                .finally(() => setLoadingSections(false));
        }
    }, [data.school_id]);

    // Load options when section changes
    useEffect(() => {
        if (data.section_id) {
            setLoadingOptions(true);
            axios.get(`/api/cascading/options?section_id=${data.section_id}`)
                .then(response => {
                    setOptions(response.data);
                    setData('option_id', '');
                })
                .catch(error => console.error('Error loading options:', error))
                .finally(() => setLoadingOptions(false));
        } else {
            setOptions([]);
            setData('option_id', '');
        }
    }, [data.section_id]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/admin/levels');
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Create Level" />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center gap-4 mb-4">
                        <Link href="/admin/levels">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Levels
                            </Button>
                        </Link>
                    </div>
                    <h1 className="text-2xl font-bold text-gray-900">Create New Level</h1>
                    <p className="text-gray-600">Add a new academic level to an option.</p>
                </div>

                <div className="max-w-2xl">
                    <Card>
                        <CardHeader>
                            <CardTitle>Level Information</CardTitle>
                            <CardDescription>
                                Follow the hierarchy: {isSuper ? 'School → ' : ''}Section → Option → Level
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* Step 1: Select School (Super Admin only) */}
                                {isSuper && schools && (
                                    <div className="space-y-2">
                                        <Label htmlFor="school_id">School *</Label>
                                        <Select value={data.school_id} onValueChange={(value) => setData('school_id', value)}>
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
                                        <p className="text-xs text-gray-500">
                                            Step 1: Select the school
                                        </p>
                                        {errors.school_id && (
                                            <p className="text-sm text-red-600">{errors.school_id}</p>
                                        )}
                                    </div>
                                )}

                                {/* Step 2: Select Section */}
                                <div className="space-y-2">
                                    <Label htmlFor="section_id">Section *</Label>
                                    <Select 
                                        value={data.section_id} 
                                        onValueChange={(value) => setData('section_id', value)}
                                        disabled={isSuper && !data.school_id}
                                    >
                                        <SelectTrigger className={errors.section_id ? 'border-red-500' : ''}>
                                            <SelectValue placeholder={loadingSections ? "Loading sections..." : "Select a section"} />
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
                                        {isSuper ? 'Step 2: Select the section' : 'Step 1: Select the section'}
                                    </p>
                                    {errors.section_id && (
                                        <p className="text-sm text-red-600">{errors.section_id}</p>
                                    )}
                                </div>

                                {/* Step 3: Select Option */}
                                <div className="space-y-2">
                                    <Label htmlFor="option_id">Option *</Label>
                                    <Select 
                                        value={data.option_id} 
                                        onValueChange={(value) => setData('option_id', value)}
                                        disabled={!data.section_id}
                                    >
                                        <SelectTrigger className={errors.option_id ? 'border-red-500' : ''}>
                                            <SelectValue placeholder={loadingOptions ? "Loading options..." : "Select an option"} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.map((option) => (
                                                <SelectItem key={option.id} value={option.id.toString()}>
                                                    {option.name} ({option.code})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-gray-500">
                                        {isSuper ? 'Step 3: Select the option' : 'Step 2: Select the option'}
                                    </p>
                                    {errors.option_id && (
                                        <p className="text-sm text-red-600">{errors.option_id}</p>
                                    )}
                                </div>

                                {/* Step 4: Level Details */}
                                <div className="space-y-2">
                                    <Label htmlFor="name">Level Name *</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Form 1, Year 1, Grade 10"
                                        className={errors.name ? 'border-red-500' : ''}
                                        disabled={!data.option_id}
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-red-600">{errors.name}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div className="space-y-2">
                                        <Label htmlFor="code">Level Code *</Label>
                                        <Input
                                            id="code"
                                            value={data.code}
                                            onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                            placeholder="e.g., F1, Y1, G10"
                                            maxLength={10}
                                            className={errors.code ? 'border-red-500' : ''}
                                            disabled={!data.option_id}
                                        />
                                        <p className="text-xs text-gray-500">
                                            Short unique code (max 10 characters)
                                        </p>
                                        {errors.code && (
                                            <p className="text-sm text-red-600">{errors.code}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="order">Display Order *</Label>
                                        <Input
                                            id="order"
                                            type="number"
                                            min="1"
                                            value={data.order}
                                            onChange={(e) => setData('order', e.target.value)}
                                            placeholder="1"
                                            className={errors.order ? 'border-red-500' : ''}
                                            disabled={!data.option_id}
                                        />
                                        <p className="text-xs text-gray-500">
                                            Order for sorting (1, 2, 3...)
                                        </p>
                                        {errors.order && (
                                            <p className="text-sm text-red-600">{errors.order}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="description">Description</Label>
                                    <Textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Describe this level..."
                                        rows={3}
                                        className={errors.description ? 'border-red-500' : ''}
                                        disabled={!data.option_id}
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-red-600">{errors.description}</p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-4 pt-6 border-t">
                                    <Link href="/admin/levels">
                                        <Button variant="outline" type="button">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing || !data.option_id}>
                                        <Save className="h-4 w-4 mr-2" />
                                        {processing ? 'Creating...' : 'Create Level'}
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
