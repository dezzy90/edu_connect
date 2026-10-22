import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';

type TimeKey =
    | 'arrival_start'
    | 'arrival_end'
    | 'normal_arrival_end'
    | 'late_cutoff'
    | 'departure_start'
    | 'departure_end';
type NumberKey =
    | 'repeat_seconds'
    | 'reversal_seconds'
    | 'future_tolerance_seconds'
    | 'resolution_max_age_seconds'
    | 'early_exit_validity_seconds'
    | 'return_min_seconds'
    | 'return_max_seconds'
    | 'return_checkout_delay_seconds';
type ListKey =
    | 'closed_dates'
    | 'correction_reason_codes'
    | 'resolution_reason_codes'
    | 'early_exit_reason_codes'
    | 'early_exit_required_checks';
type Configuration = Record<TimeKey, string | null> &
    Record<NumberKey, number | null> &
    Record<ListKey, string[]> & {
        timezone: string | null;
        roster_mode: 'all_active' | 'selected';
        weekdays: number[];
        class_days: { date: string; class_ids: number[] }[];
        student_ids: number[];
        device_ids: number[];
        late_at_cutoff: boolean | null;
        resolution_enabled: boolean;
        early_exit_enabled: boolean;
        return_enabled: boolean;
        early_exit_roles: string[];
    };
type Student = {
    id: number;
    first_name: string;
    last_name: string;
    student_number: string;
};
type StudentPage = {
    data: Student[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};
type Props = {
    actorName: string;
    csrfToken: string;
    gateUrl: string | null;
    deviceRegistryUrl?: string | null;
    schools: { school_id: number; name: string; timezone: string }[];
    selectedSchool: {
        school_id: number;
        tenant_id: number;
        name: string;
        timezone: string;
    };
    draft: {
        revision: number;
        state: 'draft';
        configuration: Configuration;
        updated_at: string | null;
    };
    readiness: {
        configuration_complete: boolean;
        activation_available: boolean;
        missing: { field: string; message: string }[];
        checks: { key: string; ok: boolean; message: string }[];
    };
    activation?: {
        available: boolean;
        can_activate: boolean;
        can_pause: boolean;
        current: {
            id: number;
            draft_revision: number;
            starts_on: string;
            state: 'active' | 'paused';
            activated_at: string;
            paused_at: string | null;
            last_materialized_at: string | null;
            materialization_error: string | null;
        } | null;
        checks: { key: string; ok: boolean; message: string }[];
    };
    connection: {
        matched: boolean;
        connection_id: number | null;
        eduadmin_school_id: number | null;
    };
    students: StudentPage;
    rosterEligibility: {
        mode: 'all_active' | 'selected';
        eligible_total: number;
        configured_total: number;
        includes_future_students: boolean;
    };
    selectedStudents: Student[];
    search: string;
    devices: {
        id: number;
        name: string;
        device_uid: string;
        shared_gate?: boolean;
    }[];
    devices_truncated: boolean;
    classes?: { id: number; full_name: string }[];
    classes_truncated?: boolean;
    staff: { id: number; name: string; role: string }[];
    staff_truncated: boolean;
    history: {
        revision: number;
        basis_revision: number;
        actor_id: number;
        actor_name: string | null;
        created_at: string;
    }[];
};
const inputClass =
    'w-full rounded border border-slate-400 bg-white p-2 text-slate-950';
const panelClass = 'space-y-4 rounded border bg-white p-5';
const readable = (value: string) => value.replaceAll('_', ' ');
const initialLists = (c: Configuration) =>
    Object.fromEntries(
        (
            [
                'closed_dates',
                'correction_reason_codes',
                'resolution_reason_codes',
                'early_exit_reason_codes',
                'early_exit_required_checks',
            ] as ListKey[]
        ).map((key) => [key, c[key].join('\n')]),
    ) as Record<ListKey, string>;

export default function Setup(props: Props) {
    const { draft, readiness, selectedSchool, actorName, csrfToken, schools } =
        props;
    const [c, setC] = useState<Configuration>(draft.configuration);
    const [lists, setLists] = useState(initialLists(draft.configuration));
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [errors, setErrors] = useState<string[]>([]);
    const [roster, setRoster] = useState(props.students);
    const [search, setSearch] = useState(props.search);
    const [searching, setSearching] = useState(false);
    const [knownStudents, setKnownStudents] = useState(
        new Map(
            [...props.students.data, ...props.selectedStudents].map((s) => [
                s.id,
                s,
            ]),
        ),
    );
    const retry = useRef({ signature: '', key: '' });
    const publicationRetry = useRef({ signature: '', key: '' });
    const [startsOn, setStartsOn] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [pauseReason, setPauseReason] = useState('planned_pause');
    const [classDayDate, setClassDayDate] = useState('');
    const [classDayClasses, setClassDayClasses] = useState<number[]>([]);
    const searchSequence = useRef(0);
    const selectedIds = useRef(c.student_ids);
    selectedIds.current = c.student_ids;
    useEffect(() => {
        setC(draft.configuration);
        setLists(initialLists(draft.configuration));
        setDirty(false);
        setConfirmed(false);
    }, [draft.configuration, draft.revision, selectedSchool.school_id]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (dirty) event.preventDefault();
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);
    function update<K extends keyof Configuration>(
        key: K,
        value: Configuration[K],
    ) {
        setC((old) => ({ ...old, [key]: value }));
        setDirty(true);
        setMessage('');
    }
    function toggle(
        key: 'student_ids' | 'device_ids' | 'weekdays',
        id: number,
        checked: boolean,
    ) {
        update(
            key,
            checked ? [...c[key], id] : c[key].filter((value) => value !== id),
        );
    }
    function changeRosterMode(mode: Configuration['roster_mode']) {
        setC((old) => ({
            ...old,
            roster_mode: mode,
            student_ids: mode === 'all_active' ? [] : old.student_ids,
        }));
        setDirty(true);
        setMessage('');
    }
    const number = (key: NumberKey, title: string) => (
        <label className="block" key={key}>
            {title} (seconds)
            <input
                className={inputClass}
                type="number"
                min={key === 'future_tolerance_seconds' ? 0 : 1}
                max={86400}
                step={1}
                value={c[key] ?? ''}
                onChange={(e) =>
                    update(
                        key,
                        e.target.value === '' ? null : Number(e.target.value),
                    )
                }
            />
        </label>
    );
    const list = (key: ListKey, title: string) => (
        <label className="block" key={key}>
            {title}
            <textarea
                className={inputClass}
                rows={3}
                value={lists[key]}
                onChange={(e) => {
                    const value = e.target.value;
                    setLists((old) => ({ ...old, [key]: value }));
                    update(
                        key,
                        value
                            .split(/[\n,]+/)
                            .map((item) => item.trim())
                            .filter(Boolean),
                    );
                }}
            />
            <span className="text-sm">
                {key === 'closed_dates'
                    ? 'One YYYY-MM-DD date per line, at most 366.'
                    : 'One lowercase code per line; use underscores, not spaces. At most 50 codes.'}
            </span>
        </label>
    );
    async function searchStudents(page = 1) {
        const sequence = ++searchSequence.current;
        setSearching(true);
        setErrors([]);
        try {
            const query = new URLSearchParams({
                school_id: String(selectedSchool.school_id),
                q: search,
                page: String(page),
            });
            const response = await fetch(`/admin/gate/setup?${query}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const result = await response.json();
            if (sequence !== searchSequence.current) return;
            if (!response.ok)
                throw new Error('Unable to load this school’s student list.');
            const incoming = result.data.students as StudentPage;
            setRoster(incoming);
            setKnownStudents(
                (old) =>
                    new Map(
                        [...old.values()]
                            .filter((s) => selectedIds.current.includes(s.id))
                            .concat(incoming.data)
                            .map((s) => [s.id, s]),
                    ),
            );
        } catch {
            if (sequence === searchSequence.current)
                setErrors([
                    'Student search was not confirmed. Your unsaved draft is unchanged.',
                ]);
        } finally {
            if (sequence === searchSequence.current) setSearching(false);
        }
    }
    async function save(event: FormEvent) {
        event.preventDefault();
        if (busy) return;
        const body = { expected_revision: draft.revision, configuration: c };
        const signature = JSON.stringify(body);
        if (retry.current.signature !== signature)
            retry.current = { signature, key: crypto.randomUUID() };
        setBusy(true);
        setErrors([]);
        setMessage('');
        try {
            const response = await fetch(
                `/admin/gate/setup/schools/${selectedSchool.school_id}`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        ...body,
                        idempotency_key: retry.current.key,
                    }),
                },
            );
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                setErrors(
                    result.errors
                        ? Object.values(result.errors).flat().map(String)
                        : [
                              result.message ||
                                  'Draft save was not confirmed. Review the saved version before retrying.',
                          ],
                );
                return;
            }
            setMessage(
                `Draft revision ${result.data.saved_revision} saved. No policy was activated and no device command or notification was created.`,
            );
            setDirty(false);
            retry.current = { signature: '', key: '' };
            await new Promise<void>((resolve) =>
                router.reload({
                    only: [
                        'draft',
                        'readiness',
                        'history',
                        'selectedStudents',
                        'rosterEligibility',
                        'activation',
                    ],
                    onFinish: () => resolve(),
                }),
            );
        } catch {
            setErrors([
                'Save was not confirmed. Retrying unchanged inputs reuses the same request key.',
            ]);
        } finally {
            setBusy(false);
        }
    }
    async function publicationAction(operation: 'activate' | 'pause') {
        if (
            busy ||
            (operation === 'activate' &&
                (dirty ||
                    !confirmed ||
                    !startsOn ||
                    !props.activation?.can_activate))
        )
            return;
        if (operation === 'pause' && !props.activation?.can_pause) return;
        const suffix =
            operation === 'activate'
                ? 'activate'
                : `publications/${props.activation?.current?.id}/pause`;
        const values =
            operation === 'activate'
                ? {
                      expected_revision: draft.revision,
                      starts_on: startsOn,
                      confirmed,
                  }
                : { reason_code: pauseReason };
        const signature = JSON.stringify([
            selectedSchool.school_id,
            suffix,
            values,
        ]);
        if (publicationRetry.current.signature !== signature)
            publicationRetry.current = { signature, key: crypto.randomUUID() };
        setBusy(true);
        setErrors([]);
        setMessage('');
        try {
            const response = await fetch(
                `/admin/gate/setup/schools/${selectedSchool.school_id}/${suffix}`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        ...values,
                        idempotency_key: publicationRetry.current.key,
                    }),
                },
            );
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                setErrors(
                    result.errors
                        ? Object.values(result.errors).flat().map(String)
                        : [
                              result.message ||
                                  'Publication action was not confirmed. Reload the checklist before retrying.',
                          ],
                );
                return;
            }
            setMessage(
                `Publication ${result.data.id} is ${result.data.state}. ${result.data.state === 'active' ? 'The saved school rules are published from the selected date; enrollment settings are unchanged.' : 'New gate movements are paused. Earlier records and device enrollment are unchanged.'}`,
            );
            publicationRetry.current = { signature: '', key: '' };
            setConfirmed(false);
            await new Promise<void>((resolve) =>
                router.reload({
                    only: ['activation', 'readiness'],
                    onFinish: () => resolve(),
                }),
            );
        } catch {
            setErrors([
                'Publication action was not confirmed. Retrying unchanged inputs reuses the same request key.',
            ]);
        } finally {
            setBusy(false);
        }
    }
    const time = (value: string) =>
        new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'medium',
            timeStyle: 'medium',
            timeZone: selectedSchool.timezone,
        }).format(
            new Date(
                value.includes('T') ? value : `${value.replace(' ', 'T')}Z`,
            ),
        );
    return (
        <div className="min-h-screen bg-slate-50 text-slate-950">
            <Head title="School setup and activation" />
            <header className="flex flex-wrap items-center justify-between gap-4 border-b bg-white p-5">
                <div>
                    <h1 className="text-2xl font-semibold">School setup</h1>
                    <p>{actorName} · explicitly assigned setup coordinator</p>
                </div>
                <form action="/admin/logout" method="post">
                    <input type="hidden" name="_token" value={csrfToken} />
                    <button type="submit" className="underline">
                        Sign out
                    </button>
                </form>
            </header>
            <main className="mx-auto max-w-6xl space-y-5 p-4 sm:p-6">
                <div className="space-y-2 rounded border border-amber-300 bg-amber-50 p-4">
                    <h2 className="text-xl font-semibold">
                        Prepare first, publish separately
                    </h2>
                    <p>
                        Saving does not publish gate rules, enroll students,
                        contact a device, grant staff permissions or change
                        EduAdmin data. Publishing requires a separate school
                        activation grant and a complete launch checklist below.
                    </p>
                    <p>
                        EduAdmin remains the student and portrait source.
                        Discipline masters continue to correct gate records
                        directly, without another approval.
                    </p>
                </div>
                <form
                    className={panelClass}
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (
                            dirty &&
                            !window.confirm(
                                'Leave this school and discard unsaved draft changes?',
                            )
                        )
                            return;
                        const id = String(
                            new FormData(e.currentTarget).get('school_id'),
                        );
                        window.location.assign(
                            `/admin/gate/setup?school_id=${encodeURIComponent(id)}`,
                        );
                    }}
                >
                    <label className="block">
                        Assigned setup school
                        <select
                            aria-label="Assigned setup school"
                            className={inputClass}
                            name="school_id"
                            defaultValue={selectedSchool.school_id}
                        >
                            {schools.map((s) => (
                                <option key={s.school_id} value={s.school_id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button className="rounded border px-4 py-2">
                        Open school
                    </button>
                </form>
                <section className={panelClass}>
                    <h2 className="text-xl font-semibold">
                        {selectedSchool.name}
                    </h2>
                    <p>
                        EduConnect school {selectedSchool.school_id} · EduAdmin
                        school{' '}
                        {props.connection.eduadmin_school_id ?? 'not mapped'} ·
                        connection{' '}
                        {props.connection.connection_id ?? 'not configured'}
                    </p>
                    <p>
                        Current school timezone: {selectedSchool.timezone}.
                        Draft timezone must be confirmed separately.
                    </p>
                    <p>
                        Saved draft revision: {draft.revision} ·{' '}
                        {dirty ? 'Unsaved changes' : 'No unsaved changes'}
                    </p>
                    {props.deviceRegistryUrl && (
                        <Link
                            className="underline"
                            href={props.deviceRegistryUrl}
                            onClick={(event) => {
                                if (
                                    dirty &&
                                    !window.confirm(
                                        'Discard unsaved draft changes and view device installations?',
                                    )
                                )
                                    event.preventDefault();
                            }}
                        >
                            Device installations
                        </Link>
                    )}
                    {props.gateUrl && (
                        <Link
                            className="underline"
                            href={props.gateUrl}
                            onClick={(event) => {
                                if (
                                    dirty &&
                                    !window.confirm(
                                        'Discard unsaved draft changes and view gate records?',
                                    )
                                )
                                    event.preventDefault();
                            }}
                        >
                            View gate records
                        </Link>
                    )}
                </section>
                <section
                    className={panelClass}
                    aria-labelledby="publication-heading"
                >
                    <h2
                        id="publication-heading"
                        className="text-xl font-semibold"
                    >
                        School activation
                    </h2>
                    {props.activation?.current ? (
                        <div className="space-y-2 rounded border bg-slate-50 p-3">
                            <p>
                                Publication {props.activation.current.id} ·{' '}
                                {props.activation.current.state === 'active'
                                    ? 'Published'
                                    : 'Paused'}{' '}
                                · saved revision{' '}
                                {props.activation.current.draft_revision}
                            </p>
                            <p>
                                Starts on {props.activation.current.starts_on}{' '}
                                in the confirmed school timezone. Draft changes
                                do not change this published version.
                            </p>
                            <p>
                                Daily calendar last checked:{' '}
                                {props.activation.current.last_materialized_at
                                    ? time(
                                          props.activation.current
                                              .last_materialized_at,
                                      )
                                    : 'Awaiting worker'}
                                .
                            </p>
                            {props.activation.current.materialization_error && (
                                <p role="alert">
                                    Daily calendar generation needs operator
                                    attention. Existing records have not been
                                    rewritten.
                                </p>
                            )}
                        </div>
                    ) : (
                        <p>No school rules have been published.</p>
                    )}
                    <p>
                        Publishing does not enroll students, change portraits or
                        operate a door. Pausing stops new gate movements for
                        this school; it does not erase records or disable the
                        shared terminal for other schools.
                    </p>
                    <ul className="space-y-2">
                        {(
                            props.activation?.checks ?? [
                                {
                                    key: 'release',
                                    ok: false,
                                    message:
                                        'Publication release has not been installed.',
                                },
                            ]
                        ).map((check) => (
                            <li
                                key={check.key}
                                className={
                                    check.ok
                                        ? 'text-green-800'
                                        : 'text-amber-900'
                                }
                            >
                                {check.ok ? 'Ready' : 'Required'} —{' '}
                                {check.message}
                            </li>
                        ))}
                    </ul>
                    {props.activation?.can_pause ? (
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void publicationAction('pause');
                            }}
                        >
                            <label className="block">
                                Pause reason
                                <select
                                    aria-label="Pause reason"
                                    className={inputClass}
                                    value={pauseReason}
                                    onChange={(e) =>
                                        setPauseReason(e.target.value)
                                    }
                                >
                                    <option value="planned_pause">
                                        Planned pause
                                    </option>
                                    <option value="configuration_change">
                                        Prepare a configuration change
                                    </option>
                                    <option value="incident">
                                        Operational incident
                                    </option>
                                </select>
                            </label>
                            <button
                                disabled={busy}
                                className="rounded border border-red-700 px-4 py-2 text-red-800 disabled:opacity-50"
                            >
                                Pause this school’s published rules
                            </button>
                        </form>
                    ) : (
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void publicationAction('activate');
                            }}
                        >
                            <label className="block">
                                Confirmed start date (school timezone)
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={startsOn}
                                    onChange={(e) => {
                                        setStartsOn(e.target.value);
                                        setConfirmed(false);
                                    }}
                                />
                            </label>
                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    checked={confirmed}
                                    onChange={(e) =>
                                        setConfirmed(e.target.checked)
                                    }
                                    disabled={
                                        dirty || !props.activation?.can_activate
                                    }
                                />
                                <span>
                                    I confirm saved revision {draft.revision},
                                    the school rules and the start date. No
                                    unsaved draft edits will be published.
                                </span>
                            </label>
                            {dirty && (
                                <p>
                                    Save or discard draft edits before
                                    publishing.
                                </p>
                            )}
                            <button
                                disabled={
                                    busy ||
                                    dirty ||
                                    !confirmed ||
                                    !startsOn ||
                                    !props.activation?.can_activate
                                }
                                className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50"
                            >
                                Publish saved school rules
                            </button>
                        </form>
                    )}
                </section>
                {message && (
                    <p
                        role="status"
                        className="rounded border bg-green-50 p-4 text-green-900"
                    >
                        {message}
                    </p>
                )}
                {errors.length > 0 && (
                    <div
                        role="alert"
                        className="rounded border bg-red-50 p-4 text-red-900"
                    >
                        <h2 className="font-semibold">Draft needs attention</h2>
                        <ul className="list-inside list-disc">
                            {errors.map((e, i) => (
                                <li key={i}>{e}</li>
                            ))}
                        </ul>
                        <button
                            type="button"
                            className="mt-3 underline"
                            onClick={() => {
                                if (
                                    !dirty ||
                                    window.confirm(
                                        'Reload the saved draft and discard unsaved changes?',
                                    )
                                )
                                    window.location.reload();
                            }}
                        >
                            Reload saved draft
                        </button>
                    </div>
                )}
                <section
                    className={panelClass}
                    aria-labelledby="readiness-title"
                >
                    <h2 id="readiness-title" className="text-xl font-semibold">
                        Saved-draft readiness
                    </h2>
                    <p>
                        {readiness.configuration_complete
                            ? 'Draft fields are complete. This is not activation or production approval.'
                            : `${readiness.missing.length} configuration items remain.`}{' '}
                        Save changes to update this checklist.
                    </p>
                    <ul className="list-inside list-disc">
                        {readiness.missing.map((item) => (
                            <li key={item.field}>{item.message}</li>
                        ))}
                    </ul>
                    <ul className="space-y-2">
                        {readiness.checks.map((check) => (
                            <li key={check.key}>
                                <strong>
                                    {check.ok ? 'Matched' : 'Needs preparation'}
                                    :
                                </strong>{' '}
                                {check.message}
                            </li>
                        ))}
                    </ul>
                </section>
                <form onSubmit={save} className="space-y-5">
                    <fieldset
                        disabled={busy}
                        className={`${panelClass} min-w-0`}
                    >
                        <legend className="text-xl font-semibold">
                            1. Automatic eligibility and assigned devices
                        </legend>
                        <p>
                            All active/enrolled students from the connected
                            EduAdmin school are included by default. New
                            students join automatically when synchronized; no
                            individual approval or selection is required. A
                            usable photo, an assigned device and verified
                            capacity are still required for face readiness.
                            Saving remains draft-only.
                        </p>
                        <fieldset className="space-y-2 rounded border p-3">
                            <legend className="font-semibold">
                                Student eligibility
                            </legend>
                            <label className="flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="roster-mode"
                                    checked={c.roster_mode === 'all_active'}
                                    onChange={() =>
                                        changeRosterMode('all_active')
                                    }
                                />
                                <span>
                                    All active students automatically (default)
                                </span>
                            </label>
                            <label className="flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="roster-mode"
                                    checked={c.roster_mode === 'selected'}
                                    onChange={() =>
                                        changeRosterMode('selected')
                                    }
                                />
                                <span>
                                    Restricted pilot — selected students only
                                </span>
                            </label>
                        </fieldset>
                        <p>
                            Current eligible school roster:{' '}
                            <strong>
                                {props.rosterEligibility.eligible_total}
                            </strong>
                            . This count includes students awaiting a photo; it
                            is not a count of device-ready faces. Suspended,
                            withdrawn and inactive records are excluded.
                        </p>
                        {c.roster_mode === 'all_active' ? (
                            <p className="rounded border border-blue-200 bg-blue-50 p-3">
                                Current and future eligible students are
                                included dynamically, not frozen into a
                                selection list. The 200-student pilot limit does
                                not apply. Devices still serve only their
                                assigned schools. This draft does not expand any
                                existing TEST pilot or activate synchronization.
                            </p>
                        ) : (
                            <>
                                <p>
                                    This is an explicit restricted pilot, not
                                    the default school roster. Select at most
                                    200 students; an empty pilot includes
                                    nobody. Existing restricted drafts stay
                                    restricted until explicitly changed.
                                    Switching this draft to automatic clears its
                                    selection list.
                                </p>
                                <h3 className="font-semibold">
                                    Selected students ({c.student_ids.length})
                                </h3>
                                <ul className="space-y-2">
                                    {c.student_ids.map((id) => (
                                        <li
                                            key={id}
                                            className="flex flex-wrap items-center gap-3"
                                        >
                                            <span>
                                                {knownStudents.get(id)
                                                    ?.student_number ??
                                                    `Unavailable school student ${id}`}
                                            </span>
                                            <button
                                                type="button"
                                                className="underline"
                                                onClick={() =>
                                                    toggle(
                                                        'student_ids',
                                                        id,
                                                        false,
                                                    )
                                                }
                                            >
                                                Remove student {id}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="min-w-0 flex-1">
                                Search school students
                                <input
                                    className={inputClass}
                                    value={search}
                                    maxLength={80}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            void searchStudents();
                                        }
                                    }}
                                />
                            </label>
                            <button
                                type="button"
                                disabled={searching}
                                className="rounded border px-4 py-2"
                                onClick={() => void searchStudents()}
                            >
                                Search students
                            </button>
                        </div>
                        <div className="max-h-72 space-y-2 overflow-auto rounded border p-3">
                            {roster.data.map((s) => (
                                <div
                                    key={s.id}
                                    className="flex items-start gap-3"
                                >
                                    {c.roster_mode === 'selected' && (
                                        <input
                                            type="checkbox"
                                            aria-label={`Select student ${s.student_number}`}
                                            checked={c.student_ids.includes(
                                                s.id,
                                            )}
                                            disabled={
                                                !c.student_ids.includes(s.id) &&
                                                c.student_ids.length >= 200
                                            }
                                            onChange={(e) =>
                                                toggle(
                                                    'student_ids',
                                                    s.id,
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                    )}
                                    <span>
                                        {s.last_name} {s.first_name} ·{' '}
                                        {s.student_number}
                                    </span>
                                </div>
                            ))}
                            {!roster.data.length && (
                                <p>
                                    No matching active students in this school.
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-4">
                            <button
                                type="button"
                                className="underline disabled:opacity-40"
                                disabled={searching || !roster.prev_page_url}
                                onClick={() =>
                                    void searchStudents(roster.current_page - 1)
                                }
                            >
                                Previous students
                            </button>
                            <span>
                                Page {roster.current_page} of {roster.last_page}
                            </span>
                            <button
                                type="button"
                                className="underline disabled:opacity-40"
                                disabled={searching || !roster.next_page_url}
                                onClick={() =>
                                    void searchStudents(roster.current_page + 1)
                                }
                            >
                                Next students
                            </button>
                        </div>
                        <h3 className="font-semibold">School devices</h3>
                        {props.devices.map((d) => (
                            <label
                                key={d.id}
                                className="flex items-start gap-3"
                            >
                                <input
                                    type="checkbox"
                                    aria-label={`Select device ${d.name}`}
                                    checked={c.device_ids.includes(d.id)}
                                    onChange={(e) =>
                                        toggle(
                                            'device_ids',
                                            d.id,
                                            e.target.checked,
                                        )
                                    }
                                />
                                <span>
                                    {d.name} · {d.device_uid}
                                    {d.shared_gate && (
                                        <span className="mt-1 block text-xs text-teal-800">
                                            Shared complex gate · Your school’s
                                            rules remain separate
                                        </span>
                                    )}
                                </span>
                            </label>
                        ))}
                        {!props.devices.length && (
                            <p>
                                No active school device yet. You may still save
                                an incomplete draft.
                            </p>
                        )}
                        {c.device_ids
                            .filter(
                                (id) => !props.devices.some((d) => d.id === id),
                            )
                            .map((id) => (
                                <p key={id}>
                                    Selected device {id} is not in the visible
                                    active list.{' '}
                                    <button
                                        type="button"
                                        className="underline"
                                        onClick={() =>
                                            toggle('device_ids', id, false)
                                        }
                                    >
                                        Remove device {id}
                                    </button>
                                </p>
                            ))}
                        {props.devices_truncated && (
                            <p>
                                Only the first 200 devices are listed. Do not
                                assume unlisted devices are included.
                            </p>
                        )}
                    </fieldset>
                    <fieldset
                        disabled={busy}
                        className={`${panelClass} min-w-0`}
                    >
                        <legend className="text-xl font-semibold">
                            2. School calendar and timetable
                        </legend>
                        <label className="block">
                            Confirmed timezone
                            <input
                                className={inputClass}
                                placeholder="For example: Africa/Douala"
                                value={c.timezone ?? ''}
                                onChange={(e) =>
                                    update('timezone', e.target.value || null)
                                }
                            />
                        </label>
                        <div className="flex flex-wrap gap-4">
                            {[
                                'Monday',
                                'Tuesday',
                                'Wednesday',
                                'Thursday',
                                'Friday',
                                'Saturday',
                                'Sunday',
                            ].map((day, i) => (
                                <label key={day} className="flex gap-2">
                                    <input
                                        type="checkbox"
                                        checked={c.weekdays.includes(i + 1)}
                                        onChange={(e) =>
                                            toggle(
                                                'weekdays',
                                                i + 1,
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    {day}
                                </label>
                            ))}
                        </div>
                        {list('closed_dates', 'Explicit closed dates')}
                        <fieldset className="space-y-3 rounded border p-3">
                            <legend className="px-1 font-semibold">
                                Additional class dates — occasional Saturdays
                            </legend>
                            <p>
                                Choose the exact date and classes. This does not
                                enable every Saturday or every student. Normal
                                operating days still cover the whole school; an
                                explicit closed date always wins. Save and
                                publish the reviewed calendar before the extra
                                day begins.
                            </p>
                            <label className="block">
                                Additional class date
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={classDayDate}
                                    onChange={(e) =>
                                        setClassDayDate(e.target.value)
                                    }
                                />
                            </label>
                            <div className="max-h-64 space-y-2 overflow-y-auto">
                                {(props.classes ?? []).map((cls) => (
                                    <label
                                        key={cls.id}
                                        className="flex items-start gap-2"
                                    >
                                        <input
                                            type="checkbox"
                                            aria-label={`Extra class ${cls.full_name}`}
                                            checked={classDayClasses.includes(
                                                cls.id,
                                            )}
                                            onChange={(e) =>
                                                setClassDayClasses((old) =>
                                                    e.target.checked
                                                        ? [...old, cls.id]
                                                        : old.filter(
                                                              (id) =>
                                                                  id !== cls.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <span>{cls.full_name}</span>
                                    </label>
                                ))}
                            </div>
                            {!(props.classes ?? []).length && (
                                <p>
                                    No active EduAdmin classes are available for
                                    this school yet.
                                </p>
                            )}
                            {props.classes_truncated && (
                                <p>
                                    Class list is bounded to 200. Ask the
                                    installation administrator to review a
                                    larger school before selecting additional
                                    dates.
                                </p>
                            )}
                            <button
                                type="button"
                                disabled={
                                    !classDayDate ||
                                    !classDayClasses.length ||
                                    (c.class_days ?? []).some(
                                        (day) => day.date === classDayDate,
                                    )
                                }
                                className="rounded border px-3 py-2 disabled:opacity-50"
                                onClick={() => {
                                    update(
                                        'class_days',
                                        [
                                            ...(c.class_days ?? []),
                                            {
                                                date: classDayDate,
                                                class_ids: [
                                                    ...classDayClasses,
                                                ].sort((a, b) => a - b),
                                            },
                                        ].sort((a, b) =>
                                            a.date.localeCompare(b.date),
                                        ),
                                    );
                                    setClassDayDate('');
                                    setClassDayClasses([]);
                                }}
                            >
                                Add selected class date
                            </button>
                            <ul className="space-y-2">
                                {(c.class_days ?? []).map((day) => (
                                    <li
                                        key={day.date}
                                        className="flex flex-wrap items-center gap-3"
                                    >
                                        <span>
                                            {day.date} ·{' '}
                                            {day.class_ids
                                                .map(
                                                    (id) =>
                                                        props.classes?.find(
                                                            (cls) =>
                                                                cls.id === id,
                                                        )?.full_name ??
                                                        `Class ${id}`,
                                                )
                                                .join(', ')}
                                        </span>
                                        <button
                                            type="button"
                                            className="underline"
                                            aria-label={`Remove class date ${day.date}`}
                                            onClick={() =>
                                                update(
                                                    'class_days',
                                                    c.class_days.filter(
                                                        (item) =>
                                                            item.date !==
                                                            day.date,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove date
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </fieldset>
                        <p>
                            Optional normal arrival end is inclusive, down to
                            the second. If configured, the interval after it and
                            before the late cutoff is a non-late grace period.
                            Leave it empty to keep a single normal/late
                            boundary.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(
                                [
                                    'arrival_start',
                                    'arrival_end',
                                    'normal_arrival_end',
                                    'late_cutoff',
                                    'departure_start',
                                    'departure_end',
                                ] as TimeKey[]
                            ).map((key) => (
                                <label className="block" key={key}>
                                    {readable(key)}
                                    <input
                                        type="time"
                                        step={1}
                                        className={inputClass}
                                        aria-label={readable(key)}
                                        value={c[key] ?? ''}
                                        onChange={(e) =>
                                            update(
                                                key,
                                                e.target.value
                                                    ? e.target.value.length ===
                                                      5
                                                        ? `${e.target.value}:00`
                                                        : e.target.value
                                                    : null,
                                            )
                                        }
                                    />
                                </label>
                            ))}
                        </div>
                        <label className="block">
                            Is a scan exactly at the late cutoff late?
                            <select
                                aria-label="Is a scan exactly at the late cutoff late?"
                                className={inputClass}
                                value={
                                    c.late_at_cutoff === null
                                        ? ''
                                        : String(c.late_at_cutoff)
                                }
                                onChange={(e) =>
                                    update(
                                        'late_at_cutoff',
                                        e.target.value === ''
                                            ? null
                                            : e.target.value === 'true',
                                    )
                                }
                            >
                                <option value="">Not confirmed</option>
                                <option value="false">No</option>
                                <option value="true">Yes</option>
                            </select>
                        </label>
                    </fieldset>
                    <fieldset
                        disabled={busy}
                        className={`${panelClass} min-w-0`}
                    >
                        <legend className="text-xl font-semibold">
                            3. Scan interpretation and corrections
                        </legend>
                        <p>
                            Timings are not supplied automatically. The current
                            live workflow still uses its own frozen policies.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {number('repeat_seconds', 'Repeat scan interval')}
                            {number(
                                'reversal_seconds',
                                'Minimum direction reversal delay',
                            )}
                            {number(
                                'future_tolerance_seconds',
                                'Permitted future-clock tolerance',
                            )}
                        </div>
                        {list(
                            'correction_reason_codes',
                            'School correction reason codes',
                        )}
                        <label className="flex gap-3">
                            <input
                                type="checkbox"
                                checked={c.resolution_enabled}
                                onChange={(e) =>
                                    update(
                                        'resolution_enabled',
                                        e.target.checked,
                                    )
                                }
                            />
                            Prepare guarded first-departure direction resolution
                        </label>
                        {c.resolution_enabled && (
                            <>
                                {number(
                                    'resolution_max_age_seconds',
                                    'Maximum age of an unresolved scan',
                                )}
                                {list(
                                    'resolution_reason_codes',
                                    'Direction-resolution reason codes',
                                )}
                            </>
                        )}
                    </fieldset>
                    <fieldset
                        disabled={busy}
                        className={`${panelClass} min-w-0`}
                    >
                        <legend className="text-xl font-semibold">
                            4. Supervised early exit and return
                        </legend>
                        <p>
                            A permit alone never records departure. A fresh
                            eligible scan is required. These draft choices
                            change no existing permissions.
                        </p>
                        <label className="flex gap-3">
                            <input
                                type="checkbox"
                                checked={c.early_exit_enabled}
                                onChange={(e) =>
                                    update(
                                        'early_exit_enabled',
                                        e.target.checked,
                                    )
                                }
                            />
                            Prepare supervised early-exit rules
                        </label>
                        {c.early_exit_enabled && (
                            <>
                                <p>
                                    Proposed permitted roles. The current
                                    runtime allows both roles; narrower
                                    selections remain blocked pending a separate
                                    runtime change.
                                </p>
                                {['guard', 'discipline_master'].map((role) => (
                                    <label key={role} className="flex gap-3">
                                        <input
                                            type="checkbox"
                                            checked={c.early_exit_roles.includes(
                                                role,
                                            )}
                                            onChange={(e) =>
                                                update(
                                                    'early_exit_roles',
                                                    e.target.checked
                                                        ? [
                                                              ...c.early_exit_roles,
                                                              role,
                                                          ]
                                                        : c.early_exit_roles.filter(
                                                              (r) => r !== role,
                                                          ),
                                                )
                                            }
                                        />
                                        {readable(role)}
                                    </label>
                                ))}
                                {number(
                                    'early_exit_validity_seconds',
                                    'Proposed exit authorization validity',
                                )}
                                {list(
                                    'early_exit_required_checks',
                                    'Required supervision/release check codes',
                                )}
                                {list(
                                    'early_exit_reason_codes',
                                    'Early-exit reason codes',
                                )}
                            </>
                        )}
                        <label className="flex gap-3">
                            <input
                                type="checkbox"
                                checked={c.return_enabled}
                                onChange={(e) =>
                                    update('return_enabled', e.target.checked)
                                }
                            />
                            Prepare one-use, same-gate return rules
                        </label>
                        {c.return_enabled && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {number(
                                    'return_min_seconds',
                                    'Minimum time before return',
                                )}
                                {number(
                                    'return_max_seconds',
                                    'Maximum time before return',
                                )}
                                {number(
                                    'return_checkout_delay_seconds',
                                    'Delay before checkout after return',
                                )}
                            </div>
                        )}
                    </fieldset>
                    <div className="flex flex-wrap items-center gap-4">
                        <button
                            type="submit"
                            disabled={busy}
                            className="rounded bg-slate-900 px-5 py-3 text-white disabled:opacity-40"
                        >
                            {busy ? 'Saving draft…' : 'Save draft only'}
                        </button>
                        <span>
                            No activation or device synchronization is
                            performed.
                        </span>
                    </div>
                </form>
                <section className={panelClass}>
                    <h2 className="text-xl font-semibold">
                        Current staff assignments — read only
                    </h2>
                    <p>
                        Setup coordinator access does not grant gate actions. A
                        separate authorized staff-management flow is required to
                        change assignments.
                    </p>
                    <ul>
                        {props.staff.map((s) => (
                            <li key={`${s.id}-${s.role}`}>
                                {s.name} · {readable(s.role)}
                            </li>
                        ))}
                    </ul>
                    {!props.staff.length && (
                        <p>No active gate staff assigned.</p>
                    )}
                    {props.staff_truncated && (
                        <p>Showing the first 200 assignments.</p>
                    )}
                </section>
                <section className={panelClass}>
                    <h2 className="text-xl font-semibold">
                        Draft revision history
                    </h2>
                    <p>
                        The latest 20 saved revisions are listed. Earlier
                        snapshots remain stored.
                    </p>
                    <ul className="divide-y">
                        {props.history.map((h) => (
                            <li className="py-3" key={h.revision}>
                                Revision {h.revision} from {h.basis_revision} ·{' '}
                                {h.actor_name ?? 'Historical coordinator'} ·{' '}
                                {time(h.created_at)}
                            </li>
                        ))}
                    </ul>
                    {!props.history.length && (
                        <p>No draft has been saved yet.</p>
                    )}
                </section>
            </main>
        </div>
    );
}
