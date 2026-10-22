import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
};
type Profile = {
    id: number;
    student_id: number;
    school_id: number;
    first_name: string;
    last_name: string;
    state: string;
    error_code: string | null;
    attempts: number;
};
type Enrollment = {
    id: number;
    student_id: number;
    school_id: number;
    device_name: string;
    state: string;
    error_code: string | null;
    device_approved: boolean;
    device_last_seen_at: string | null;
    current_version_confirmed: boolean;
    recognition_confirmed_at: string | null;
};
type Props = {
    admin: { name: string; email: string };
    enabled: boolean;
    deviceWritesEnabled: boolean;
    csrfToken: string;
    profiles: Page<Profile> | null;
    enrollments: Page<Enrollment> | null;
};
const label = (value: string) => value.replaceAll('_', ' ');

export default function Index({
    admin,
    enabled,
    deviceWritesEnabled,
    profiles,
    enrollments,
    csrfToken,
}: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const retry = async (url: string) => {
        setBusy(true);
        setError('');
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
            if (!response.ok) {
                const result = await response.json().catch(() => ({}));
                throw new Error(
                    result.message ||
                        'Retry could not be queued. Refresh and check the enrollment status.',
                );
            }
            router.reload();
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Retry could not be queued.',
            );
        } finally {
            setBusy(false);
        }
    };
    const page = Math.max(
        profiles?.current_page ?? 1,
        enrollments?.current_page ?? 1,
    );
    const last = Math.max(
        profiles?.last_page ?? 1,
        enrollments?.last_page ?? 1,
    );
    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <Head title="Face enrollment" />
            <header className="flex flex-wrap items-center justify-between gap-4 border-b bg-white p-6">
                <span>EduConnect · {admin.name}</span>
                <form method="post" action="/admin/logout">
                    <input type="hidden" name="_token" value={csrfToken} />
                    <button className="rounded border px-4 py-2" type="submit">
                        Sign out
                    </button>
                </form>
            </header>
            <main className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Face enrollment
                        </h1>
                        <p className="mt-2 max-w-3xl text-gray-600">
                            Manage student details and reference portraits in
                            EduAdmin. This page tracks delivery to school
                            devices; it does not enroll faces from a terminal.
                        </p>
                    </div>
                    <button
                        className="rounded border px-4 py-2"
                        onClick={() => router.reload()}
                    >
                        Refresh status
                    </button>
                </div>
                {!enabled && (
                    <p className="rounded border border-amber-300 bg-amber-50 p-4">
                        Enrollment is disabled. Deployment and a controlled
                        portrait acceptance test are required before enabling
                        device writes.
                    </p>
                )}
                {enabled && !deviceWritesEnabled && (
                    <p className="rounded border border-blue-300 bg-blue-50 p-4">
                        Enrollment preparation is enabled. Terminal enrollment
                        writes are paused; portraits and synchronization can be
                        checked without changing a device.
                    </p>
                )}
                {error && (
                    <p
                        role="alert"
                        className="rounded border border-red-300 bg-red-50 p-4"
                    >
                        {error}
                    </p>
                )}
                {enabled && (
                    <>
                        <section className="rounded border bg-white p-4">
                            <h2 className="text-lg font-semibold">
                                Application portraits ({profiles?.total ?? 0})
                            </h2>
                            <p className="my-2 text-sm text-gray-600">
                                File preparation is not face acceptance. Missing
                                or rejected photos must be replaced in EduAdmin.
                            </p>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr>
                                            <th className="p-2">
                                                Student / school
                                            </th>
                                            <th className="p-2">
                                                Photo status
                                            </th>
                                            <th className="p-2">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {profiles?.data.map((profile) => (
                                            <tr
                                                key={profile.id}
                                                className="border-t"
                                            >
                                                <td className="p-2">
                                                    {profile.first_name}{' '}
                                                    {profile.last_name}
                                                    <span className="block text-gray-500">
                                                        ID {profile.student_id}{' '}
                                                        · School{' '}
                                                        {profile.school_id}
                                                    </span>
                                                </td>
                                                <td className="p-2">
                                                    {label(profile.state)}
                                                    {profile.error_code && (
                                                        <span className="block text-red-700">
                                                            {label(
                                                                profile.error_code,
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="p-2">
                                                    {profile.state ===
                                                        'photo_unavailable' && (
                                                        <button
                                                            disabled={busy}
                                                            className="rounded border px-3 py-2 disabled:opacity-50"
                                                            onClick={() =>
                                                                retry(
                                                                    `/admin/hikvision-enrollment-photos/${profile.id}/retry`,
                                                                )
                                                            }
                                                        >
                                                            Retry download
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {!profiles?.data.length && (
                                            <tr>
                                                <td colSpan={3} className="p-4">
                                                    No imported enrollment
                                                    profiles yet. Run the
                                                    EduAdmin student sync after
                                                    deployment.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section className="rounded border bg-white p-4">
                            <h2 className="text-lg font-semibold">
                                Device enrollments ({enrollments?.total ?? 0})
                            </h2>
                            <p className="my-2 text-sm text-gray-600">
                                Device ready means the identity and face were
                                acknowledged and read back. Recognition
                                confirmed additionally requires a subsequent
                                matching live event. Neither alone publishes
                                attendance.
                            </p>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr>
                                            <th className="p-2">
                                                Student / device
                                            </th>
                                            <th className="p-2">
                                                Enrollment status
                                            </th>
                                            <th className="p-2">
                                                Connection / approval
                                            </th>
                                            <th className="p-2">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {enrollments?.data.map((enrollment) => (
                                            <tr
                                                key={enrollment.id}
                                                className="border-t"
                                            >
                                                <td className="p-2">
                                                    Student{' '}
                                                    {enrollment.student_id} ·
                                                    School{' '}
                                                    {enrollment.school_id}
                                                    <span className="block text-gray-500">
                                                        {enrollment.device_name}
                                                    </span>
                                                </td>
                                                <td className="p-2">
                                                    {label(enrollment.state)}
                                                    {enrollment.error_code && (
                                                        <span className="block text-red-700">
                                                            {label(
                                                                enrollment.error_code,
                                                            )}
                                                        </span>
                                                    )}
                                                    {enrollment.current_version_confirmed && (
                                                        <span className="block text-green-700">
                                                            Current version
                                                            confirmed
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="p-2">
                                                    {enrollment.device_approved
                                                        ? 'Profile approved'
                                                        : 'Awaiting profile approval'}
                                                    <span className="block text-gray-500">
                                                        Last seen:{' '}
                                                        {enrollment.device_last_seen_at ??
                                                            'never'}{' '}
                                                        (UTC)
                                                    </span>
                                                </td>
                                                <td className="p-2">
                                                    {[
                                                        'device_capacity_exceeded',
                                                        'device_rejected',
                                                        'face_not_confirmed',
                                                    ].includes(
                                                        enrollment.error_code ??
                                                            '',
                                                    ) && (
                                                        <button
                                                            disabled={busy}
                                                            className="rounded border px-3 py-2 disabled:opacity-50"
                                                            onClick={() =>
                                                                retry(
                                                                    `/admin/hikvision-enrollments/${enrollment.id}/retry`,
                                                                )
                                                            }
                                                        >
                                                            Retry enrollment
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {!enrollments?.data.length && (
                                            <tr>
                                                <td colSpan={4} className="p-4">
                                                    No assigned device
                                                    enrollments. A registered
                                                    school device needs an
                                                    enrollment profile.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <nav
                            aria-label="Enrollment pages"
                            className="flex items-center gap-4"
                        >
                            {page > 1 && (
                                <Link
                                    href={`/admin/hikvision-enrollments?page=${page - 1}`}
                                >
                                    Previous
                                </Link>
                            )}
                            <span>
                                Page {page} of {last}
                            </span>
                            {page < last && (
                                <Link
                                    href={`/admin/hikvision-enrollments?page=${page + 1}`}
                                >
                                    Next
                                </Link>
                            )}
                        </nav>
                    </>
                )}
            </main>
        </div>
    );
}
