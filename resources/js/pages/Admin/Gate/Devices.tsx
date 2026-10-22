import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    Cable,
    Check,
    ChevronRight,
    Clipboard,
    DoorOpen,
    Fingerprint,
    Info,
    Monitor,
    Plus,
    RefreshCw,
    Search,
    ShieldCheck,
    X,
} from 'lucide-react';
import { FormEvent, ReactNode, useEffect, useRef, useState } from 'react';

type School = {
    school_id: number;
    tenant_id: number;
    name: string;
    complex_name: string;
    eduadmin_school_id: string | null;
    roster_total: number;
};
type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total?: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};
type Connection = {
    configured: boolean;
    server_host: string | null;
    protocol: string;
    https_port: number | null;
    websocket_port: number | null;
    receiver_mode: string;
    may_enable: boolean;
    message: string;
    username?: string | null;
};
type Device = {
    id: number;
    tenant_id: number;
    complex_name: string;
    name: string;
    location: string | null;
    serial: string | null;
    model: string;
    firmware: string | null;
    school_ids: number[];
    schools: (Pick<School, 'school_id' | 'name' | 'eduadmin_school_id'> & {
        setup_url: string | null;
    })[];
    scope_mode: string;
    revision: number;
    managed_registration: boolean;
    connection_allowed: boolean;
    status: { key: string; label: string; tone: string };
    last_contact_at: string | null;
    last_verified_at: string | null;
    created_at: string | null;
    connection: Connection;
    capacity: {
        verified_limit: number | null;
        school_roster_total: number;
        managed_enrollments: number;
        unmanaged_count: number | null;
    };
    student_sync_enabled: boolean;
    change_blockers: string[];
    checks: { key: string; ok: boolean; title: string; message: string }[];
    audit: Page<{
        operation: string;
        revision: number;
        created_at: string;
        actor_name: string | null;
        details: Record<string, unknown>;
    }>;
};
type Props = {
    actorName: string;
    csrfToken: string;
    schools: School[];
    registrationEnabled: boolean;
    readOnly: boolean;
    sharedGateEnabled: boolean;
    serverConnection: Connection;
    setupUrl: string | null;
    devices: Page<Device> | null;
    detail: Device | null;
    stats: {
        total: number;
        active: number;
        shared: number;
        paused: number;
    } | null;
    filters: { q?: string; state?: string; tenant_id?: string | number };
};
type Operation =
    | 'register'
    | 'edit'
    | 'reassign'
    | 'enable'
    | 'pause'
    | 'rotate'
    | 'retire'
    | 'verify';
type Secret = { username: string; password: string; expires_at: string };
type Form = {
    tenant: string;
    schoolIds: number[];
    name: string;
    location: string;
    serial: string;
    firmware: string;
    note: string;
    confirmation: string;
};
const input =
    'mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-100 disabled:bg-slate-100';
const buttonFrame =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed disabled:opacity-50';
const button = `${buttonFrame} border-slate-300 bg-white text-slate-700 hover:bg-slate-50`;
const primary = `${buttonFrame} border-teal-800 bg-teal-800 text-white hover:bg-teal-900`;
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const titles: Record<Operation, string> = {
    register: 'Register a terminal',
    edit: 'Edit installation details',
    reassign: 'Change participating schools',
    enable: 'Allow device connection',
    pause: 'Pause device connection',
    rotate: 'Replace connection credentials',
    retire: 'Retire unused registration',
    verify: 'Check authenticated contact',
};
const date = (value: string | null) =>
    value
        ? `${value.replace('T', ' ').replace(/\.\d+Z$/, 'Z')} UTC`
        : 'Not yet recorded';
const readable = (value: string) => value.replaceAll('_', ' ');

function Badge({
    children,
    good = false,
}: {
    children: ReactNode;
    good?: boolean;
}) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${good ? 'bg-teal-50 text-teal-800' : 'bg-slate-100 text-slate-700'}`}
        >
            <span
                className={`h-1.5 w-1.5 rounded-full ${good ? 'bg-teal-600' : 'bg-slate-400'}`}
            />
            {children}
        </span>
    );
}
function Field({
    label,
    children,
    hint,
}: {
    label: string;
    children: ReactNode;
    hint?: string;
}) {
    return (
        <label className="block text-sm font-medium text-slate-800">
            {label}
            {children}
            {hint && (
                <span className="mt-1.5 block text-xs leading-5 font-normal text-slate-500">
                    {hint}
                </span>
            )}
        </label>
    );
}
function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-slate-500">{label}</dt>
            <dd className="mt-1 text-sm font-medium break-words text-slate-900">
                {children}
            </dd>
        </div>
    );
}

export default function Devices(props: Props) {
    return <DeviceWorkspace key={props.detail?.id ?? 'inventory'} {...props} />;
}

function DeviceWorkspace(props: Props) {
    const d = props.detail;
    const [tab, setTab] = useState('overview');
    const [query, setQuery] = useState(props.filters.q ?? '');
    const [tenantFilter, setTenantFilter] = useState(
        String(props.filters.tenant_id ?? ''),
    );
    const [stateFilter, setStateFilter] = useState(
        props.filters.state ?? 'all',
    );
    const [operation, setOperation] = useState<Operation | null>(null);
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const [notice, setNotice] = useState('');
    const [secret, setSecret] = useState<Secret | null>(null);
    const [revealed, setRevealed] = useState(false);
    const [copied, setCopied] = useState(false);
    const dialog = useRef<HTMLDialogElement>(null);
    const retry = useRef({ signature: '', key: '' });
    const initialSignature = useRef('');
    const [form, setForm] = useState<Form>({
        tenant: '',
        schoolIds: [],
        name: '',
        location: '',
        serial: '',
        firmware: '',
        note: '',
        confirmation: '',
    });
    const dirty =
        operation !== null && JSON.stringify(form) !== initialSignature.current;
    const complexes = [
        ...new Map(
            props.schools.map((s) => [
                s.tenant_id,
                { id: s.tenant_id, name: s.complex_name },
            ]),
        ).values(),
    ];
    const schoolOptions = props.schools.filter(
        (s) => s.tenant_id === Number(form.tenant),
    );
    const selectedSchools = schoolOptions.filter((s) =>
        form.schoolIds.includes(s.school_id),
    );
    const f = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((previous) => ({ ...previous, [key]: value }));

    useEffect(() => {
        if (!operation) return;
        dialog.current?.showModal();
    }, [operation]);
    useEffect(() => {
        const unload = (event: BeforeUnloadEvent) => {
            if (dirty || busy) event.preventDefault();
        };
        window.addEventListener('beforeunload', unload);
        return () => window.removeEventListener('beforeunload', unload);
    }, [dirty, busy]);
    useEffect(() => {
        if (!secret) return;
        const clear = () => {
            setSecret(null);
            setRevealed(false);
            setCopied(false);
        };
        const timer = window.setTimeout(
            clear,
            Math.max(0, Date.parse(secret.expires_at) - Date.now()),
        );
        const hidden = () => {
            if (document.hidden) clear();
        };
        document.addEventListener('visibilitychange', hidden);
        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', hidden);
        };
    }, [secret]);

    function open(op: Operation) {
        if (props.readOnly) return;
        const next = {
            tenant: String(d?.tenant_id ?? complexes[0]?.id ?? ''),
            schoolIds: d?.school_ids ?? [],
            name: d?.name ?? '',
            location: d?.location ?? '',
            serial: d?.serial ?? '',
            firmware: d?.firmware ?? '',
            note: op === 'verify' ? 'Installation contact check' : '',
            confirmation: '',
        };
        setForm(next);
        initialSignature.current = JSON.stringify(next);
        retry.current = { signature: '', key: '' };
        setErrors([]);
        setStep(0);
        setOperation(op);
    }
    function close() {
        if (
            busy ||
            (dirty &&
                !window.confirm('Discard these unsaved installation details?'))
        )
            return;
        dialog.current?.close();
        setOperation(null);
        setErrors([]);
    }
    function search(event: FormEvent) {
        event.preventDefault();
        router.get('/admin/gate/devices', {
            q: query,
            state: stateFilter,
            ...(tenantFilter ? { tenant_id: tenantFilter } : {}),
        });
    }
    async function submit(event: FormEvent) {
        event.preventDefault();
        if (!operation || busy || props.readOnly) return;
        if (operation === 'register' && step < 2) {
            nextStep();
            return;
        }
        const body: Record<string, unknown> = {
            operation,
            expected_revision: d?.revision ?? 0,
            note: form.note.trim(),
        };
        if (operation === 'register')
            Object.assign(body, {
                tenant_id: Number(form.tenant),
                school_ids: form.schoolIds,
                name: form.name.trim(),
                location: form.location.trim(),
                serial: form.serial.trim(),
                firmware: form.firmware.trim(),
            });
        if (operation === 'edit')
            Object.assign(body, {
                name: form.name.trim(),
                location: form.location.trim(),
            });
        if (operation === 'reassign') body.school_ids = form.schoolIds;
        if (!['register', 'edit', 'verify'].includes(operation))
            body.confirmation = form.confirmation.trim();
        const signature = JSON.stringify(body);
        if (retry.current.signature !== signature)
            retry.current = { signature, key: crypto.randomUUID() };
        body.idempotency_key = retry.current.key;
        setBusy(true);
        setErrors([]);
        setNotice('');
        try {
            const response = await fetch(
                operation === 'register'
                    ? '/admin/gate/devices'
                    : `/admin/gate/devices/${d!.id}/actions`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'error',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': props.csrfToken,
                    },
                    body: JSON.stringify(body),
                },
            );
            const result = await response.json();
            if (!response.ok) {
                setErrors([
                    ...(response.status === 409
                        ? [
                              'The device state may have changed. Your inputs are retained. Close this form and reload the device before trying a changed operation.',
                          ]
                        : []),
                    ...Object.values(result.errors ?? {})
                        .flat()
                        .map(String),
                    result.message ?? `Request failed (${response.status}).`,
                ]);
                return;
            }
            const data = result.data;
            // Secrets stay in this component's memory only: never router props, URLs, browser history or storage.
            setSecret(data.connection_secret);
            setRevealed(false);
            setCopied(false);
            setNotice(
                operation === 'verify'
                    ? data.authenticated_contact_observed
                        ? 'Recent authenticated device contact confirmed. Student sync and attendance activation were not changed.'
                        : 'No recent authenticated contact is visible on this application receiver. No device settings were changed.'
                    : `${titles[operation]}: saved revision ${data.saved_revision}. No student synchronisation or attendance policy was started.`,
            );
            dialog.current?.close();
            setOperation(null);
            // A newly registered detail remounts the workspace. Keep one-time credentials
            // on the inventory until the administrator deliberately opens the new row.
            if (operation === 'register') {
                setNotice(
                    `Registration saved. Device #${data.device_id} is paused. Copy its dedicated credentials below before opening the installation.`,
                );
                router.get(
                    '/admin/gate/devices',
                    {},
                    { preserveState: true, preserveScroll: true },
                );
            } else
                router.get(
                    `/admin/gate/devices/${data.device_id}`,
                    {},
                    { preserveState: true, preserveScroll: true },
                );
        } catch {
            setErrors([
                'We could not confirm the result. Your inputs are retained. Retry unchanged to reuse the same request key; do not register the serial again with a different key. If your session expired, sign in and check the inventory first.',
            ]);
        } finally {
            setBusy(false);
        }
    }
    function nextStep() {
        const missing: string[] = [];
        if (step === 0 && (!form.tenant || form.schoolIds.length === 0))
            missing.push(
                'Select a complex and at least one participating school.',
            );
        if (step === 0 && form.schoolIds.length > 1 && !props.sharedGateEnabled)
            missing.push(
                'Shared-gate registration is not enabled on this host.',
            );
        if (
            step === 1 &&
            (!form.name.trim() ||
                !form.location.trim() ||
                !/^[A-Za-z0-9_-]{1,128}$/.test(form.serial.trim()) ||
                !/^V\d+\.\d+\.\d+[ _]build\d{6,8}$/.test(form.firmware.trim()))
        )
            missing.push(
                'Enter a name, gate location, exact hardware serial and firmware version with build number.',
            );
        setErrors(missing);
        if (!missing.length) setStep(step + 1);
    }
    const scopePicker = (
        <div className="space-y-5">
            <Field
                label="Complex owner"
                hint="One complex owns this installation. Sharing never crosses complex boundaries."
            >
                <select
                    className={input}
                    value={form.tenant}
                    disabled={operation !== 'register'}
                    onChange={(e) => {
                        f('tenant', e.target.value);
                        f('schoolIds', []);
                    }}
                    required
                >
                    {complexes.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </select>
            </Field>
            <fieldset className="space-y-2">
                <legend className="mb-2 text-sm font-semibold text-slate-900">
                    Participating schools{' '}
                    <span className="font-normal text-slate-500">
                        · Choose explicitly
                    </span>
                </legend>
                {schoolOptions.map((s) => (
                    <label
                        key={s.school_id}
                        className={`flex cursor-pointer items-start gap-3 rounded-lg border p-4 ${form.schoolIds.includes(s.school_id) ? 'border-teal-600 bg-teal-50/60' : 'border-slate-200'}`}
                    >
                        <input
                            type="checkbox"
                            className="mt-1 h-4 w-4 accent-teal-700"
                            aria-label={`Include ${s.name}`}
                            checked={form.schoolIds.includes(s.school_id)}
                            onChange={(e) =>
                                f(
                                    'schoolIds',
                                    e.target.checked
                                        ? [...form.schoolIds, s.school_id].sort(
                                              (a, b) => a - b,
                                          )
                                        : form.schoolIds.filter(
                                              (id) => id !== s.school_id,
                                          ),
                                )
                            }
                        />
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold text-slate-900">
                                {s.name}
                            </span>
                            <span className="mt-1 block text-xs text-slate-500">
                                EduAdmin school{' '}
                                {s.eduadmin_school_id ??
                                    'identity not configured'}
                                {' · '}
                                {s.roster_total.toLocaleString()} active
                                students
                            </span>
                        </span>
                    </label>
                ))}
            </fieldset>
            <div className="rounded-lg bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                <strong className="text-slate-900">
                    {form.schoolIds.length > 1
                        ? `${form.schoolIds.length} schools · Shared gate`
                        : 'Single-school installation'}
                </strong>
                <p className="mt-2 font-medium text-slate-900">
                    Combined selected-school roster:{' '}
                    {selectedSchools
                        .reduce(
                            (total, school) => total + school.roster_total,
                            0,
                        )
                        .toLocaleString()}{' '}
                    students
                </p>
                <p>
                    One serial and one connection. Each recognised student
                    routes to their own school. Timetables, staff permissions,
                    corrections and EduAdmin records remain separate.
                </p>
            </div>
        </div>
    );
    const hardwareFields = (
        <div className="grid gap-5 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <Field label="Installation name">
                    <input
                        className={input}
                        value={form.name}
                        onChange={(e) => f('name', e.target.value)}
                        placeholder="Complex main gate"
                        maxLength={100}
                        required
                    />
                </Field>
            </div>
            <div className="sm:col-span-2">
                <Field label="Gate location">
                    <input
                        className={input}
                        value={form.location}
                        onChange={(e) => f('location', e.target.value)}
                        placeholder="Shared entrance · Main road"
                        maxLength={120}
                        required
                    />
                </Field>
            </div>
            {operation === 'register' && (
                <>
                    <div className="sm:col-span-2">
                        <Field
                            label="Full hardware serial"
                            hint="Copy from the terminal’s web Basic Information page. Do not use its IP address or create a second registration for the same serial."
                        >
                            <input
                                className={`${input} font-mono`}
                                value={form.serial}
                                onChange={(e) => f('serial', e.target.value)}
                                autoComplete="off"
                                maxLength={128}
                                required
                            />
                        </Field>
                    </div>
                    <div className="sm:col-span-2">
                        <Field
                            label="Firmware version and build"
                            hint="For example: V4.48.0_build260417. This records identity; it does not certify hardware capacity."
                        >
                            <input
                                className={input}
                                value={form.firmware}
                                onChange={(e) => f('firmware', e.target.value)}
                                placeholder="V4.48.0_build260417"
                                maxLength={128}
                                required
                            />
                        </Field>
                    </div>
                </>
            )}
        </div>
    );

    return (
        <div className="min-h-screen bg-[#f5f7f8] text-slate-900 selection:bg-teal-100">
            <Head title="Device installations" />
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-4 px-5 py-4 sm:px-8">
                    <Link
                        href="/admin/gate/devices"
                        className="flex items-center gap-3 font-semibold"
                    >
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-800 text-white">
                            <DoorOpen size={20} />
                        </span>
                        <span>
                            EduConnect{' '}
                            <span className="ml-2 hidden border-l border-slate-200 pl-3 text-sm font-normal text-slate-500 sm:inline">
                                Gate management
                            </span>
                        </span>
                    </Link>
                    <div className="flex items-center gap-4 text-sm">
                        <span className="hidden text-slate-500 sm:inline">
                            {props.actorName}
                        </span>
                        {props.setupUrl && (
                            <Link
                                href={props.setupUrl}
                                className="font-medium text-teal-800 underline-offset-4 hover:underline"
                            >
                                School setup
                            </Link>
                        )}
                        <form action="/admin/logout" method="post">
                            <input
                                type="hidden"
                                name="_token"
                                value={props.csrfToken}
                            />
                            <button
                                type="submit"
                                className="text-slate-600 underline-offset-4 hover:underline"
                            >
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-[1440px] space-y-6 px-5 py-7 sm:px-8 sm:py-9">
                <nav
                    aria-label="Breadcrumb"
                    className="flex items-center gap-2 text-xs text-slate-500"
                >
                    <Link
                        href="/admin/gate/devices"
                        className="hover:text-teal-800"
                    >
                        Device installations
                    </Link>
                    {d && (
                        <>
                            <ChevronRight size={13} />
                            <span className="truncate text-slate-800">
                                {d.name}
                            </span>
                        </>
                    )}
                </nav>
                {notice && (
                    <div
                        role="status"
                        className="rounded-lg border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900"
                    >
                        {notice}
                    </div>
                )}
                {secret && (
                    <section
                        aria-label="One-time connection credentials"
                        className="rounded-xl border border-amber-300 bg-amber-50 p-5"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold text-amber-950">
                                    Save the dedicated device credentials now
                                </h2>
                                <p className="mt-1 text-sm leading-6 text-amber-900">
                                    Use these only in PushSDK. They are not the
                                    terminal administrator password. This view
                                    clears when hidden or after ten minutes;
                                    credentials never appear in installation
                                    history.
                                </p>
                            </div>
                            <button
                                type="button"
                                aria-label="Dismiss credentials"
                                className={button}
                                onClick={() => setSecret(null)}
                            >
                                <X size={16} />
                            </button>
                        </div>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <Fact label="PushSDK username">
                                <span className="font-mono">
                                    {secret.username}
                                </span>
                            </Fact>
                            <Fact label="PushSDK password">
                                <span className="font-mono">
                                    {revealed
                                        ? secret.password
                                        : '••••••••••••••••••••••••'}
                                </span>
                            </Fact>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                className={button}
                                onClick={() => setRevealed(!revealed)}
                            >
                                {revealed ? 'Hide' : 'Reveal'} password
                            </button>
                            <button
                                type="button"
                                className={button}
                                onClick={async () => {
                                    try {
                                        await navigator.clipboard.writeText(
                                            secret.password,
                                        );
                                        setCopied(true);
                                    } catch {
                                        setNotice(
                                            'Clipboard unavailable. Reveal and copy the password manually in this private view.',
                                        );
                                    }
                                }}
                            >
                                <Clipboard size={15} />
                                {copied ? 'Password copied' : 'Copy password'}
                            </button>
                        </div>
                    </section>
                )}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs font-semibold tracking-[0.14em] text-teal-800 uppercase">
                            {d
                                ? 'Installation workspace'
                                : 'Hardware & school scope'}
                        </p>
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                            {d?.name ?? 'Device installations'}
                        </h1>
                        <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-500">
                            {d
                                ? `${d.complex_name} · ${d.location || 'Gate location not recorded'}`
                                : 'A clear home for every terminal. Register once, choose the schools it serves, then verify its connection.'}
                        </p>
                    </div>
                    {d ? (
                        <Link className={button} href="/admin/gate/devices">
                            <ArrowLeft size={16} />
                            All installations
                        </Link>
                    ) : (
                        <button
                            type="button"
                            className={primary}
                            disabled={
                                props.readOnly || !props.registrationEnabled
                            }
                            onClick={() => open('register')}
                        >
                            <Plus size={17} />
                            Register terminal
                        </button>
                    )}
                </div>
                {props.readOnly && (
                    <div
                        role="status"
                        className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900"
                    >
                        Read-only review: registration, editing, credential and
                        connection changes are disabled on this host. The
                        working terminal and school data are unchanged.
                    </div>
                )}
                {!d && (
                    <>
                        {!props.registrationEnabled && (
                            <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                Registration is paused on this host. Existing
                                installations remain visible; no live device is
                                changed.
                            </p>
                        )}
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            {[
                                {
                                    label: 'Installations in view',
                                    value: props.stats?.total,
                                    icon: Monitor,
                                },
                                {
                                    label: 'Shared gate installations',
                                    value: props.stats?.shared,
                                    icon: Building2,
                                },
                                {
                                    label: 'Active registrations',
                                    value: props.stats?.active,
                                    icon: ShieldCheck,
                                },
                                {
                                    label: 'Connections paused',
                                    value: props.stats?.paused,
                                    icon: Cable,
                                },
                            ].map((s) => (
                                <div key={s.label} className={`${panel} p-5`}>
                                    <div className="flex justify-between gap-2 text-xs text-slate-500">
                                        {s.label}
                                        <s.icon
                                            size={17}
                                            className="shrink-0 text-slate-400"
                                        />
                                    </div>
                                    <p className="mt-4 text-3xl font-semibold tabular-nums">
                                        {s.value ?? 0}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <section
                            className={panel}
                            aria-label="Installation inventory"
                        >
                            <form
                                onSubmit={search}
                                className="flex flex-wrap items-end gap-3 border-b border-slate-200 p-5"
                            >
                                <div className="min-w-0 flex-1 basis-52">
                                    <Field label="Search installations">
                                        <input
                                            type="search"
                                            className={input}
                                            placeholder="Name, serial or gate location"
                                            maxLength={80}
                                            value={query}
                                            onChange={(e) =>
                                                setQuery(e.target.value)
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="min-w-0 flex-1 basis-44">
                                    <Field label="Complex">
                                        <select
                                            className={input}
                                            value={tenantFilter}
                                            onChange={(e) =>
                                                setTenantFilter(e.target.value)
                                            }
                                        >
                                            <option value="">
                                                All authorised complexes
                                            </option>
                                            {complexes.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>
                                <div>
                                    <Field label="Registration filter">
                                        <select
                                            className={input}
                                            value={stateFilter}
                                            onChange={(e) =>
                                                setStateFilter(e.target.value)
                                            }
                                        >
                                            {[
                                                'all',
                                                'active',
                                                'shared',
                                                'paused',
                                                'retired',
                                            ].map((s) => (
                                                <option key={s} value={s}>
                                                    {s === 'all'
                                                        ? 'All registrations'
                                                        : readable(s)}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>
                                <button className={button} type="submit">
                                    <Search size={16} />
                                    Search
                                </button>
                            </form>
                            <div className="divide-y divide-slate-100">
                                {props.devices?.data.map((device) => (
                                    <Link
                                        key={device.id}
                                        href={`/admin/gate/devices/${device.id}`}
                                        className="group grid items-center gap-4 p-5 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-teal-700 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,.8fr)_24px]"
                                    >
                                        <div className="flex min-w-0 items-start gap-3">
                                            <span className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-teal-800">
                                                <Monitor size={21} />
                                            </span>
                                            <div className="min-w-0">
                                                <h2 className="font-semibold break-words group-hover:text-teal-800">
                                                    {device.name}
                                                </h2>
                                                <p className="mt-1 text-xs break-words text-slate-500">
                                                    {device.location ||
                                                        'Gate location not recorded'}
                                                </p>
                                                <p className="mt-2 font-mono text-xs break-all text-slate-400">
                                                    {device.serial ??
                                                        'No hardware serial recorded'}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">
                                                {device.complex_name}
                                            </p>
                                            <p className="mt-1 text-xs text-slate-500">
                                                {device.schools.length}{' '}
                                                {device.schools.length === 1
                                                    ? 'school'
                                                    : 'schools'}{' '}
                                                ·{' '}
                                                {device.scope_mode ===
                                                'shared_gate'
                                                    ? 'Shared gate'
                                                    : 'Single-school gate'}
                                            </p>
                                            <p className="mt-2 text-xs text-slate-500">
                                                {device.schools
                                                    .map((s) => s.name)
                                                    .join(' · ') ||
                                                    'Historical school assignments retained'}
                                            </p>
                                        </div>
                                        <div>
                                            <Badge
                                                good={
                                                    device.status.key ===
                                                    'online'
                                                }
                                            >
                                                {device.status.label}
                                            </Badge>
                                            <p className="mt-2 text-xs text-slate-500">
                                                {device.managed_registration
                                                    ? 'Application-managed'
                                                    : 'Existing installation · Protected'}
                                            </p>
                                        </div>
                                        <ChevronRight
                                            size={18}
                                            className="hidden text-slate-400 md:block"
                                        />
                                    </Link>
                                ))}
                                {!props.devices?.data.length && (
                                    <div className="px-6 py-14 text-center">
                                        <Monitor
                                            className="mx-auto mb-4 text-slate-300"
                                            size={36}
                                        />
                                        <h2 className="font-semibold">
                                            No installations match this view
                                        </h2>
                                        <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                                            Adjust the filters, or register a
                                            terminal under an authorised
                                            complex. Schools sharing a gate need
                                            one registration, not one duplicate
                                            per school.
                                        </p>
                                    </div>
                                )}
                            </div>
                            <Pagination page={props.devices} />
                        </section>
                        <div className="grid gap-5 md:grid-cols-2">
                            <section className={`${panel} flex gap-4 p-5`}>
                                <Building2
                                    className="shrink-0 text-teal-700"
                                    size={22}
                                />
                                <div>
                                    <h2 className="font-semibold">
                                        Three schools. One shared entrance.
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-slate-500">
                                        Choose the complex and all participating
                                        schools in one installation. School
                                        ownership follows the student, not the
                                        terminal’s Wi-Fi address.
                                    </p>
                                </div>
                            </section>
                            <section className={`${panel} flex gap-4 p-5`}>
                                <ShieldCheck
                                    className="shrink-0 text-teal-700"
                                    size={22}
                                />
                                <div>
                                    <h2 className="font-semibold">
                                        A deliberate setup, not automatic
                                        activation
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-slate-500">
                                        New terminals start paused. Registration
                                        does not synchronise portraits, alter
                                        student records or publish a school
                                        attendance policy.
                                    </p>
                                </div>
                            </section>
                        </div>
                    </>
                )}
                {d && (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4">
                            <div className="flex flex-wrap gap-2">
                                <Badge good={d.status.key === 'online'}>
                                    {d.status.label}
                                </Badge>
                                <Badge>
                                    {d.scope_mode === 'shared_gate'
                                        ? `${d.schools.length} schools · Shared gate`
                                        : 'Single-school gate'}
                                </Badge>
                                <Badge>
                                    {d.managed_registration
                                        ? 'Application-managed'
                                        : 'Existing installation · Protected'}
                                </Badge>
                            </div>
                            <span className="text-xs text-slate-500">
                                Revision {d.revision} · No automatic attendance
                                activation
                            </span>
                        </div>
                        <div
                            className="flex gap-1 overflow-x-auto border-b border-slate-200"
                            aria-label="Installation sections"
                        >
                            {[
                                ['overview', 'Overview'],
                                ['schools', 'Participating schools'],
                                ['connection', 'Connection & installation'],
                                ['history', 'Activity history'],
                            ].map(([id, label]) => (
                                <button
                                    type="button"
                                    key={id}
                                    aria-pressed={tab === id}
                                    onClick={() => setTab(id)}
                                    className={`shrink-0 border-b-2 px-4 py-3 text-sm font-medium ${tab === id ? 'border-teal-700 text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-900'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        {tab === 'overview' && (
                            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                                <div className="space-y-6">
                                    <section className={`${panel} p-6`}>
                                        <div className="flex items-center justify-between gap-3">
                                            <h2 className="text-lg font-semibold">
                                                Terminal identity
                                            </h2>
                                            <button
                                                className={button}
                                                onClick={() => open('edit')}
                                                disabled={
                                                    props.readOnly ||
                                                    d.status.key === 'retired'
                                                }
                                            >
                                                Edit details
                                            </button>
                                        </div>
                                        <dl className="mt-6 grid gap-6 sm:grid-cols-2">
                                            <Fact label="Model">{d.model}</Fact>
                                            <Fact label="Firmware">
                                                {d.firmware ?? 'Not recorded'}
                                            </Fact>
                                            <div className="sm:col-span-2">
                                                <Fact label="Hardware serial">
                                                    <span className="font-mono break-all">
                                                        {d.serial ??
                                                            'Not recorded'}
                                                    </span>
                                                </Fact>
                                            </div>
                                            <Fact label="Gate location">
                                                {d.location || 'Not recorded'}
                                            </Fact>
                                            <Fact label="Registered at">
                                                {date(d.created_at)}
                                            </Fact>
                                        </dl>
                                    </section>
                                    <section className={`${panel} p-6`}>
                                        <h2 className="text-lg font-semibold">
                                            Combined roster & capacity
                                        </h2>
                                        <p className="mt-2 text-sm leading-6 text-slate-500">
                                            Capacity belongs to the physical
                                            terminal, not each school
                                            separately.
                                        </p>
                                        <div className="mt-5 grid gap-4 sm:grid-cols-3">
                                            <Fact label="Active school roster total">
                                                {d.capacity.school_roster_total.toLocaleString()}
                                            </Fact>
                                            <Fact label="Managed enrolment targets">
                                                {d.capacity.managed_enrollments.toLocaleString()}
                                            </Fact>
                                            <Fact label="Verified face limit">
                                                {d.capacity.verified_limit?.toLocaleString() ??
                                                    'Not verified'}
                                            </Fact>
                                        </div>
                                        <div className="mt-5 rounded-lg bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                                            {d.capacity.verified_limit === null
                                                ? 'Do not assume that a combined roster below 1,500 is automatically ready. Verify capacity for this exact model and firmware, and account for people already stored on the terminal.'
                                                : `Verified limit: ${d.capacity.verified_limit}. Unmanaged people: ${d.capacity.unmanaged_count ?? 'not confirmed'}. Compare the combined enrolment selection, not each school alone.`}{' '}
                                            This roster count does not enrol
                                            anyone.
                                        </div>
                                    </section>
                                </div>
                                <section className={`${panel} p-6`}>
                                    <h2 className="text-lg font-semibold">
                                        Installation readiness
                                    </h2>
                                    <p className="mt-2 text-xs leading-5 text-slate-500">
                                        Registration → connection → student sync
                                        → school rules. Each is a separate
                                        control.
                                    </p>
                                    <ol className="mt-6 space-y-6">
                                        {d.checks.map((check, i) => (
                                            <li
                                                key={check.key}
                                                className="flex gap-3"
                                            >
                                                <span
                                                    className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${check.ok ? 'bg-teal-50 text-teal-700' : 'bg-slate-100 text-slate-500'}`}
                                                >
                                                    {check.ok ? (
                                                        <Check size={15} />
                                                    ) : (
                                                        i + 1
                                                    )}
                                                </span>
                                                <div>
                                                    <h3 className="text-sm font-semibold">
                                                        {check.title}
                                                    </h3>
                                                    <p className="mt-1 text-xs leading-5 text-slate-500">
                                                        {check.message}
                                                    </p>
                                                </div>
                                            </li>
                                        ))}
                                    </ol>
                                </section>
                            </div>
                        )}
                        {tab === 'schools' && (
                            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                                <section className={`${panel} overflow-hidden`}>
                                    <div className="border-b p-6">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <h2 className="text-lg font-semibold">
                                                Schools served by this gate
                                            </h2>
                                            <button
                                                className={button}
                                                disabled={
                                                    props.readOnly ||
                                                    d.change_blockers.length > 0
                                                }
                                                onClick={() => open('reassign')}
                                            >
                                                Change schools
                                            </button>
                                        </div>
                                        <p className="mt-2 text-sm leading-6 text-slate-500">
                                            Owned by {d.complex_name}.{' '}
                                            {d.scope_mode === 'shared_gate'
                                                ? 'This is a shared installation, not a merger of school data.'
                                                : 'This installation currently serves one school; its school data remains separate.'}
                                        </p>
                                    </div>
                                    {d.schools.map((s) => (
                                        <div
                                            key={s.school_id}
                                            className="flex items-start gap-3 border-b border-slate-100 p-6"
                                        >
                                            <Building2
                                                size={21}
                                                className="mt-1 shrink-0 text-teal-700"
                                            />
                                            <div>
                                                <h3 className="font-semibold">
                                                    {s.name}
                                                </h3>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    EduAdmin school{' '}
                                                    {s.eduadmin_school_id ??
                                                        'not mapped'}{' '}
                                                    · Own students, timetable
                                                    and staff permissions
                                                </p>
                                                {s.setup_url && (
                                                    <Link
                                                        href={s.setup_url}
                                                        className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-teal-800 underline"
                                                    >
                                                        Open authorised school
                                                        setup{' '}
                                                        <ArrowRight size={14} />
                                                    </Link>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </section>
                                <div className="space-y-5">
                                    <section className={`${panel} p-6`}>
                                        <h2 className="font-semibold">
                                            How a shared scan is handled
                                        </h2>
                                        <ol className="mt-4 space-y-4 text-sm leading-6 text-slate-600">
                                            <li>
                                                1. EduAdmin supplies the student
                                                and approved portrait.
                                            </li>
                                            <li>
                                                2. The application synchronises
                                                a unique person ID to the shared
                                                terminal.
                                            </li>
                                            <li>
                                                3. The recognised ID resolves to
                                                exactly one participating
                                                school.
                                            </li>
                                            <li>
                                                4. That school’s rules classify
                                                the scan; its records are shared
                                                back to EduAdmin.
                                            </li>
                                        </ol>
                                        <p className="mt-5 rounded-lg bg-teal-50 p-3 text-xs leading-5 text-teal-900">
                                            A guard serving the shared gate
                                            needs explicit authority for each
                                            school. Sharing hardware grants no
                                            extra access.
                                        </p>
                                    </section>
                                    <Blockers device={d} />
                                </div>
                            </div>
                        )}
                        {tab === 'connection' && (
                            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                                <div className="space-y-6">
                                    <section className={`${panel} p-6`}>
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <h2 className="text-lg font-semibold">
                                                PushSDK connection
                                            </h2>
                                            <button
                                                className={button}
                                                disabled={
                                                    props.readOnly ||
                                                    d.status.key === 'retired'
                                                }
                                                onClick={() => open('verify')}
                                            >
                                                <RefreshCw size={15} />
                                                Check contact
                                            </button>
                                        </div>
                                        <p className="mt-3 text-sm leading-6 text-slate-500">
                                            Use the approved application
                                            endpoint below. No inbound port
                                            forwarding to the terminal is
                                            needed; it initiates the connection.
                                        </p>
                                        <dl className="mt-6 grid gap-5 sm:grid-cols-2">
                                            <div className="sm:col-span-2">
                                                <Fact label="Server host">
                                                    {d.connection.server_host ??
                                                        'Not configured on this host'}
                                                </Fact>
                                            </div>
                                            <Fact label="Protocol">HTTPS</Fact>
                                            <Fact label="HTTPS port">
                                                {d.connection.https_port ??
                                                    'Not configured'}
                                            </Fact>
                                            <Fact label="WebSocket port">
                                                {d.connection.websocket_port ??
                                                    'Not configured'}
                                            </Fact>
                                            <Fact label="Dedicated username">
                                                {d.connection.username ??
                                                    'Not provisioned'}
                                            </Fact>
                                            <Fact label="Last authenticated contact">
                                                {date(d.last_contact_at)}
                                            </Fact>
                                            <Fact label="Last successful check">
                                                {date(d.last_verified_at)}
                                            </Fact>
                                        </dl>
                                        <p className="mt-6 rounded-lg bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                            {d.connection.message}
                                        </p>
                                        <div className="mt-5 flex flex-wrap gap-2">
                                            <button
                                                className={primary}
                                                disabled={
                                                    props.readOnly ||
                                                    !d.managed_registration ||
                                                    d.status.key ===
                                                        'retired' ||
                                                    (!d.connection_allowed &&
                                                        !d.connection
                                                            .may_enable) ||
                                                    d.student_sync_enabled ||
                                                    d.capacity
                                                        .managed_enrollments > 0
                                                }
                                                onClick={() =>
                                                    open(
                                                        d.connection_allowed
                                                            ? 'pause'
                                                            : 'enable',
                                                    )
                                                }
                                            >
                                                {d.connection_allowed
                                                    ? 'Pause connection'
                                                    : 'Allow connection'}
                                            </button>
                                            <button
                                                className={button}
                                                disabled={
                                                    props.readOnly ||
                                                    d.change_blockers.length > 0
                                                }
                                                onClick={() => open('rotate')}
                                            >
                                                Replace credentials
                                            </button>
                                        </div>
                                        <p className="mt-3 text-xs leading-5 text-slate-500">
                                            Live student transport is protected.
                                            Connection controls here are for
                                            unused, application-managed
                                            installations; they do not stop an
                                            active school roster.
                                        </p>
                                    </section>
                                    <section className={`${panel} p-6`}>
                                        <h2 className="text-lg font-semibold">
                                            Installer checklist
                                        </h2>
                                        <ol className="mt-5 space-y-4 text-sm leading-6 text-slate-600">
                                            <li>
                                                <strong className="text-slate-900">
                                                    1. Power and network.
                                                </strong>{' '}
                                                Use the device-approved power
                                                method. Connect the terminal to
                                                internet-enabled LAN or Wi-Fi at
                                                the shared gate.
                                            </li>
                                            <li>
                                                <strong className="text-slate-900">
                                                    2. Identity and time.
                                                </strong>{' '}
                                                Check the exact serial, model,
                                                firmware, correct local time and
                                                time synchronisation. Do not
                                                factory-reset an existing
                                                installation.
                                            </li>
                                            <li>
                                                <strong className="text-slate-900">
                                                    3. Outbound connection.
                                                </strong>{' '}
                                                In System and Maintenance →
                                                Network → Device Access →
                                                PushSDK, enter the approved
                                                host, HTTPS/WebSocket ports and
                                                dedicated credentials. Keep
                                                certificate validation in the
                                                approved configuration.
                                            </li>
                                            <li>
                                                <strong className="text-slate-900">
                                                    4. Verify here.
                                                </strong>{' '}
                                                After the terminal reports
                                                Online, check authenticated
                                                contact. A green icon alone is
                                                not proof of student
                                                synchronisation.
                                            </li>
                                            <li>
                                                <strong className="text-slate-900">
                                                    5. Prepare each school.
                                                </strong>{' '}
                                                Confirm its EduAdmin mapping,
                                                selected students, portraits,
                                                staff and attendance rules. Test
                                                one consenting adult per
                                                participating school before
                                                admitting the full roster.
                                            </li>
                                        </ol>
                                        <div className="mt-5 flex gap-2 rounded-lg bg-teal-50 p-4 text-sm leading-6 text-teal-900">
                                            <Info
                                                size={18}
                                                className="mt-1 shrink-0"
                                            />
                                            The direct PushSDK deployment does
                                            not need an always-on school
                                            computer. An installer can use a
                                            laptop during setup; the terminal
                                            then talks to the application
                                            server.
                                        </div>
                                    </section>
                                </div>
                                <div className="space-y-5">
                                    <Blockers device={d} />
                                    <section className={`${panel} p-6`}>
                                        <h2 className="font-semibold">
                                            Retire an unused registration
                                        </h2>
                                        <p className="mt-2 text-sm leading-6 text-slate-500">
                                            Retirement preserves the serial,
                                            school-assignment epochs and audit
                                            history. It is not a factory reset
                                            or a command to erase faces.
                                        </p>
                                        <button
                                            className={`${button} mt-4 border-red-200 text-red-700`}
                                            disabled={
                                                props.readOnly ||
                                                d.change_blockers.length > 0
                                            }
                                            onClick={() => open('retire')}
                                        >
                                            Retire registration
                                        </button>
                                    </section>
                                </div>
                            </div>
                        )}
                        {tab === 'history' && (
                            <section className={panel}>
                                <div className="border-b p-6">
                                    <h2 className="text-lg font-semibold">
                                        Installation activity
                                    </h2>
                                    <p className="mt-2 text-sm text-slate-500">
                                        Who changed this installation, what
                                        changed and why. No passwords, portraits
                                        or biometric payloads appear here.
                                    </p>
                                </div>
                                <ol className="divide-y divide-slate-100">
                                    {d.audit.data.map((a) => (
                                        <li
                                            key={a.revision}
                                            className="flex gap-4 p-6"
                                        >
                                            <span className="mt-1 rounded-full bg-slate-100 p-2 text-slate-500">
                                                <ShieldCheck size={16} />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap justify-between gap-2">
                                                    <h3 className="font-semibold capitalize">
                                                        {readable(a.operation)}{' '}
                                                        <span className="ml-2 text-xs font-normal text-slate-400">
                                                            Revision{' '}
                                                            {a.revision}
                                                        </span>
                                                    </h3>
                                                    <time className="text-xs text-slate-500">
                                                        {date(a.created_at)}
                                                    </time>
                                                </div>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {a.actor_name ??
                                                        'Historical administrator'}
                                                </p>
                                                <p className="mt-3 text-sm break-words text-slate-700">
                                                    {String(
                                                        a.details.note ?? '',
                                                    )}
                                                </p>
                                                {Array.isArray(
                                                    a.details.school_ids,
                                                ) && (
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        Participating school
                                                        IDs:{' '}
                                                        {a.details.school_ids.join(
                                                            ', ',
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                                {!d.audit.data.length && (
                                    <p className="p-8 text-sm text-slate-500">
                                        No device-registry actions yet.
                                        Pre-existing installation records have
                                        not been rewritten.
                                    </p>
                                )}
                                <Pagination page={d.audit} />
                            </section>
                        )}
                    </>
                )}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-5 text-xs leading-5 text-slate-500">
                    <span className="inline-flex items-center gap-2">
                        <ShieldCheck size={14} />
                        Explicit complex-level device-administrator access
                    </span>
                    <span>
                        Student portraits stay authoritative in EduAdmin
                    </span>
                </footer>
            </main>
            <dialog
                ref={dialog}
                aria-labelledby="device-dialog-title"
                onCancel={(e) => {
                    e.preventDefault();
                    close();
                }}
                className="fixed m-auto max-h-[90dvh] w-[min(680px,calc(100%_-_24px))] rounded-2xl bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/40"
            >
                {operation && (
                    <form onSubmit={submit}>
                        <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b bg-white p-5 sm:p-6">
                            <div>
                                <p className="mb-1 text-xs font-medium text-teal-800">
                                    {operation === 'register'
                                        ? 'New installation · Starts paused'
                                        : `Revision ${d?.revision} · ${d?.name}`}
                                </p>
                                <h2
                                    id="device-dialog-title"
                                    className="text-xl font-semibold"
                                >
                                    {titles[operation]}
                                </h2>
                            </div>
                            <button
                                className={button}
                                type="button"
                                aria-label="Close installation form"
                                disabled={busy}
                                onClick={close}
                            >
                                <X size={17} />
                            </button>
                        </div>
                        <fieldset
                            disabled={busy}
                            className="min-w-0 space-y-6 p-5 sm:p-6"
                        >
                            {operation === 'register' && (
                                <ol
                                    aria-label="Registration progress"
                                    className="grid grid-cols-3 gap-2"
                                >
                                    {['School scope', 'Hardware', 'Review'].map(
                                        (s, i) => (
                                            <li
                                                key={s}
                                                aria-current={
                                                    step === i
                                                        ? 'step'
                                                        : undefined
                                                }
                                                className={`border-t-2 pt-2 text-xs font-medium ${step >= i ? 'border-teal-700 text-teal-800' : 'border-slate-200 text-slate-400'}`}
                                            >
                                                {i + 1}. {s}
                                            </li>
                                        ),
                                    )}
                                </ol>
                            )}
                            {errors.length > 0 && (
                                <div
                                    role="alert"
                                    className="space-y-2 rounded-lg border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-800"
                                >
                                    {errors.map((e, i) => (
                                        <p key={i}>{e}</p>
                                    ))}
                                </div>
                            )}
                            {(operation === 'reassign' ||
                                (operation === 'register' && step === 0)) &&
                                scopePicker}
                            {(operation === 'edit' ||
                                (operation === 'register' && step === 1)) &&
                                hardwareFields}
                            {operation === 'register' && step === 2 && (
                                <div className="space-y-5">
                                    <dl className="grid gap-5 sm:grid-cols-2">
                                        <Fact label="Complex">
                                            {
                                                complexes.find(
                                                    (c) =>
                                                        c.id ===
                                                        Number(form.tenant),
                                                )?.name
                                            }
                                        </Fact>
                                        <Fact label="Installation">
                                            {form.name}
                                        </Fact>
                                        <div className="sm:col-span-2">
                                            <Fact label="Participating schools">
                                                {selectedSchools
                                                    .map((s) => s.name)
                                                    .join(' · ')}
                                            </Fact>
                                        </div>
                                        <Fact label="Location">
                                            {form.location}
                                        </Fact>
                                        <Fact label="Firmware">
                                            {form.firmware}
                                        </Fact>
                                        <div className="sm:col-span-2">
                                            <Fact label="Serial">
                                                <span className="font-mono break-all">
                                                    {form.serial.toUpperCase()}
                                                </span>
                                            </Fact>
                                        </div>
                                    </dl>
                                    <div className="rounded-lg bg-teal-50 p-4 text-sm leading-6 text-teal-900">
                                        This creates one paused registration and
                                        dedicated PushSDK credentials. It does
                                        not enable the connection, send
                                        portraits or activate attendance.
                                        School-specific setup follows after
                                        capacity and connection checks.
                                    </div>
                                </div>
                            )}
                            {[
                                'enable',
                                'pause',
                                'rotate',
                                'retire',
                                'reassign',
                            ].includes(operation) && (
                                <>
                                    <p className="rounded-lg bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                                        {operation === 'reassign'
                                            ? 'Only an unused, paused terminal can change school scope here. Previous assignment epochs remain in history. New credentials replace the old pair.'
                                            : operation === 'rotate'
                                              ? 'Old credentials will stop working. Configure the new dedicated pair on the terminal before allowing its connection.'
                                              : operation === 'retire'
                                                ? 'This unused registration becomes inactive. Its identity and history are retained; no device erase command is sent.'
                                                : operation === 'pause'
                                                  ? 'Authenticated transport for this unused registration will be paused. This action is unavailable for live student synchronisation.'
                                                  : 'This permits the terminal to authenticate to the approved application receiver. Student synchronisation and attendance remain separate controls.'}
                                    </p>
                                    <Field
                                        label="Confirm exact hardware serial"
                                        hint={`Type ${d?.serial ?? 'the registered serial'} to confirm.`}
                                    >
                                        <input
                                            className={`${input} font-mono`}
                                            value={form.confirmation}
                                            onChange={(e) =>
                                                f(
                                                    'confirmation',
                                                    e.target.value,
                                                )
                                            }
                                            autoComplete="off"
                                            required
                                        />
                                    </Field>
                                </>
                            )}
                            {operation === 'verify' && (
                                <p className="text-sm leading-6 text-slate-600">
                                    This checks recent, authenticated inbound
                                    PushSDK activity recorded by this
                                    application. It does not probe an IP,
                                    contact the terminal, request a face scan or
                                    change school data. An external test
                                    receiver cannot be verified here.
                                </p>
                            )}
                            {(operation !== 'register' || step === 2) && (
                                <Field
                                    label="Reason for this operation"
                                    hint="Required for the installation audit. At least 8 characters. Do not enter passwords or student information."
                                >
                                    <textarea
                                        className={input}
                                        value={form.note}
                                        onChange={(e) =>
                                            f('note', e.target.value)
                                        }
                                        rows={3}
                                        minLength={8}
                                        maxLength={500}
                                        required
                                    />
                                </Field>
                            )}
                        </fieldset>
                        <div className="sticky bottom-0 flex justify-between gap-3 border-t bg-white p-5 sm:px-6">
                            <button
                                className={button}
                                type="button"
                                disabled={busy}
                                onClick={
                                    operation === 'register' && step > 0
                                        ? () => {
                                              setStep(step - 1);
                                              setErrors([]);
                                          }
                                        : close
                                }
                            >
                                {operation === 'register' && step > 0
                                    ? 'Back'
                                    : 'Cancel'}
                            </button>
                            {operation === 'register' && step < 2 ? (
                                <button
                                    className={primary}
                                    type="button"
                                    onClick={nextStep}
                                >
                                    Continue
                                    <ArrowRight size={16} />
                                </button>
                            ) : (
                                <button
                                    className={primary}
                                    type="submit"
                                    disabled={
                                        busy ||
                                        (operation === 'register' &&
                                            form.schoolIds.length < 1)
                                    }
                                >
                                    {busy
                                        ? 'Saving…'
                                        : operation === 'register'
                                          ? 'Register paused terminal'
                                          : operation === 'verify'
                                            ? 'Check contact'
                                            : 'Confirm and save'}
                                </button>
                            )}
                        </div>
                    </form>
                )}
            </dialog>
        </div>
    );
}

function Blockers({ device }: { device: Device }) {
    return (
        <section className={`${panel} p-6`}>
            <div className="flex items-center gap-2">
                <Fingerprint size={19} className="text-slate-500" />
                <h2 className="font-semibold">Identity & scope safeguards</h2>
            </div>
            {device.change_blockers.length ? (
                <ul className="mt-4 space-y-3 text-sm leading-6 text-slate-600">
                    {device.change_blockers.map((reason) => (
                        <li key={reason} className="flex gap-2">
                            <span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                            {reason}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-3 text-sm leading-6 text-slate-500">
                    This unused, paused installation can change school scope or
                    credentials with serial confirmation and an audit reason.
                </p>
            )}
        </section>
    );
}
function Pagination<T>({ page }: { page: Page<T> | null }) {
    if (!page || page.last_page <= 1) return null;
    return (
        <nav
            aria-label="Pagination"
            className="flex items-center justify-between gap-3 border-t p-5 text-xs text-slate-500"
        >
            <span>
                Page {page.current_page} of {page.last_page}
            </span>
            <div className="flex gap-2">
                {page.prev_page_url && (
                    <Link href={page.prev_page_url} className={button}>
                        Previous
                    </Link>
                )}
                {page.next_page_url && (
                    <Link href={page.next_page_url} className={button}>
                        Next
                    </Link>
                )}
            </div>
        </nav>
    );
}
