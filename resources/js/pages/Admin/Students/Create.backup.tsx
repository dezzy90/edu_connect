import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft } from 'lucide-react';

interface School {
    id: number;
    name: string;
}

interface SchoolClass {
    id: number;
    name: string;
}

interface Section {
    id: number;
    name: string;
}

interface Level {
    id: number;
    name: string;
}

interface Option {
    id: number;
    name: string;
}

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
}

interface Props {
    schools: School[];
    classes: SchoolClass[];
    sections: Section[];
    levels: Level[];
    options: Option[];
    isSuper: boolean;
    admin: AdminUser;
}

export default function StudentsCreate({ schools, classes, sections, levels, options, isSuper, admin }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        student_number: '',
        first_name: '',
        last_name: '',
        middle_name: '',
        email: '',
        phone: '',
        date_of_birth: '',
        gender: 'male' as 'male' | 'female',
        school_id: isSuper ? '' : schools[0]?.id || '',
        class_id: '',
        section_id: '',
        level_id: '',
        option_id: '',
        guardian_name: '',
        guardian_phone: '',
        medical_info: '',
        address: '',
        emergency_contact_name: '',
        emergency_contact_phone: '',
        photo: null as File | null,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/admin/students');
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Add Student" />
            
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/admin/students">
                            <ArrowLeft className="w-4 h-4 mr-2" />
                            Back to Students
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Add Student</h1>
                        <p className="text-muted-foreground">
                            Create a new student record with device synchronization
                        </p>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-6 md:grid-cols-2">
                        {/* Basic Information */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Basic Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="student_number">Student Number *</Label>
                                    <Input
                                        id="student_number"
                                        type="text"
                                        value={data.student_number}
                                        onChange={(e) => setData('student_number', e.target.value)}
                                        placeholder="Enter student number"
                                        required
                                    />
                                    {errors.student_number && (
                                        <p className="text-sm text-destructive">{errors.student_number}</p>
                                    )}
                                </div>

                                <div className="grid gap-4 md:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="first_name">First Name *</Label>
                                        <Input
                                            id="first_name"
                                            type="text"
                                            value={data.first_name}
                                            onChange={(e) => setData('first_name', e.target.value)}
                                            placeholder="Enter first name"
                                            required
                                        />
                                        {errors.first_name && (
                                            <p className="text-sm text-destructive">{errors.first_name}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="middle_name">Middle Name</Label>
                                        <Input
                                            id="middle_name"
                                            type="text"
                                            value={data.middle_name}
                                            onChange={(e) => setData('middle_name', e.target.value)}
                                            placeholder="Enter middle name"
                                        />
                                        {errors.middle_name && (
                                            <p className="text-sm text-destructive">{errors.middle_name}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="last_name">Last Name *</Label>
                                        <Input
                                            id="last_name"
                                            type="text"
                                            value={data.last_name}
                                            onChange={(e) => setData('last_name', e.target.value)}
                                            placeholder="Enter last name"
                                            required
                                        />
                                        {errors.last_name && (
                                            <p className="text-sm text-destructive">{errors.last_name}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="date_of_birth">Date of Birth *</Label>
                                        <Input
                                            id="date_of_birth"
                                            type="date"
                                            value={data.date_of_birth}
                                            onChange={(e) => setData('date_of_birth', e.target.value)}
                                            required
                                        />
                                        {errors.date_of_birth && (
                                            <p className="text-sm text-destructive">{errors.date_of_birth}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="gender">Gender *</Label>
                                        <select
                                            id="gender"
                                            value={data.gender}
                                            onChange={(e) => setData('gender', e.target.value as 'male' | 'female')}
                                            className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm"
                                            required
                                        >
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </select>
                                        {errors.gender && (
                                            <p className="text-sm text-destructive">{errors.gender}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            placeholder="Enter email address"
                                        />
                                        {errors.email && (
                                            <p className="text-sm text-destructive">{errors.email}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="phone">Phone</Label>
                                        <Input
                                            id="phone"
                                            type="text"
                                            value={data.phone}
                                            onChange={(e) => setData('phone', e.target.value)}
                                            placeholder="Enter phone number"
                                        />
                                        {errors.phone && (
                                            <p className="text-sm text-destructive">{errors.phone}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="address">Address</Label>
                                        <textarea
                                            id="address"
                                            value={data.address}
                                            onChange={(e) => setData('address', e.target.value)}
                                            placeholder="Enter address"
                                            className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm min-h-[80px] resize-none"
                                        />
                                        {errors.address && (
                                            <p className="text-sm text-destructive">{errors.address}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="medical_info">Medical Information</Label>
                                        <textarea
                                            id="medical_info"
                                            value={data.medical_info}
                                            onChange={(e) => setData('medical_info', e.target.value)}
                                            placeholder="Enter medical information (allergies, conditions, etc.)"
                                            className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm min-h-[80px] resize-none"
                                        />
                                        {errors.medical_info && (
                                            <p className="text-sm text-destructive">{errors.medical_info}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="photo">Student Photo</Label>
                                    <Input
                                        id="photo"
                                        type="file"
                                        accept="image/jpeg,image/jpg,image/png"
                                        onChange={(e) => setData('photo', e.target.files?.[0] || null)}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Max file size: 1MB. Accepted formats: JPG, JPEG, PNG
                                    </p>
                                    {errors.photo && (
                                        <p className="text-sm text-destructive">{errors.photo}</p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Academic Information */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Academic Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {isSuper && (
                                    <div className="space-y-2">
                                        <Label htmlFor="school_id">School *</Label>
                                        <select
                                            id="school_id"
                                            value={data.school_id}
                                            onChange={(e) => setData('school_id', e.target.value)}
                                            className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm"
                                            required
                                        >
                                            <option value="">Select School</option>
                                            {schools.map(school => (
                                                <option key={school.id} value={school.id}>
                                                    {school.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.school_id && (
                                            <p className="text-sm text-destructive">{errors.school_id}</p>
                                        )}
                                    </div>
                                )}

                                <div className="space-y-2">
                                    <Label htmlFor="class_id">Class *</Label>
                                    <select
                                        id="class_id"
                                        value={data.class_id}
                                        onChange={(e) => setData('class_id', e.target.value)}
                                        className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm"
                                        required
                                    >
                                        <option value="">Select Class</option>
                                        {classes.map(cls => (
                                            <option key={cls.id} value={cls.id}>
                                                {cls.name}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.class_id && (
                                        <p className="text-sm text-destructive">{errors.class_id}</p>
                                    )}
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="section_id">Section</Label>
                                        <select
                                            id="section_id"
                                            value={data.section_id}
                                            onChange={(e) => setData('section_id', e.target.value)}
                                            className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm"
                                        >
                                            <option value="">Select Section</option>
                                            {sections.map(section => (
                                                <option key={section.id} value={section.id}>
                                                    {section.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.section_id && (
                                            <p className="text-sm text-destructive">{errors.section_id}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="level_id">Level</Label>
                                        <select
                                            id="level_id"
                                            value={data.level_id}
                                            onChange={(e) => setData('level_id', e.target.value)}
                                            className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm"
                                        >
                                            <option value="">Select Level</option>
                                            {levels.map(level => (
                                                <option key={level.id} value={level.id}>
                                                    {level.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.level_id && (
                                            <p className="text-sm text-destructive">{errors.level_id}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="option_id">Option</Label>
                                    <select
                                        id="option_id"
                                        value={data.option_id}
                                        onChange={(e) => setData('option_id', e.target.value)}
                                        className="w-full px-3 py-2 border border-input bg-background rounded-md text-sm"
                                    >
                                        <option value="">Select Option</option>
                                        {options.map(option => (
                                            <option key={option.id} value={option.id}>
                                                {option.name}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.option_id && (
                                        <p className="text-sm text-destructive">{errors.option_id}</p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Guardian Information */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Guardian Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="guardian_name">Guardian Name</Label>
                                    <Input
                                        id="guardian_name"
                                        type="text"
                                        value={data.guardian_name}
                                        onChange={(e) => setData('guardian_name', e.target.value)}
                                        placeholder="Enter guardian name"
                                    />
                                    {errors.guardian_name && (
                                        <p className="text-sm text-destructive">{errors.guardian_name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="guardian_phone">Guardian Phone</Label>
                                    <Input
                                        id="guardian_phone"
                                        type="text"
                                        value={data.guardian_phone}
                                        onChange={(e) => setData('guardian_phone', e.target.value)}
                                        placeholder="Enter guardian phone"
                                    />
                                    {errors.guardian_phone && (
                                        <p className="text-sm text-destructive">{errors.guardian_phone}</p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Emergency Contact */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Emergency Contact</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="emergency_contact_name">Emergency Contact Name</Label>
                                    <Input
                                        id="emergency_contact_name"
                                        type="text"
                                        value={data.emergency_contact_name}
                                        onChange={(e) => setData('emergency_contact_name', e.target.value)}
                                        placeholder="Enter emergency contact name"
                                    />
                                    {errors.emergency_contact_name && (
                                        <p className="text-sm text-destructive">{errors.emergency_contact_name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="emergency_contact_phone">Emergency Contact Phone</Label>
                                    <Input
                                        id="emergency_contact_phone"
                                        type="text"
                                        value={data.emergency_contact_phone}
                                        onChange={(e) => setData('emergency_contact_phone', e.target.value)}
                                        placeholder="Enter emergency contact phone"
                                    />
                                    {errors.emergency_contact_phone && (
                                        <p className="text-sm text-destructive">{errors.emergency_contact_phone}</p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Submit Buttons */}
                    <div className="flex gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating Student...' : 'Create Student'}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/admin/students">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
