import { FormEventHandler, useEffect } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AlertCircle, UserPlus } from 'lucide-react';

type RegisterForm = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  role: 'super_admin' | 'school_admin' | '';
  school_id: string | null;
};

export default function Register() {
  const { data, setData, post, processing, errors, reset, recentlySuccessful } = useForm<RegisterForm>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: '',
    school_id: null,
  });

  useEffect(() => {
    return () => {
      reset('password', 'password_confirmation');
    };
  }, []);

  const onSubmit: FormEventHandler = (e) => {
    e.preventDefault();
    post('/admin/register');
  };

  const requireSchool = data.role === 'school_admin';

  return (
    <>
      <Head title="Create Admin" />
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100 px-4 py-10">
        <div className="w-full max-w-xl">
          <div className="text-center mb-8">
            <div className="mx-auto w-16 h-16 bg-blue-600 rounded-full flex items-center justify-center mb-4">
              <UserPlus className="w-8 h-8 text-white" />
            </div>
            <h1 className="text-2xl font-bold text-gray-900">Create Admin Account</h1>
            <p className="text-sm text-gray-600 mt-2">Only the first super admin or a current super admin can create accounts.</p>
          </div>

          <Card className="shadow-lg">
            <CardHeader className="space-y-1">
              <CardTitle className="text-xl font-semibold text-center">New Admin</CardTitle>
              <CardDescription className="text-center">
                Fill in the details to create an admin user
              </CardDescription>
            </CardHeader>

            <CardContent>
              {recentlySuccessful && (
                <Alert className="mb-4">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription>Account created successfully.</AlertDescription>
                </Alert>
              )}

              <form onSubmit={onSubmit} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="name">Full Name</Label>
                  <Input
                    id="name"
                    name="name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="Jane Doe"
                  />
                  {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="email">Email</Label>
                  <Input
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    placeholder="admin@school.com"
                  />
                  {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>
                    <Input
                      id="password"
                      type="password"
                      name="password"
                      value={data.password}
                      onChange={(e) => setData('password', e.target.value)}
                      placeholder="********"
                    />
                    {errors.password && <p className="text-sm text-red-600">{errors.password}</p>}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="password_confirmation">Confirm Password</Label>
                    <Input
                      id="password_confirmation"
                      type="password"
                      name="password_confirmation"
                      value={data.password_confirmation}
                      onChange={(e) => setData('password_confirmation', e.target.value)}
                      placeholder="********"
                    />
                    {errors.password_confirmation && (
                      <p className="text-sm text-red-600">{errors.password_confirmation}</p>
                    )}
                  </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Role</Label>
                    <Select
                      value={data.role}
                      onValueChange={(val: 'super_admin' | 'school_admin') => {
                        setData('role', val);
                        if (val === 'super_admin') setData('school_id', null);
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select a role" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="super_admin">Super Admin</SelectItem>
                        <SelectItem value="school_admin">School Admin</SelectItem>
                      </SelectContent>
                    </Select>
                    {errors.role && <p className="text-sm text-red-600">{errors.role}</p>}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="school_id">School (required for School Admin)</Label>
                    <Input
                      id="school_id"
                      name="school_id"
                      value={data.school_id ?? ''}
                      onChange={(e) => setData('school_id', e.target.value)}
                      placeholder="Enter school ID"
                      disabled={!requireSchool}
                    />
                    {errors.school_id && <p className="text-sm text-red-600">{errors.school_id}</p>}
                  </div>
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                  {processing ? 'Creating...' : 'Create Admin'}
                </Button>
              </form>
            </CardContent>
          </Card>

          <div className="text-center mt-6">
            <p className="text-xs text-gray-500">© 2024 Rod Connect. All rights reserved.</p>
          </div>
        </div>
      </div>
    </>
  );
}
