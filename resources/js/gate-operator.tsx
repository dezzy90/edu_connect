import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import GateDevices from './pages/Admin/Gate/Devices';
import GateWorkspace from './pages/Admin/Gate/Index';
import GateSetup from './pages/Admin/Gate/Setup';
import FaceEnrollment from './pages/Admin/HikvisionEnrollments/Index';

// Deliberately isolated from the application's other pages and deployment manifest.
function SessionChanged() {
    return (
        <main className="mx-auto max-w-xl space-y-4 p-6">
            <h1 className="text-2xl font-semibold">Gate session ended</h1>
            <p>Sign in again to reload your current school access.</p>
            <a className="underline" href="/admin/login">
                Return to sign in
            </a>
        </main>
    );
}

createInertiaApp({
    title: (title) => `${title} · EduConnect`,
    resolve: (name) =>
        name === 'Admin/Gate/Index'
            ? GateWorkspace
            : name === 'Admin/Gate/Setup'
              ? GateSetup
              : name === 'Admin/Gate/Devices'
                ? GateDevices
                : name === 'Admin/HikvisionEnrollments/Index'
                  ? FaceEnrollment
                  : SessionChanged,
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
