import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useRef, useState } from 'react';

type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Rules = {
    reason_codes: string[];
    required_checks?: string[];
    validity_seconds?: number;
};
type Scan = {
    id: number;
    occurred_at: string;
    received_at: string;
    device_uid: string;
    decision: { status: string; reason: string } | null;
    operator_rules: { correction?: Rules; resolution?: Rules } | null;
};
type Movement = {
    event_id: string;
    observation_id: number;
    revision: number;
    state: string;
    movement: string;
    occurred_at: string;
    decision_source: string;
};
type Action = {
    id: string;
    kind: string;
    role: string;
    actor_id: number;
    reason_code: string;
    note: string;
    status: string;
    stale: boolean;
    movement_id: string | null;
    created_at: string;
    expires_at: string | null;
    expired: boolean;
    consumed_observation_id: number | null;
};
type Student = {
    id: number;
    first_name: string;
    last_name: string;
    student_number: string;
};
type Day = {
    id: number;
    student_id: number;
    first_name: string;
    last_name: string;
    student_number: string;
    state: string;
    revision: number;
};
type School = { school_id: number; name: string; timezone: string };
type Detail = {
    id: number;
    student: Student | null;
    day: string;
    state: string;
    revision: number;
    observations: Page<Scan>;
    movements: Movement[];
    movements_truncated: boolean;
    actions: Action[];
    actions_truncated: boolean;
    early_exit: {
        available: boolean;
        version: string;
        rules: Rules | null;
        return_rules: {
            min_seconds: number;
            max_seconds: number;
            checkout_delay_seconds: number;
        } | null;
    } | null;
};
type Props = {
    setupUrl?: string | null;
    actorName: string;
    actorId: number;
    csrfToken: string;
    schools: School[];
    selectedSchool: School;
    selectedDay: string;
    roles: string[];
    days: Page<Day> | null;
    detail: Detail | null;
};
type Choice = {
    key: string;
    label: string;
    operation: string;
    fields: Record<string, string | number>;
    reasons: string[];
};
const label = (text: string) => text.replaceAll('_', ' ');
const fieldClass =
    'w-full rounded border border-slate-400 bg-white p-2 text-slate-950';

function Pages({ page }: { page: Page<unknown> }) {
    return (
        <nav aria-label="Results pages" className="my-3 flex flex-wrap gap-5">
            {page.prev_page_url && (
                <Link className="underline" href={page.prev_page_url}>
                    Previous
                </Link>
            )}
            <span>
                Page {page.current_page} of {page.last_page}
            </span>
            {page.next_page_url && (
                <Link className="underline" href={page.next_page_url}>
                    Next
                </Link>
            )}
        </nav>
    );
}

function ActionForm({
    detail,
    roles,
    actorId,
    csrfToken,
}: Pick<Props, 'roles' | 'actorId' | 'csrfToken'> & { detail: Detail }) {
    const master = roles.includes('discipline_master');
    const guard = roles.includes('guard');
    const choices: Choice[] = [];
    const scans = new Map(detail.observations.data.map((s) => [s.id, s]));
    if (master || guard) {
        for (const scan of detail.observations.data) {
            if (scan.decision?.reason === 'first_scan_requires_guard') {
                for (const movement of ['check_in', 'check_out'])
                    choices.push({
                        key: `resolve-${scan.id}-${movement}`,
                        label: `Confirm ${label(movement)} for unresolved scan ${scan.id}`,
                        operation: 'resolve',
                        fields: { observation_id: scan.id, movement },
                        reasons:
                            scan.operator_rules?.resolution?.reason_codes ?? [],
                    });
            }
        }
        if (detail.early_exit?.available)
            choices.push({
                key: 'permit',
                label: 'Prepare supervised early exit (requires a new scan)',
                operation: 'permit_exit',
                fields: {},
                reasons: detail.early_exit.rules?.reason_codes ?? [],
            });
        for (const permit of detail.actions.filter(
            (a) =>
                a.kind === 'permit_exit' &&
                a.status === 'active' &&
                !a.expired &&
                (master || a.actor_id === actorId),
        )) {
            choices.push({
                key: `cancel-${permit.id}`,
                label: `Cancel unused exit prepared ${permit.created_at} UTC`,
                operation: 'cancel_exit',
                fields: { action_id: permit.id },
                reasons: [permit.reason_code],
            });
        }
        for (const movement of detail.movements.filter(
            (m) => m.state === 'active',
        )) {
            choices.push({
                key: `correct-${movement.event_id}`,
                label: `${master ? 'Correct directly: exclude' : 'Request correction: exclude'} ${label(movement.movement)} · scan ${movement.observation_id}`,
                operation: master ? 'correct' : 'request_correction',
                fields: { movement_id: movement.event_id },
                reasons:
                    scans.get(movement.observation_id)?.operator_rules
                        ?.correction?.reason_codes ?? [],
            });
        }
    }
    if (!master && roles.includes('correction_approver')) {
        for (const request of detail.actions.filter(
            (a) =>
                a.kind === 'correction_request' &&
                a.status === 'pending' &&
                !a.stale &&
                a.actor_id !== actorId,
        )) {
            choices.push({
                key: `approve-${request.id}`,
                label: `Approve correction request by operator ${request.actor_id}`,
                operation: 'approve_correction',
                fields: { action_id: request.id },
                reasons: [request.reason_code],
            });
        }
    }
    const [choiceKey, setChoiceKey] = useState('');
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [checks, setChecks] = useState<string[]>([]);
    const [returnAllowed, setReturnAllowed] = useState(false);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const retry = useRef({ signature: '', key: '' });
    const choice = choices.find((c) => c.key === choiceKey);
    const required =
        choice?.operation === 'permit_exit'
            ? (detail.early_exit?.rules?.required_checks ?? [])
            : [];
    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!choice || !confirmed || !reason || busy) return;
        const body = {
            operation: choice.operation,
            expected_revision: detail.revision,
            reason_code: reason,
            ...(note.trim() ? { note: note.trim() } : {}),
            ...choice.fields,
            ...(choice.operation === 'permit_exit'
                ? { checks: [...checks].sort(), return_allowed: returnAllowed }
                : {}),
        };
        const signature = JSON.stringify(body);
        if (retry.current.signature !== signature)
            retry.current = { signature, key: crypto.randomUUID() };
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const response = await fetch(
                `/admin/gate/days/${detail.id}/actions`,
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
            if (!response.ok)
                throw new Error(
                    result.message ||
                        'Action was not confirmed. Refresh and review the current gate record.',
                );
            setMessage(
                choice.operation === 'permit_exit'
                    ? 'Authorization saved. Waiting for a fresh successful scan; no checkout has been recorded by this action.'
                    : choice.operation === 'request_correction'
                      ? 'Correction request saved. The original movement is unchanged pending an authorized decision.'
                      : 'Action saved with audit history. Review the updated recorded state below.',
            );
            setConfirmed(false);
            setChoiceKey('');
            setNote('');
            retry.current = { signature: '', key: '' };
            router.reload();
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Unable to confirm the action.',
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <section
            className="space-y-4 rounded border bg-white p-5"
            aria-labelledby="gate-action-title"
        >
            <h2 id="gate-action-title" className="text-xl font-semibold">
                Supervised gate action
            </h2>
            <p>
                Guards request corrections. Discipline masters correct directly
                without another approval. Original scans and earlier revisions
                are retained.
            </p>
            {message && (
                <p
                    role="status"
                    className="rounded bg-green-50 p-3 text-green-900"
                >
                    {message}
                </p>
            )}
            {error && (
                <p role="alert" className="rounded bg-red-50 p-3 text-red-900">
                    {error}
                </p>
            )}
            {!choices.length ? (
                <p>No action is available for this role and recorded state.</p>
            ) : (
                <form onSubmit={submit} className="max-w-2xl space-y-4">
                    <label className="block">
                        Action
                        <select
                            aria-label="Action"
                            className={fieldClass}
                            value={choiceKey}
                            disabled={busy}
                            onChange={(e) => {
                                setChoiceKey(e.target.value);
                                setReason('');
                                setChecks([]);
                                setReturnAllowed(false);
                                setConfirmed(false);
                                setError('');
                            }}
                        >
                            <option value="">Select an action</option>
                            {choices.map((c) => (
                                <option key={c.key} value={c.key}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {choice && (
                        <>
                            {!choice.reasons.length && (
                                <p className="rounded bg-amber-50 p-3">
                                    School reason rules are not configured for
                                    this target, or its scan is on another page.
                                    No action can be submitted.
                                </p>
                            )}
                            <label className="block">
                                Required reason
                                <select
                                    aria-label="Required reason"
                                    required
                                    className={fieldClass}
                                    disabled={busy}
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                >
                                    <option value="">
                                        Select a school-configured reason
                                    </option>
                                    {choice.reasons.map((r) => (
                                        <option key={r} value={r}>
                                            {label(r)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="block">
                                Optional internal audit note
                                <textarea
                                    maxLength={500}
                                    disabled={busy}
                                    className={fieldClass}
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                            </label>
                            {choice.operation === 'permit_exit' && (
                                <fieldset className="space-y-3 rounded border p-3">
                                    <legend>School release checks</legend>
                                    <p>
                                        Authorization validity:{' '}
                                        {detail.early_exit?.rules
                                            ?.validity_seconds ??
                                            'not configured'}{' '}
                                        seconds, capped at departure start.
                                        Approval alone is not a departure.
                                    </p>
                                    {!required.length && (
                                        <p>
                                            Required school checks are missing;
                                            preparation is blocked.
                                        </p>
                                    )}
                                    {required.map((check) => (
                                        <label
                                            key={check}
                                            className="flex items-start gap-3"
                                        >
                                            <input
                                                type="checkbox"
                                                disabled={busy}
                                                checked={checks.includes(check)}
                                                onChange={(e) =>
                                                    setChecks(
                                                        e.target.checked
                                                            ? [...checks, check]
                                                            : checks.filter(
                                                                  (c) =>
                                                                      c !==
                                                                      check,
                                                              ),
                                                    )
                                                }
                                            />
                                            {label(check)}
                                        </label>
                                    ))}
                                    <label className="flex items-start gap-3">
                                        <input
                                            type="checkbox"
                                            disabled={
                                                busy ||
                                                !detail.early_exit?.return_rules
                                            }
                                            checked={returnAllowed}
                                            onChange={(e) =>
                                                setReturnAllowed(
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        Allow one automatic return under the
                                        displayed school timing
                                    </label>
                                    {detail.early_exit?.return_rules ? (
                                        <p>
                                            Return:{' '}
                                            {
                                                detail.early_exit.return_rules
                                                    .min_seconds
                                            }
                                            –
                                            {
                                                detail.early_exit.return_rules
                                                    .max_seconds
                                            }{' '}
                                            seconds after the actual exit. After
                                            a return, checkout requires{' '}
                                            {
                                                detail.early_exit.return_rules
                                                    .checkout_delay_seconds
                                            }{' '}
                                            seconds and a new eligible departure
                                            scan.
                                        </p>
                                    ) : (
                                        <p>
                                            Automatic return is unavailable:
                                            school timing is not configured.
                                        </p>
                                    )}
                                </fieldset>
                            )}
                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    checked={confirmed}
                                    disabled={busy}
                                    onChange={(e) =>
                                        setConfirmed(e.target.checked)
                                    }
                                />
                                I checked the student, selected record and
                                school procedure. I understand this action
                                preserves an audit trail.
                            </label>
                            <button
                                type="submit"
                                disabled={
                                    busy ||
                                    !confirmed ||
                                    !reason ||
                                    (choice.operation === 'permit_exit' &&
                                        (!required.length ||
                                            required.some(
                                                (c) => !checks.includes(c),
                                            )))
                                }
                                className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-40"
                            >
                                {busy
                                    ? 'Saving…'
                                    : choice.operation === 'request_correction'
                                      ? 'Submit correction request'
                                      : 'Save gate action'}
                            </button>
                        </>
                    )}
                </form>
            )}
        </section>
    );
}

export default function Index({
    setupUrl,
    actorName,
    actorId,
    csrfToken,
    schools,
    selectedSchool,
    selectedDay,
    roles,
    days,
    detail,
}: Props) {
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
            <Head title="Gate workspace" />
            <header className="flex flex-wrap items-center justify-between gap-4 border-b bg-white p-5">
                <div>
                    <h1 className="text-2xl font-semibold">Gate workspace</h1>
                    <p>
                        {actorName} · {roles.map(label).join(', ')}
                    </p>
                </div>
                <div className="flex gap-5">
                    {setupUrl && <Link className="underline" href={setupUrl}>School setup (draft)</Link>}
                    <button
                        className="underline"
                        onClick={() => router.reload()}
                    >
                        Refresh records
                    </button>
                    <form action="/admin/logout" method="post">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <button className="underline" type="submit">
                            Sign out
                        </button>
                    </form>
                </div>
            </header>
            <main className="mx-auto max-w-6xl space-y-5 p-4 sm:p-6">
                <p className="rounded border border-blue-300 bg-blue-50 p-4">
                    Recorded gate state is based on scans and school rules—not
                    proof of physical passage or classroom attendance. Nothing
                    here opens a door. Confirm school policy and supervision
                    before acting.
                </p>
                <div>
                    <h2 className="text-xl font-semibold">
                        {selectedSchool.name} · {selectedDay}
                    </h2>
                    <p>
                        Times: {selectedSchool.timezone}. Reason notes are
                        internal, not parent notification previews.
                    </p>
                </div>
                {days && (
                    <>
                        <form
                            action="/admin/gate"
                            method="get"
                            className="flex flex-wrap items-end gap-4 rounded border bg-white p-4"
                        >
                            <label>
                                Assigned school
                                <select
                                    name="school_id"
                                    aria-label="Assigned school"
                                    defaultValue={selectedSchool.school_id}
                                    className={fieldClass}
                                >
                                    {schools.map((s) => (
                                        <option
                                            value={s.school_id}
                                            key={s.school_id}
                                        >
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                School date
                                <input
                                    type="date"
                                    name="day"
                                    defaultValue={selectedDay}
                                    required
                                    className={fieldClass}
                                />
                            </label>
                            <button className="rounded bg-slate-900 px-4 py-2 text-white">
                                Show records
                            </button>
                        </form>
                        <section className="overflow-x-auto rounded border bg-white p-4">
                            <h2 className="mb-3 text-xl font-semibold">
                                Recorded student days
                            </h2>
                            <table className="w-full text-left">
                                <thead>
                                    <tr>
                                        <th className="p-2">Student</th>
                                        <th className="p-2">Recorded state</th>
                                        <th className="p-2">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {days.data.map((d) => (
                                        <tr key={d.id} className="border-t">
                                            <td className="p-2">
                                                {d.last_name} {d.first_name}
                                                <span className="block text-sm">
                                                    {d.student_number}
                                                </span>
                                            </td>
                                            <td className="p-2">
                                                {label(d.state)}
                                            </td>
                                            <td className="p-2">
                                                <Link
                                                    className="underline"
                                                    href={`/admin/gate/days/${d.id}`}
                                                >
                                                    Open student day
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                    {!days.data.length && (
                                        <tr>
                                            <td className="p-4" colSpan={3}>
                                                No processed gate days for this
                                                date. This does not mean
                                                students are absent.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                            <Pages page={days} />
                        </section>
                    </>
                )}
                {detail && (
                    <>
                        <Link
                            className="inline-block underline"
                            href={`/admin/gate?school_id=${selectedSchool.school_id}&day=${selectedDay}`}
                        >
                            Back to school gate records
                        </Link>
                        <section className="rounded border bg-white p-5">
                            <h2 className="text-xl font-semibold">
                                {detail.student?.last_name}{' '}
                                {detail.student?.first_name} ·{' '}
                                {detail.student?.student_number ??
                                    'Historical student record'}
                            </h2>
                            <p>
                                Recorded state:{' '}
                                <strong>{label(detail.state)}</strong> ·
                                revision {detail.revision}
                            </p>
                            {detail.state === 'unknown' && (
                                <p className="mt-3 text-amber-900">
                                    Needs review. Do not assume this student is
                                    inside or outside.
                                </p>
                            )}
                            {!detail.early_exit?.available && (
                                <p className="mt-3">
                                    Early-exit preparation is unavailable for
                                    this state/date or current policy. No
                                    fallback school timing is assumed.
                                </p>
                            )}
                        </section>
                        <ActionForm
                            detail={detail}
                            roles={roles}
                            actorId={actorId}
                            csrfToken={csrfToken}
                        />
                        <section className="overflow-x-auto rounded border bg-white p-5">
                            <h2 className="text-xl font-semibold">
                                Movement history
                            </h2>
                            {detail.movements_truncated && (
                                <p>Showing the latest 200 movements only.</p>
                            )}
                            <table className="mt-3 w-full text-left">
                                <thead>
                                    <tr>
                                        <th className="p-2">Movement / time</th>
                                        <th className="p-2">State / source</th>
                                        <th className="p-2">Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {detail.movements.map((m) => (
                                        <tr
                                            className="border-t"
                                            key={m.event_id}
                                        >
                                            <td className="p-2">
                                                {label(m.movement)}
                                                <span className="block text-sm">
                                                    {time(m.occurred_at)}
                                                </span>
                                            </td>
                                            <td className="p-2">
                                                {label(m.state)} ·{' '}
                                                {label(m.decision_source)}
                                            </td>
                                            <td className="p-2">
                                                Scan {m.observation_id} ·
                                                revision {m.revision}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!detail.movements.length && (
                                <p>No accepted movement.</p>
                            )}
                        </section>
                        <section className="overflow-x-auto rounded border bg-white p-5">
                            <h2 className="text-xl font-semibold">
                                Scan decisions
                            </h2>
                            <table className="mt-3 w-full text-left">
                                <thead>
                                    <tr>
                                        <th className="p-2">Scan / gate</th>
                                        <th className="p-2">
                                            Original occurrence
                                        </th>
                                        <th className="p-2">Decision</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {detail.observations.data.map((s) => (
                                        <tr className="border-t" key={s.id}>
                                            <td className="p-2">
                                                {s.id}
                                                <span className="block text-sm">
                                                    {s.device_uid}
                                                </span>
                                            </td>
                                            <td className="p-2">
                                                {time(s.occurred_at)}
                                            </td>
                                            <td className="p-2">
                                                {label(
                                                    s.decision?.reason ??
                                                        'unprocessed',
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <Pages page={detail.observations} />
                        </section>
                        <section className="rounded border bg-white p-5">
                            <h2 className="text-xl font-semibold">
                                Action audit
                            </h2>
                            {detail.actions_truncated && (
                                <p>Showing the latest 100 actions only.</p>
                            )}
                            <ul className="mt-3 divide-y">
                                {detail.actions.map((a) => (
                                    <li key={a.id} className="space-y-1 py-3">
                                        <p>
                                            {label(a.kind)} ·{' '}
                                            {a.stale
                                                ? 'stale (reassessment required)'
                                                : a.expired &&
                                                    a.status === 'active'
                                                  ? 'expired (unused)'
                                                  : label(a.status)}
                                        </p>
                                        <p className="text-sm">
                                            Operator {a.actor_id} (
                                            {label(a.role)}) ·{' '}
                                            {time(a.created_at)} ·{' '}
                                            {label(a.reason_code)}
                                        </p>
                                        {a.note && (
                                            <p className="text-sm whitespace-pre-wrap">
                                                {a.note}
                                            </p>
                                        )}
                                        {a.expires_at && (
                                            <p className="text-sm">
                                                Expires {time(a.expires_at)}
                                            </p>
                                        )}
                                        {a.consumed_observation_id && (
                                            <p className="text-sm">
                                                Consumed by scan{' '}
                                                {a.consumed_observation_id}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {!detail.actions.length && (
                                <p>No operator actions have been recorded.</p>
                            )}
                        </section>
                    </>
                )}
            </main>
        </div>
    );
}
