import React, { useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    AlertCircle,
    Activity,
    CheckCircle,
    Clock,
    Database,
    KeyRound,
    ListChecks,
    PlusCircle,
    PlugZap,
    RefreshCw,
    Save,
    Send,
    Server,
    ShieldCheck,
    XCircle,
} from 'lucide-react';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
    school?: {
        id: number;
        name: string;
    };
}

interface Summary {
    total_connections: number;
    active_connections: number;
    total_mappings: number;
    pending_outbox: number;
    failed_outbox: number;
    sent_outbox: number;
    last_successful_sync_at?: string | null;
}

interface SyncRun {
    id: number;
    sync_type: string;
    direction: string;
    status: string;
    records_read: number;
    records_created: number;
    records_updated: number;
    records_failed: number;
    started_at?: string | null;
    finished_at?: string | null;
    error_message?: string | null;
}

interface Connection {
    id: number;
    tenant?: {
        id: number;
        name: string;
        slug: string;
    } | null;
    provider: string;
    mode: string;
    status: string;
    base_url?: string | null;
    api_version: string;
    remote_tenant_id?: string | null;
    scopes: string[];
    feature_flags: Record<string, boolean>;
    has_access_token: boolean;
    has_webhook_secret: boolean;
    last_successful_sync_at?: string | null;
    last_failed_sync_at?: string | null;
    last_error?: string | null;
    mappings_count: number;
    sync_runs_count: number;
    outbox_events_count: number;
    audit_events_count: number;
    outbox_summary: {
        pending: number;
        failed: number;
        sent: number;
    };
    recent_sync_runs: SyncRun[];
}

interface OutboxEvent {
    id: number;
    connection_id: number;
    tenant_name?: string | null;
    event_type: string;
    event_key: string;
    status: string;
    attempts: number;
    available_at?: string | null;
    sent_at?: string | null;
    last_error?: string | null;
    created_at?: string | null;
}

interface AuditEvent {
    id: number;
    connection_id?: number | null;
    tenant_name?: string | null;
    category: string;
    event_type: string;
    severity: string;
    status?: string | null;
    summary: string;
    metadata: Record<string, unknown>;
    actor_type?: string | null;
    actor_id?: number | null;
    related_type?: string | null;
    related_id?: number | null;
    occurred_at?: string | null;
}

interface SyncItem {
    id: number;
    sync_run_id: number;
    connection_id?: number | null;
    tenant_name?: string | null;
    sync_type?: string | null;
    local_type?: string | null;
    local_id?: number | null;
    external_type?: string | null;
    external_id?: string | null;
    action: string;
    status: string;
    error_message?: string | null;
    created_at?: string | null;
}

interface TenantOption {
    id: number;
    name: string;
    slug: string;
    status: string;
}

interface Props {
    admin: AdminUser;
    isSuper: boolean;
    summary: Summary;
    connections: Connection[];
    availableTenants: TenantOption[];
    connectorScopes: string[];
    connectorDefaultScopes: string[];
    recentOutboxEvents: OutboxEvent[];
    recentAuditEvents: AuditEvent[];
    recentSyncItems: SyncItem[];
}

interface PageProps {
    flash?: {
        success?: string;
    };
    errors?: Record<string, string>;
    [key: string]: unknown;
}

export default function IntegrationsIndex({
    admin,
    isSuper,
    summary,
    connections,
    availableTenants,
    connectorScopes,
    connectorDefaultScopes,
    recentOutboxEvents,
    recentAuditEvents,
    recentSyncItems,
}: Props) {
    const { props } = usePage<PageProps>();
    const [processingKey, setProcessingKey] = useState<string | null>(null);

    const latestSyncRun = useMemo(() => {
        return connections
            .flatMap((connection) =>
                connection.recent_sync_runs.map((run) => ({
                    ...run,
                    tenant_name: connection.tenant?.name || 'Unmapped tenant',
                })),
            )
            .sort((a, b) => new Date(b.started_at || 0).getTime() - new Date(a.started_at || 0).getTime())[0];
    }, [connections]);

    const runInitialSync = (connection: Connection) => {
        const key = `initial-sync-${connection.id}`;
        setProcessingKey(key);

        router.post(
            `/admin/integrations/${connection.id}/sync-initial`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setProcessingKey(null),
            },
        );
    };

    const runIncrementalSync = (connection: Connection) => {
        const key = `incremental-sync-${connection.id}`;
        setProcessingKey(key);

        router.post(
            `/admin/integrations/${connection.id}/sync-incremental`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setProcessingKey(null),
            },
        );
    };

    const pushAttendance = (connection: Connection) => {
        const key = `push-${connection.id}`;
        setProcessingKey(key);

        router.post(
            `/admin/integrations/${connection.id}/push-attendance`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setProcessingKey(null),
            },
        );
    };

    return (
        <AdminLayout admin={admin}>
            <Head title="Edu-admin Integration" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Edu-admin Integration</h1>
                        <p className="text-sm text-gray-600">Connection status, sync runs, and attendance delivery health</p>
                    </div>
                    <Badge variant={summary.active_connections > 0 ? 'success' : 'secondary'} className="h-7 px-3">
                        <PlugZap className="h-3.5 w-3.5" />
                        {summary.active_connections > 0 ? 'Connected' : 'Standalone'}
                    </Badge>
                </div>

                {props.flash?.success && (
                    <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                        {props.flash.success}
                    </div>
                )}

                {props.errors?.integration && (
                    <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        {props.errors.integration}
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        title="Connections"
                        value={`${summary.active_connections}/${summary.total_connections}`}
                        detail="active"
                        icon={Server}
                    />
                    <MetricCard
                        title="Mapped Records"
                        value={summary.total_mappings.toLocaleString()}
                        detail="local to Edu-admin"
                        icon={Database}
                    />
                    <MetricCard
                        title="Pending Outbox"
                        value={summary.pending_outbox.toLocaleString()}
                        detail="waiting to push"
                        icon={Clock}
                    />
                    <MetricCard
                        title="Failed Outbox"
                        value={summary.failed_outbox.toLocaleString()}
                        detail="needs attention"
                        icon={summary.failed_outbox > 0 ? AlertCircle : ShieldCheck}
                        tone={summary.failed_outbox > 0 ? 'danger' : 'success'}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Connections</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-hidden rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tenant</TableHead>
                                        <TableHead>Mode</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Remote</TableHead>
                                        <TableHead>Mappings</TableHead>
                                        <TableHead>Outbox</TableHead>
                                        <TableHead>Last Sync</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {connections.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={8} className="py-8 text-center text-sm text-gray-600">
                                                No Edu-admin connections found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        connections.map((connection) => (
                                            <TableRow key={connection.id}>
                                                <TableCell>
                                                    <div className="font-medium text-gray-900">
                                                        {connection.tenant?.name || 'Unmapped tenant'}
                                                    </div>
                                                    <div className="text-xs text-gray-500">{connection.tenant?.slug || `Connection ${connection.id}`}</div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">{connection.mode}</Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge status={connection.status} />
                                                    {connection.last_error && (
                                                        <div className="mt-1 max-w-56 truncate text-xs text-red-600">{connection.last_error}</div>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="max-w-64 truncate text-sm text-gray-800">{connection.base_url || 'Not set'}</div>
                                                    {connection.remote_tenant_id && (
                                                        <div className="text-xs text-gray-500">Remote {connection.remote_tenant_id}</div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">{connection.mappings_count}</TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        <Badge variant="outline">P {connection.outbox_summary.pending}</Badge>
                                                        <Badge variant={connection.outbox_summary.failed > 0 ? 'destructive' : 'outline'}>
                                                            F {connection.outbox_summary.failed}
                                                        </Badge>
                                                        <Badge variant="outline">S {connection.outbox_summary.sent}</Badge>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="text-sm">{formatDate(connection.last_successful_sync_at)}</div>
                                                    {connection.last_failed_sync_at && (
                                                        <div className="text-xs text-red-600">Failed {formatDate(connection.last_failed_sync_at)}</div>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap justify-end gap-2">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={!canRun(connection) || processingKey !== null}
                                                            onClick={() => runInitialSync(connection)}
                                                        >
                                                            <RefreshCw className="h-4 w-4" />
                                                            {processingKey === `initial-sync-${connection.id}` ? 'Syncing' : 'Initial'}
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={!canRun(connection) || processingKey !== null}
                                                            onClick={() => runIncrementalSync(connection)}
                                                        >
                                                            <RefreshCw className="h-4 w-4" />
                                                            {processingKey === `incremental-sync-${connection.id}` ? 'Syncing' : 'Updates'}
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            disabled={!canRun(connection) || processingKey !== null}
                                                            onClick={() => pushAttendance(connection)}
                                                        >
                                                            <Send className="h-4 w-4" />
                                                            {processingKey === `push-${connection.id}` ? 'Pushing' : 'Push'}
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {isSuper && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <KeyRound className="h-4 w-4 text-gray-600" />
                                Credentials
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <CreateConnectionForm
                                key={availableTenants.map((tenant) => tenant.id).join('-') || 'none'}
                                tenants={availableTenants}
                                scopes={connectorScopes}
                                defaultScopes={connectorDefaultScopes}
                                errors={props.errors || {}}
                            />

                            {connections.map((connection) => (
                                <ConnectionCredentialForm
                                    key={connection.id}
                                    connection={connection}
                                    scopes={connectorScopes}
                                    defaultScopes={connectorDefaultScopes}
                                    errors={props.errors || {}}
                                />
                            ))}
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(340px,420px)]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Recent Outbox Events</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Event</TableHead>
                                            <TableHead>Tenant</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Attempts</TableHead>
                                            <TableHead>Time</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentOutboxEvents.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="py-8 text-center text-sm text-gray-600">
                                                    No outbox events yet.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            recentOutboxEvents.map((event) => (
                                                <TableRow key={event.id}>
                                                    <TableCell>
                                                        <div className="font-medium text-gray-900">{event.event_type}</div>
                                                        <div className="max-w-80 truncate font-mono text-xs text-gray-500">{event.event_key}</div>
                                                        {event.last_error && (
                                                            <div className="mt-1 max-w-80 truncate text-xs text-red-600">{event.last_error}</div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>{event.tenant_name || 'Unmapped tenant'}</TableCell>
                                                    <TableCell>
                                                        <StatusBadge status={event.status} />
                                                    </TableCell>
                                                    <TableCell className="font-mono text-sm">{event.attempts}</TableCell>
                                                    <TableCell>{formatDate(event.sent_at || event.available_at || event.created_at)}</TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Latest Sync</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {latestSyncRun ? (
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <div className="font-medium text-gray-900">{latestSyncRun.tenant_name}</div>
                                            <div className="text-sm text-gray-600">
                                                {latestSyncRun.sync_type} {latestSyncRun.direction}
                                            </div>
                                        </div>
                                        <StatusBadge status={latestSyncRun.status} />
                                    </div>
                                    <div className="grid grid-cols-2 gap-3 text-sm">
                                        <SyncStat label="Read" value={latestSyncRun.records_read} />
                                        <SyncStat label="Created" value={latestSyncRun.records_created} />
                                        <SyncStat label="Updated" value={latestSyncRun.records_updated} />
                                        <SyncStat label="Failed" value={latestSyncRun.records_failed} />
                                    </div>
                                    <div className="text-sm text-gray-600">
                                        Started {formatDate(latestSyncRun.started_at)}
                                    </div>
                                    {latestSyncRun.error_message && (
                                        <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                                            {latestSyncRun.error_message}
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex min-h-48 items-center justify-center text-sm text-gray-600">
                                    No sync runs yet.
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-4 w-4 text-gray-600" />
                                Audit Trail
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Event</TableHead>
                                            <TableHead>Tenant</TableHead>
                                            <TableHead>Severity</TableHead>
                                            <TableHead>Time</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentAuditEvents.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="py-8 text-center text-sm text-gray-600">
                                                    No audit events yet.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            recentAuditEvents.map((event) => (
                                                <TableRow key={event.id}>
                                                    <TableCell>
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge variant="outline">{event.category}</Badge>
                                                            <span className="font-medium text-gray-900">{event.event_type}</span>
                                                        </div>
                                                        <div className="mt-1 text-sm text-gray-700">{event.summary}</div>
                                                        {auditDetail(event) && (
                                                            <div className="mt-1 max-w-xl truncate text-xs text-gray-500">{auditDetail(event)}</div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>{event.tenant_name || 'Unmapped tenant'}</TableCell>
                                                    <TableCell>
                                                        <SeverityBadge severity={event.severity} status={event.status} />
                                                    </TableCell>
                                                    <TableCell>{formatDate(event.occurred_at)}</TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ListChecks className="h-4 w-4 text-gray-600" />
                                Sync Items
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Record</TableHead>
                                            <TableHead>Tenant</TableHead>
                                            <TableHead>Action</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Time</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentSyncItems.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="py-8 text-center text-sm text-gray-600">
                                                    No synced records yet.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            recentSyncItems.map((item) => (
                                                <TableRow key={item.id}>
                                                    <TableCell>
                                                        <div className="font-medium text-gray-900">{item.external_type || 'record'}</div>
                                                        <div className="font-mono text-xs text-gray-500">External {item.external_id || 'n/a'}</div>
                                                        {item.local_id && (
                                                            <div className="font-mono text-xs text-gray-500">Local {item.local_type}:{item.local_id}</div>
                                                        )}
                                                        {item.error_message && (
                                                            <div className="mt-1 max-w-72 truncate text-xs text-red-600">{item.error_message}</div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>{item.tenant_name || 'Unmapped tenant'}</TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline">{item.action}</Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge status={item.status} />
                                                    </TableCell>
                                                    <TableCell>{formatDate(item.created_at)}</TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}

interface CredentialFormState {
    tenant_id: string;
    base_url: string;
    api_version: string;
    remote_tenant_id: string;
    status: string;
    scopes: string[];
    access_token: string;
    webhook_secret: string;
    clear_access_token: boolean;
    clear_webhook_secret: boolean;
}

function CreateConnectionForm({
    tenants,
    scopes,
    defaultScopes,
    errors,
}: {
    tenants: TenantOption[];
    scopes: string[];
    defaultScopes: string[];
    errors: Record<string, string>;
}) {
    const [processing, setProcessing] = useState(false);
    const [form, setForm] = useState<CredentialFormState>(() => ({
        tenant_id: tenants[0]?.id ? String(tenants[0].id) : '',
        base_url: '',
        api_version: 'v1',
        remote_tenant_id: '',
        status: 'inactive',
        scopes: defaultScopes,
        access_token: '',
        webhook_secret: '',
        clear_access_token: false,
        clear_webhook_secret: false,
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        router.post('/admin/integrations', credentialPayload(form), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onSuccess: () =>
                setForm((current) => ({
                    ...current,
                    tenant_id: tenants[0]?.id ? String(tenants[0].id) : '',
                    access_token: '',
                    webhook_secret: '',
                })),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <form onSubmit={submit} className="rounded-md border p-4">
            <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="font-medium text-gray-900">New Edu-admin Connection</div>
                    <div className="text-sm text-gray-600">{tenants.length} tenant{tenants.length === 1 ? '' : 's'} available</div>
                </div>
                <Button type="submit" disabled={processing || tenants.length === 0}>
                    <PlusCircle className="h-4 w-4" />
                    {processing ? 'Saving' : 'Create'}
                </Button>
            </div>

            <div className="grid gap-3 lg:grid-cols-4">
                <Field label="Tenant" error={errors.tenant_id}>
                    <select
                        className={selectClassName}
                        value={form.tenant_id}
                        onChange={(event) => setForm({ ...form, tenant_id: event.target.value })}
                        disabled={tenants.length === 0}
                    >
                        {tenants.length === 0 ? (
                            <option value="">No tenants</option>
                        ) : (
                            tenants.map((tenant) => (
                                <option key={tenant.id} value={tenant.id}>
                                    {tenant.name}
                                </option>
                            ))
                        )}
                    </select>
                </Field>
                <TextField label="Base URL" value={form.base_url} error={errors.base_url} onChange={(base_url) => setForm({ ...form, base_url })} />
                <TextField label="API Version" value={form.api_version} error={errors.api_version} onChange={(api_version) => setForm({ ...form, api_version })} />
                <TextField
                    label="Remote ID"
                    value={form.remote_tenant_id}
                    error={errors.remote_tenant_id}
                    onChange={(remote_tenant_id) => setForm({ ...form, remote_tenant_id })}
                />
                <StatusField value={form.status} error={errors.status} onChange={(status) => setForm({ ...form, status })} />
                <TextField
                    label="Access Token"
                    type="password"
                    value={form.access_token}
                    error={errors.access_token}
                    onChange={(access_token) => setForm({ ...form, access_token })}
                />
                <TextField
                    label="Webhook Secret"
                    type="password"
                    value={form.webhook_secret}
                    error={errors.webhook_secret}
                    onChange={(webhook_secret) => setForm({ ...form, webhook_secret })}
                />
                <ScopeField value={form.scopes} scopes={scopes} error={errors.scopes} onChange={(nextScopes) => setForm({ ...form, scopes: nextScopes })} />
            </div>
        </form>
    );
}

function ConnectionCredentialForm({
    connection,
    scopes,
    defaultScopes,
    errors,
}: {
    connection: Connection;
    scopes: string[];
    defaultScopes: string[];
    errors: Record<string, string>;
}) {
    const [processing, setProcessing] = useState(false);
    const [form, setForm] = useState<CredentialFormState>(() => ({
        tenant_id: connection.tenant?.id ? String(connection.tenant.id) : '',
        base_url: connection.base_url || '',
        api_version: connection.api_version || 'v1',
        remote_tenant_id: connection.remote_tenant_id || '',
        status: connection.status || 'inactive',
        scopes: connection.scopes?.length ? connection.scopes : defaultScopes,
        access_token: '',
        webhook_secret: '',
        clear_access_token: false,
        clear_webhook_secret: false,
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        router.patch(`/admin/integrations/${connection.id}/credentials`, credentialPayload(form), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onSuccess: () =>
                setForm((current) => ({
                    ...current,
                    access_token: '',
                    webhook_secret: '',
                    clear_access_token: false,
                    clear_webhook_secret: false,
                })),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <form onSubmit={submit} className="rounded-md border p-4">
            <div className="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div>
                    <div className="font-medium text-gray-900">{connection.tenant?.name || `Connection ${connection.id}`}</div>
                    <div className="text-sm text-gray-600">{connection.base_url || 'Base URL not set'}</div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <CredentialBadge saved={connection.has_access_token} label="Token" />
                    <CredentialBadge saved={connection.has_webhook_secret} label="Secret" />
                    <Button type="submit" disabled={processing}>
                        <Save className="h-4 w-4" />
                        {processing ? 'Saving' : 'Save'}
                    </Button>
                </div>
            </div>

            <div className="grid gap-3 lg:grid-cols-4">
                <TextField label="Base URL" value={form.base_url} error={errors.base_url} onChange={(base_url) => setForm({ ...form, base_url })} />
                <TextField label="API Version" value={form.api_version} error={errors.api_version} onChange={(api_version) => setForm({ ...form, api_version })} />
                <TextField
                    label="Remote ID"
                    value={form.remote_tenant_id}
                    error={errors.remote_tenant_id}
                    onChange={(remote_tenant_id) => setForm({ ...form, remote_tenant_id })}
                />
                <StatusField value={form.status} error={errors.status} onChange={(status) => setForm({ ...form, status })} />
                <TextField
                    label="New Access Token"
                    type="password"
                    value={form.access_token}
                    error={errors.access_token}
                    disabled={form.clear_access_token}
                    onChange={(access_token) => setForm({ ...form, access_token })}
                />
                <TextField
                    label="New Webhook Secret"
                    type="password"
                    value={form.webhook_secret}
                    error={errors.webhook_secret}
                    disabled={form.clear_webhook_secret}
                    onChange={(webhook_secret) => setForm({ ...form, webhook_secret })}
                />
                <ScopeField value={form.scopes} scopes={scopes} error={errors.scopes} onChange={(nextScopes) => setForm({ ...form, scopes: nextScopes })} />
                <Field label="Clear">
                    <div className="flex h-9 items-center gap-4 rounded-md border px-3">
                        <Checkbox
                            id={`clear-token-${connection.id}`}
                            checked={form.clear_access_token}
                            onCheckedChange={(checked) => setForm({ ...form, clear_access_token: checked === true, access_token: checked === true ? '' : form.access_token })}
                        />
                        <Label htmlFor={`clear-token-${connection.id}`} className="text-xs text-gray-700">
                            Token
                        </Label>
                        <Checkbox
                            id={`clear-secret-${connection.id}`}
                            checked={form.clear_webhook_secret}
                            onCheckedChange={(checked) =>
                                setForm({ ...form, clear_webhook_secret: checked === true, webhook_secret: checked === true ? '' : form.webhook_secret })
                            }
                        />
                        <Label htmlFor={`clear-secret-${connection.id}`} className="text-xs text-gray-700">
                            Secret
                        </Label>
                    </div>
                </Field>
            </div>
        </form>
    );
}

function TextField({
    label,
    value,
    onChange,
    error,
    type = 'text',
    disabled = false,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    type?: string;
    disabled?: boolean;
}) {
    return (
        <Field label={label} error={error}>
            <Input type={type} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} />
        </Field>
    );
}

function StatusField({ value, onChange, error }: { value: string; onChange: (value: string) => void; error?: string }) {
    return (
        <Field label="Status" error={error}>
            <select className={selectClassName} value={value} onChange={(event) => onChange(event.target.value)}>
                {['inactive', 'active', 'paused', 'error'].map((status) => (
                    <option key={status} value={status}>
                        {status}
                    </option>
                ))}
            </select>
        </Field>
    );
}

function ScopeField({
    value,
    scopes,
    onChange,
    error,
}: {
    value: string[];
    scopes: string[];
    onChange: (value: string[]) => void;
    error?: string;
}) {
    const toggleScope = (scope: string, checked: boolean) => {
        onChange(checked ? [...value, scope] : value.filter((item) => item !== scope));
    };

    return (
        <Field label="Scopes" error={error}>
            <div className="flex min-h-9 flex-wrap items-center gap-3 rounded-md border px-3 py-2">
                {scopes.map((scope) => (
                    <label key={scope} className="flex items-center gap-2 text-xs text-gray-700">
                        <Checkbox checked={value.includes(scope)} onCheckedChange={(checked) => toggleScope(scope, checked === true)} />
                        <span>{scope}</span>
                    </label>
                ))}
            </div>
        </Field>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            {children}
            {error && <div className="text-xs text-red-600">{error}</div>}
        </div>
    );
}

function CredentialBadge({ saved, label }: { saved: boolean; label: string }) {
    return <Badge variant={saved ? 'success' : 'destructive'}>{saved ? `${label} saved` : `${label} missing`}</Badge>;
}

function credentialPayload(form: CredentialFormState) {
    return {
        tenant_id: form.tenant_id ? Number(form.tenant_id) : null,
        base_url: form.base_url || null,
        api_version: form.api_version || 'v1',
        remote_tenant_id: form.remote_tenant_id || null,
        status: form.status,
        scopes: form.scopes,
        access_token: form.access_token || null,
        webhook_secret: form.webhook_secret || null,
        clear_access_token: form.clear_access_token,
        clear_webhook_secret: form.clear_webhook_secret,
    };
}

const selectClassName =
    'border-input focus-visible:border-ring focus-visible:ring-ring/50 flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50';

function MetricCard({
    title,
    value,
    detail,
    icon: Icon,
    tone = 'default',
}: {
    title: string;
    value: string;
    detail: string;
    icon: React.ElementType;
    tone?: 'default' | 'success' | 'danger';
}) {
    const iconClass = tone === 'danger' ? 'text-red-600' : tone === 'success' ? 'text-green-600' : 'text-gray-600';

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                <Icon className={`h-4 w-4 ${iconClass}`} />
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold text-gray-900">{value}</div>
                <p className="text-xs text-gray-600">{detail}</p>
            </CardContent>
        </Card>
    );
}

function SyncStat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md border px-3 py-2">
            <div className="text-xs text-gray-500">{label}</div>
            <div className="font-mono text-base font-semibold text-gray-900">{value}</div>
        </div>
    );
}

function StatusBadge({ status }: { status: string }) {
    if (['active', 'completed', 'sent', 'synced'].includes(status)) {
        return (
            <Badge variant="success">
                <CheckCircle className="h-3 w-3" />
                {status}
            </Badge>
        );
    }

    if (['failed', 'error'].includes(status)) {
        return (
            <Badge variant="destructive">
                <XCircle className="h-3 w-3" />
                {status}
            </Badge>
        );
    }

    if (['pending', 'running', 'queued'].includes(status)) {
        return (
            <Badge variant="secondary">
                <Clock className="h-3 w-3" />
                {status}
            </Badge>
        );
    }

    return <Badge variant="outline">{status}</Badge>;
}

function SeverityBadge({ severity, status }: { severity: string; status?: string | null }) {
    if (severity === 'error') {
        return (
            <Badge variant="destructive">
                <XCircle className="h-3 w-3" />
                {status || severity}
            </Badge>
        );
    }

    if (severity === 'warning') {
        return (
            <Badge variant="secondary">
                <AlertCircle className="h-3 w-3" />
                {status || severity}
            </Badge>
        );
    }

    return (
        <Badge variant="success">
            <CheckCircle className="h-3 w-3" />
            {status || severity}
        </Badge>
    );
}

function auditDetail(event: AuditEvent) {
    const metadata = event.metadata || {};

    if (Array.isArray(metadata.changed_fields) && metadata.changed_fields.length > 0) {
        return `Changed ${metadata.changed_fields.join(', ')}`;
    }

    const counters = ['records_read', 'records_created', 'records_updated', 'records_failed']
        .map((key) => [key.replace('records_', ''), metadata[key]])
        .filter(([, value]) => typeof value === 'number')
        .map(([label, value]) => `${label}: ${value}`);

    if (counters.length > 0) {
        return counters.join(' · ');
    }

    if (typeof metadata.resource === 'string') {
        return `Resource ${metadata.resource}`;
    }

    return '';
}

function canRun(connection: Connection) {
    return connection.mode === 'connected' && connection.status === 'active';
}

function formatDate(value?: string | null) {
    if (!value) {
        return 'Never';
    }

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}
