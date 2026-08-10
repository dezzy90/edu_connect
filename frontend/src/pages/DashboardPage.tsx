import { useQuery } from '@tanstack/react-query';
import {
  Activity,
  AlertTriangle,
  Bell,
  Cable,
  CheckCircle2,
  GraduationCap,
  MessageCircle,
  School,
  Smartphone,
  Users,
} from 'lucide-react';
import EmptyState from '../components/EmptyState';
import LoadingState from '../components/LoadingState';
import MetricTile from '../components/MetricTile';
import StatusBadge from '../components/StatusBadge';
import { adminApi } from '../lib/api';
import { formatDate, formatNumber, titleCase } from '../lib/format';

export default function DashboardPage() {
  const dashboardQuery = useQuery({
    queryKey: ['dashboard'],
    queryFn: adminApi.dashboard,
  });

  if (dashboardQuery.isLoading) {
    return <LoadingState label="Loading Edu-connect dashboard" />;
  }

  if (dashboardQuery.isError || !dashboardQuery.data) {
    return <EmptyState icon={Activity} title="Dashboard unavailable" message="The backend did not return the operations dashboard." />;
  }

  const { summary, health, recent } = dashboardQuery.data;
  const realtime = health.realtime;
  const realtimeReady = realtime?.ready ?? health.realtime_enabled;
  const realtimeStatus = realtime?.status ?? (health.realtime_enabled ? 'enabled' : 'disabled');

  const metrics = [
    { label: 'Schools', value: summary.schools, icon: School, helper: `${formatNumber(summary.tenants)} tenants` },
    { label: 'Students', value: summary.students, icon: GraduationCap, helper: `${formatNumber(summary.classes)} classes` },
    { label: 'Parents', value: summary.parents, icon: Users, helper: 'linked accounts' },
    { label: 'Devices', value: summary.active_devices, icon: Smartphone, helper: `${formatNumber(health.online_devices)} online now` },
    { label: 'Attendance Today', value: summary.attendance_today, icon: CheckCircle2, helper: 'events received' },
    { label: 'Open Chats', value: summary.open_conversations, icon: MessageCircle, helper: 'groups, channels, direct' },
    { label: 'Queued Pushes', value: summary.notifications_queued, icon: Bell, helper: health.push_provider ?? 'provider pending' },
    { label: 'Realtime', value: realtimeReady ? 1 : 0, icon: Cable, helper: realtimeStatus },
    { label: 'Active Integrations', value: health.active_connections, icon: Cable, helper: `${formatNumber(health.pending_outbox)} outbox pending` },
  ];

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Edu-connect operations</p>
          <h1>Dashboard</h1>
          <p>Live school connectivity, parent communication, device activity, and Edu-admin sync health.</p>
        </div>
        <div className="header-status">
          <StatusBadge status={health.mode} />
          <span>API {health.api_version}</span>
        </div>
      </section>

      <section className="metrics-grid">
        {metrics.map((metric) => (
          <MetricTile key={metric.label} {...metric} />
        ))}
      </section>

      <section className="two-column">
        <div className="panel">
          <div className="panel-header">
            <div>
              <h2>System Health</h2>
              <p>Backend readiness for mobile and web clients.</p>
            </div>
            <StatusBadge status={health.failed_sync_runs || health.failed_outbox || (realtime?.enabled && !realtime.ready) ? 'attention' : 'active'} />
          </div>
          <div className="health-grid">
            <div>
              <span>Realtime</span>
              <strong>{titleCase(realtimeStatus)}</strong>
            </div>
            <div>
              <span>Realtime host</span>
              <strong>{realtime?.host || 'Not configured'}</strong>
            </div>
            <div>
              <span>Broadcast</span>
              <strong>{realtime?.broadcast_connection ?? 'Unknown'}</strong>
            </div>
            <div>
              <span>Push provider</span>
              <strong>{health.push_provider ?? 'Not configured'}</strong>
            </div>
            <div>
              <span>Failed syncs</span>
              <strong>{formatNumber(health.failed_sync_runs)}</strong>
            </div>
            <div>
              <span>Failed outbox</span>
              <strong>{formatNumber(health.failed_outbox)}</strong>
            </div>
          </div>
          {realtime && (realtime.problems.length > 0 || realtime.warnings.length > 0) && (
            <div className="health-notes">
              {[...realtime.problems, ...realtime.warnings].map((item) => (
                <p key={item}>
                  <AlertTriangle size={14} />
                  <span>{item}</span>
                </p>
              ))}
            </div>
          )}
          <p className="muted-line">Server time: {formatDate(health.server_time)}</p>
        </div>

        <div className="panel">
          <div className="panel-header">
            <div>
              <h2>Recent Attendance</h2>
              <p>Latest device events flowing into Edu-connect.</p>
            </div>
          </div>
          <div className="list-stack">
            {recent.attendance_events.length === 0 ? (
              <EmptyState icon={Activity} title="No attendance events" message="Device events will appear here as they arrive." />
            ) : (
              recent.attendance_events.map((event) => (
                <div className="list-row" key={event.id}>
                  <div>
                    <strong>{event.student?.full_name ?? 'Unknown student'}</strong>
                    <span>{event.school?.name ?? 'No school'} · {event.device?.name ?? 'No device'}</span>
                  </div>
                  <div className="row-meta">
                    <StatusBadge status={event.edu_admin_sync_status} />
                    <small>{formatDate(event.event_time)}</small>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </section>

      <section className="three-column">
        <RecentPanel
          title="Sync Runs"
          items={recent.sync_runs.map((run) => ({
            key: run.id,
            title: `${titleCase(run.sync_type)} ${titleCase(run.direction)}`,
            meta: run.tenant?.name ?? 'No tenant',
            status: run.status,
            time: run.started_at,
          }))}
        />
        <RecentPanel
          title="Outbox"
          items={recent.outbox_events.map((event) => ({
            key: event.id,
            title: event.event_type,
            meta: event.tenant?.name ?? event.event_key,
            status: event.status,
            time: event.available_at,
          }))}
        />
        <RecentPanel
          title="Conversations"
          items={recent.conversation_messages.map((message) => ({
            key: message.id,
            title: message.sender_display_name,
            meta: message.thread?.title ?? message.body ?? 'Message',
            status: message.sender_type,
            time: message.sent_at,
          }))}
        />
      </section>
    </div>
  );
}

function RecentPanel({
  title,
  items,
}: {
  title: string;
  items: { key: number; title: string; meta: string; status: string; time: string | null }[];
}) {
  return (
    <div className="panel compact-panel">
      <div className="panel-header">
        <h2>{title}</h2>
      </div>
      <div className="list-stack">
        {items.length === 0 ? (
          <p className="muted-line">No recent activity.</p>
        ) : (
          items.map((item) => (
            <div className="list-row tight" key={item.key}>
              <div>
                <strong>{item.title}</strong>
                <span>{item.meta}</span>
              </div>
              <div className="row-meta">
                <StatusBadge status={item.status} />
                <small>{formatDate(item.time)}</small>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
