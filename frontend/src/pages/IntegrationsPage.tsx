import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Cable, Loader2, Play, Plus, RefreshCw } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import EmptyState from '../components/EmptyState';
import LoadingState from '../components/LoadingState';
import StatusBadge from '../components/StatusBadge';
import { adminApi, errorMessage } from '../lib/api';
import { formatDate, titleCase } from '../lib/format';
import { useAuthStore } from '../store/authStore';

const defaultScopes = 'foundation:read,messages:read,attendance:write';

export default function IntegrationsPage() {
  const queryClient = useQueryClient();
  const admin = useAuthStore((state) => state.admin);
  const dashboardQuery = useQuery({ queryKey: ['dashboard'], queryFn: adminApi.dashboard });
  const connectionsQuery = useQuery({ queryKey: ['connections'], queryFn: adminApi.connections });
  const tenants = dashboardQuery.data?.organization.tenants ?? [];
  const [tenantId, setTenantId] = useState('');
  const [baseUrl, setBaseUrl] = useState('https://eduadmin-api.ghvcameroon.com');
  const [remoteTenantId, setRemoteTenantId] = useState('');
  const [accessToken, setAccessToken] = useState('');
  const [webhookSecret, setWebhookSecret] = useState('');
  const [scopes, setScopes] = useState(defaultScopes);

  const selectedTenantId = tenantId
    ? Number(tenantId)
    : admin?.role !== 'super_admin' && tenants.length === 1
      ? tenants[0].id
      : undefined;

  const createMutation = useMutation({
    mutationFn: adminApi.createConnection,
    onSuccess: () => {
      setAccessToken('');
      setWebhookSecret('');
      void queryClient.invalidateQueries({ queryKey: ['connections'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });

  const syncMutation = useMutation({
    mutationFn: ({ id, type }: { id: number; type: 'initial' | 'incremental' }) =>
      type === 'initial' ? adminApi.syncInitial(id) : adminApi.syncIncremental(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['connections'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });

  function handleCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    createMutation.mutate({
      tenant_id: selectedTenantId,
      mode: 'connected',
      status: 'active',
      base_url: baseUrl,
      api_version: 'v1',
      remote_tenant_id: remoteTenantId || undefined,
      scopes: scopes.split(',').map((scope) => scope.trim()).filter(Boolean),
      access_token: accessToken || undefined,
      webhook_secret: webhookSecret || undefined,
    });
  }

  if (dashboardQuery.isLoading || connectionsQuery.isLoading) {
    return <LoadingState label="Loading integration workspace" />;
  }

  const connections = connectionsQuery.data ?? [];

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Edu-admin bridge</p>
          <h1>Integrations</h1>
          <p>Connect this Edu-connect backend to Edu-admin and watch sync health from the same panel.</p>
        </div>
      </section>

      <section className="two-column align-start">
        <form className="panel form-panel" onSubmit={handleCreate}>
          <div className="panel-header">
            <div>
              <h2>New Edu-admin Connection</h2>
              <p>Use credentials issued from Edu-admin. Edu-connect can create the tenant automatically.</p>
            </div>
            <Plus size={20} />
          </div>

          {(createMutation.isError || dashboardQuery.isError) && (
            <div className="form-alert">
              {errorMessage(createMutation.error ?? dashboardQuery.error, 'Unable to save the connection.')}
            </div>
          )}

          {admin?.role === 'super_admin' && (
            <label className="field">
              <span>Tenant</span>
              <select value={tenantId} onChange={(event) => setTenantId(event.target.value)}>
                <option value="">Create from Edu-admin complex</option>
                {tenants.map((tenant) => (
                  <option value={tenant.id} key={tenant.id}>
                    {tenant.name}
                  </option>
                ))}
              </select>
            </label>
          )}

          <label className="field">
            <span>Edu-admin API root URL</span>
            <input value={baseUrl} onChange={(event) => setBaseUrl(event.target.value)} type="url" required />
          </label>

          <label className="field">
            <span>Remote tenant ID</span>
            <input value={remoteTenantId} onChange={(event) => setRemoteTenantId(event.target.value)} placeholder="Edu-admin academic complex id" />
          </label>

          <label className="field">
            <span>Access token</span>
            <input value={accessToken} onChange={(event) => setAccessToken(event.target.value)} type="password" placeholder="Paste issued token" />
          </label>

          <label className="field">
            <span>Webhook secret</span>
            <input value={webhookSecret} onChange={(event) => setWebhookSecret(event.target.value)} type="password" placeholder="Optional signing secret" />
          </label>

          <label className="field">
            <span>Scopes</span>
            <input value={scopes} onChange={(event) => setScopes(event.target.value)} />
          </label>

          <button className="primary-button" type="submit" disabled={createMutation.isPending}>
            {createMutation.isPending ? <Loader2 size={18} className="spin" /> : <Plus size={18} />}
            {selectedTenantId ? 'Save connection' : 'Link complex'}
          </button>
        </form>

        <div className="panel">
          <div className="panel-header">
            <div>
              <h2>Connection Status</h2>
              <p>{connections.length} configured connection{connections.length === 1 ? '' : 's'}.</p>
            </div>
          </div>

          <div className="connection-list">
            {connections.length === 0 ? (
              <EmptyState icon={Cable} title="No connections yet" message="Create a connection once Edu-admin has issued credentials." />
            ) : (
              connections.map((connection) => (
                <article className="connection-item" key={connection.id}>
                  <div className="connection-main">
                    <div>
                      <h3>{connection.tenant?.name ?? `Tenant ${connection.tenant_id}`}</h3>
                      <p>{connection.base_url ?? 'No connector URL set'}</p>
                    </div>
                    <StatusBadge status={connection.status} />
                  </div>

                  <div className="connection-meta">
                    <span>{titleCase(connection.mode)}</span>
                    <span>{connection.mappings_count} mappings</span>
                    <span>{connection.sync_runs_count} runs</span>
                    <span>Last sync: {formatDate(connection.last_successful_sync_at)}</span>
                  </div>

                  {connection.last_error && <div className="form-alert">{connection.last_error}</div>}

                  <div className="button-row">
                    <button
                      className="secondary-button"
                      type="button"
                      onClick={() => syncMutation.mutate({ id: connection.id, type: 'initial' })}
                      disabled={syncMutation.isPending || connection.status !== 'active'}
                    >
                      <Play size={16} />
                      Initial sync
                    </button>
                    <button
                      className="secondary-button"
                      type="button"
                      onClick={() => syncMutation.mutate({ id: connection.id, type: 'incremental' })}
                      disabled={syncMutation.isPending || connection.status !== 'active'}
                    >
                      <RefreshCw size={16} />
                      Incremental
                    </button>
                  </div>
                </article>
              ))
            )}
          </div>
        </div>
      </section>
    </div>
  );
}
