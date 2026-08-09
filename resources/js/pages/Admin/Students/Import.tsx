import { Head, Link, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { ArrowLeft, Upload, FileSpreadsheet, ImageIcon, AlertCircle, CheckCircle2 } from 'lucide-react';
import { Admin, School, Section, Option, Level } from '@/types';
import axios from 'axios';

interface ImportStudentsProps {
    admin: Admin;
    schools?: School[];
    sections: Section[];
    options: Option[];
    levels: Level[];
}

export default function ImportStudents({ admin, schools, sections: initialSections, options: initialOptions, levels: initialLevels }: ImportStudentsProps) {
    const isSuper = admin.role === 'super_admin';
    
    // Use regular state instead of useForm for better file handling
    const [data, setData] = useState({
        school_id: isSuper ? '' : admin.school_id?.toString() || '',
        section_id: '',
        option_id: '',
        level_id: '',
        class_id: '',
        excel_file: null as File | null,
        student_images: [] as File[],
    });
    
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const [sections, setSections] = useState<Section[]>(initialSections);
    const [options, setOptions] = useState<Option[]>(initialOptions);
    const [levels, setLevels] = useState<Level[]>(initialLevels);
    const [classes, setClasses] = useState<any[]>([]);
    const [loadingSections, setLoadingSections] = useState(false);
    const [loadingOptions, setLoadingOptions] = useState(false);
    const [loadingLevels, setLoadingLevels] = useState(false);
    const [loadingClasses, setLoadingClasses] = useState(false);

    // Load sections when school changes
    useEffect(() => {
        if (isSuper && data.school_id) {
            setLoadingSections(true);
            axios.get(`/api/cascading/sections?school_id=${data.school_id}`)
                .then(response => {
                    setSections(response.data);
                    setData(prev => ({
                        ...prev,
                        section_id: '',
                        option_id: '',
                        level_id: '',
                        class_id: ''
                    }));
                    setOptions([]);
                    setLevels([]);
                    setClasses([]);
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
                    setData(prev => ({
                        ...prev,
                        option_id: '',
                        level_id: '',
                        class_id: ''
                    }));
                    setLevels([]);
                    setClasses([]);
                })
                .catch(error => console.error('Error loading options:', error))
                .finally(() => setLoadingOptions(false));
        } else {
            setOptions([]);
            setData(prev => ({
                ...prev,
                option_id: '',
                level_id: '',
                class_id: ''
            }));
            setLevels([]);
            setClasses([]);
        }
    }, [data.section_id]);

    // Load levels when option changes
    useEffect(() => {
        if (data.option_id) {
            setLoadingLevels(true);
            axios.get(`/api/cascading/levels?option_id=${data.option_id}`)
                .then(response => {
                    setLevels(response.data);
                    setData(prev => ({
                        ...prev,
                        level_id: '',
                        class_id: ''
                    }));
                    setClasses([]);
                })
                .catch(error => console.error('Error loading levels:', error))
                .finally(() => setLoadingLevels(false));
        } else {
            setLevels([]);
            setData(prev => ({
                ...prev,
                level_id: '',
                class_id: ''
            }));
            setClasses([]);
        }
    }, [data.option_id]);

    // Load classes when level changes
    useEffect(() => {
        if (data.level_id) {
            setLoadingClasses(true);
            axios.get(`/api/cascading/classes?level_id=${data.level_id}`)
                .then(response => {
                    setClasses(response.data);
                    setData(prev => ({
                        ...prev,
                        class_id: ''
                    }));
                })
                .catch(error => console.error('Error loading classes:', error))
                .finally(() => setLoadingClasses(false));
        } else {
            setClasses([]);
            setData(prev => ({
                ...prev,
                class_id: ''
            }));
        }
    }, [data.level_id]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});
        
        // Create FormData for file upload
        const formData = new FormData();
        
        // Add all form fields
        if (data.school_id) formData.append('school_id', data.school_id);
        formData.append('section_id', data.section_id);
        formData.append('option_id', data.option_id);
        formData.append('level_id', data.level_id);
        formData.append('class_id', data.class_id);
        
        // Add Excel file
        if (data.excel_file) {
            formData.append('excel_file', data.excel_file);
        }
        
        // Add multiple image files
        data.student_images.forEach((file, index) => {
            formData.append(`student_images[${index}]`, file);
        });
        
        console.log('Submitting form with data:', {
            school_id: data.school_id,
            section_id: data.section_id,
            option_id: data.option_id,
            level_id: data.level_id,
            class_id: data.class_id,
            excel_file: data.excel_file?.name,
            student_images_count: data.student_images.length,
            student_images_names: data.student_images.map(f => f.name)
        });
        
        // Use router.post instead of useForm
        router.post('/admin/students/process-import', formData, {
            preserveScroll: true,
            onError: (errors) => {
                console.error('Import errors:', errors);
                setErrors(errors);
                setProcessing(false);
            },
            onSuccess: () => {
                console.log('Import successful');
                setProcessing(false);
                // Redirect will be handled by the controller
            },
            onFinish: () => {
                setProcessing(false);
            }
        });
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Import Students" />
            
            <div className="p-6">
                <div className="mb-6">
                    <div className="flex items-center gap-4 mb-4">
                        <Link href="/admin/students">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back to Students
                            </Button>
                        </Link>
                    </div>
                    <h1 className="text-2xl font-bold text-gray-900">Import Students</h1>
                    <p className="text-gray-600">Bulk import students from Excel file with optional images</p>
                </div>

                <div className="max-w-3xl">
                    {/* Error Display */}
                    {Object.keys(errors).length > 0 && (
                        <Alert variant="destructive" className="mb-6">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>
                                <div className="font-semibold mb-2">Please fix the following errors:</div>
                                <ul className="list-disc list-inside space-y-1">
                                    {Object.entries(errors).map(([key, value]) => (
                                        <li key={key} className="text-sm">{value}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Instructions Card */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertCircle className="h-5 w-5 text-blue-500" />
                                Import Instructions
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex items-start gap-2">
                                <CheckCircle2 className="h-4 w-4 text-green-500 mt-0.5 flex-shrink-0" />
                                <div>
                                    <strong>Excel File:</strong> Single column with student names (first names only)
                                </div>
                            </div>
                            <div className="flex items-start gap-2">
                                <CheckCircle2 className="h-4 w-4 text-green-500 mt-0.5 flex-shrink-0" />
                                <div>
                                    <strong>Student Images (Optional):</strong> Select multiple image files for students. Images will be matched to students in alphabetical order by filename.
                                </div>
                            </div>
                            <div className="flex items-start gap-2">
                                <CheckCircle2 className="h-4 w-4 text-green-500 mt-0.5 flex-shrink-0" />
                                <div>
                                    <strong>Supported Image Formats:</strong> JPG, JPEG, PNG, GIF, BMP, WEBP (max 2MB each)
                                </div>
                            </div>
                            <div className="flex items-start gap-2">
                                <CheckCircle2 className="h-4 w-4 text-green-500 mt-0.5 flex-shrink-0" />
                                <div>
                                    <strong>Auto-Generated:</strong> Student numbers, parent link codes, and biometric IDs
                                </div>
                            </div>
                            <div className="flex items-start gap-2">
                                <CheckCircle2 className="h-4 w-4 text-green-500 mt-0.5 flex-shrink-0" />
                                <div>
                                    <strong>Image Matching:</strong> 1st image (alphabetically) → 1st student, 2nd image → 2nd student, etc.
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Import Form */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Import Details</CardTitle>
                            <CardDescription>
                                Select the class hierarchy and upload files
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* Step 1: Select School (Super Admin only) */}
                                {isSuper && schools && (
                                    <div className="space-y-2">
                                        <Label htmlFor="school_id">School *</Label>
                                        <Select value={data.school_id} onValueChange={(value) => setData(prev => ({ ...prev, school_id: value }))}>
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
                                        <p className="text-xs text-gray-500">Step 1: Select the school</p>
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
                                        onValueChange={(value) => setData(prev => ({ ...prev, section_id: value }))}
                                        disabled={isSuper && !data.school_id}
                                    >
                                        <SelectTrigger className={errors.section_id ? 'border-red-500' : ''}>
                                            <SelectValue placeholder={loadingSections ? "Loading..." : "Select a section"} />
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
                                        onValueChange={(value) => setData(prev => ({ ...prev, option_id: value }))}
                                        disabled={!data.section_id}
                                    >
                                        <SelectTrigger className={errors.option_id ? 'border-red-500' : ''}>
                                            <SelectValue placeholder={loadingOptions ? "Loading..." : "Select an option"} />
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

                                {/* Step 4: Select Level */}
                                <div className="space-y-2">
                                    <Label htmlFor="level_id">Level *</Label>
                                    <Select 
                                        value={data.level_id} 
                                        onValueChange={(value) => setData(prev => ({ ...prev, level_id: value }))}
                                        disabled={!data.option_id}
                                    >
                                        <SelectTrigger className={errors.level_id ? 'border-red-500' : ''}>
                                            <SelectValue placeholder={loadingLevels ? "Loading..." : "Select a level"} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {levels.map((level) => (
                                                <SelectItem key={level.id} value={level.id.toString()}>
                                                    {level.name} ({level.code})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-gray-500">
                                        {isSuper ? 'Step 4: Select the level' : 'Step 3: Select the level'}
                                    </p>
                                    {errors.level_id && (
                                        <p className="text-sm text-red-600">{errors.level_id}</p>
                                    )}
                                </div>

                                {/* Step 5: Select Class */}
                                <div className="space-y-2">
                                    <Label htmlFor="class_id">Class *</Label>
                                    <Select 
                                        value={data.class_id} 
                                        onValueChange={(value) => setData(prev => ({ ...prev, class_id: value }))}
                                        disabled={!data.level_id}
                                    >
                                        <SelectTrigger className={errors.class_id ? 'border-red-500' : ''}>
                                            <SelectValue placeholder={loadingClasses ? "Loading..." : "Select a class"} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {classes.map((cls) => (
                                                <SelectItem key={cls.id} value={cls.id.toString()}>
                                                    {cls.name} ({cls.code})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-gray-500">
                                        {isSuper ? 'Step 5: Select the class' : 'Step 4: Select the class'}
                                    </p>
                                    {errors.class_id && (
                                        <p className="text-sm text-red-600">{errors.class_id}</p>
                                    )}
                                </div>

                                {/* File Uploads */}
                                <div className="border-t pt-6 space-y-6">
                                    {/* Excel File */}
                                    <div className="space-y-2">
                                        <Label htmlFor="excel_file">
                                            <FileSpreadsheet className="inline h-4 w-4 mr-1" />
                                            Excel File *
                                        </Label>
                                        <Input
                                            id="excel_file"
                                            type="file"
                                            accept=".xlsx,.xls,.csv"
                                            onChange={(e) => setData(prev => ({ ...prev, excel_file: e.target.files?.[0] || null }))}
                                            className={errors.excel_file ? 'border-red-500' : ''}
                                            disabled={!data.class_id}
                                        />
                                        <p className="text-xs text-gray-500">
                                            Upload Excel file with student names (single column)
                                        </p>
                                        {errors.excel_file && (
                                            <p className="text-sm text-red-600">{errors.excel_file}</p>
                                        )}
                                    </div>

                                    {/* Student Images */}
                                    <div className="space-y-2">
                                        <Label htmlFor="student_images">
                                            <ImageIcon className="inline h-4 w-4 mr-1" />
                                            Student Images (Optional)
                                        </Label>
                                        <Input
                                            id="student_images"
                                            type="file"
                                            accept="image/jpeg,image/jpg,image/png,image/gif,image/bmp,image/webp"
                                            multiple
                                            onChange={(e) => {
                                                const files = Array.from(e.target.files || []);
                                                // Sort files alphabetically by name
                                                files.sort((a, b) => a.name.localeCompare(b.name));
                                                setData(prev => ({ ...prev, student_images: files }));
                                            }}
                                            className={errors.student_images ? 'border-red-500' : ''}
                                            disabled={!data.class_id}
                                        />
                                        <p className="text-xs text-gray-500">
                                            Select multiple image files for students. Images will be automatically sorted alphabetically by filename and matched to students in order.
                                            Maximum 2MB per image. Supported formats: JPG, JPEG, PNG, GIF, BMP, WEBP
                                        </p>
                                        {data.student_images.length > 0 && (
                                            <div className="space-y-2">
                                                <p className="text-xs text-blue-600 font-medium">
                                                    Selected {data.student_images.length} image(s) ({(data.student_images.reduce((sum, file) => sum + file.size, 0) / 1024 / 1024).toFixed(2)} MB total)
                                                </p>
                                                <div className="max-h-32 overflow-y-auto bg-gray-50 rounded p-2">
                                                    <div className="text-xs space-y-1">
                                                        {data.student_images.map((file, index) => (
                                                            <div key={index} className="flex justify-between items-center">
                                                                <span className="truncate text-gray-700">
                                                                    {index + 1}. {file.name}
                                                                </span>
                                                                <span className="text-gray-500 ml-2">
                                                                    {(file.size / 1024).toFixed(1)} KB
                                                                </span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                        {errors.student_images && (
                                            <p className="text-sm text-red-600">{errors.student_images}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="flex justify-end gap-4 pt-6 border-t">
                                    <Link href="/admin/students">
                                        <Button variant="outline" type="button">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing || !data.class_id || !data.excel_file}>
                                        <Upload className="h-4 w-4 mr-2" />
                                        {processing ? 'Importing...' : 'Import Students'}
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
