import { useQuery } from '@tanstack/react-query';
import { Bell, Radio } from 'lucide-react';
import EmptyState from '../components/EmptyState';
import LoadingState from '../components/LoadingState';
import MetricTile from '../components/MetricTile';
import StatusBadge from '../components/StatusBadge';
import { adminApi } from '../lib/api';
import { formatDate } from '../lib/format';

export default function NotificationsPage() {
  const dashboardQuery = useQuery({
    queryKey: ['dashboard'],
    queryFn: adminApi.dashboard,
  });

  if (dashboardQuery.isLoading) {
    return <LoadingState label="Loading notifications" />;
  }

  const dashboard = dashboardQuery.data;
  const realtime = dashboard?.health.realtime;
  const realtimeReady = realtime?.ready ?? dashboard?.health.realtime_enabled ?? false;
  const realtimeStatus = realtime?.status ?? (dashboard?.health.realtime_enabled ? 'enabled' : 'disabled');

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Mobile delivery</p>
          <h1>Notifications</h1>
          <p>Push and realtime readiness for parent alerts, channels, and attendance events.</p>
        </div>
      </section>

      <section className="metrics-grid narrow">
        <MetricTile icon={Bell} label="Queued pushes" value={dashboard?.summary.notifications_queued ?? 0} helper={dashboard?.health.push_provider ?? 'provider pending'} />
        <MetricTile icon={Radio} label="Realtime" value={realtimeReady ? 1 : 0} helper={realtimeStatus} />
      </section>

      {realtime && (realtime.problems.length > 0 || realtime.warnings.length > 0) && (
        <section className="panel">
          <div className="panel-header">
            <div>
              <h2>Realtime Readiness</h2>
              <p>{realtime.host || 'No websocket host configured'} - {realtime.broadcast_connection}</p>
            </div>
            <StatusBadge status={realtime.status} />
          </div>
          <div className="health-notes">
            {[...realtime.problems, ...realtime.warnings].map((item) => (
              <p key={item}>{item}</p>
            ))}
          </div>
        </section>
      )}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Recent Conversation Triggers</h2>
            <p>Useful signals for notification delivery and realtime fan-out.</p>
          </div>
        </div>

        <div className="list-stack">
          {(dashboard?.recent.conversation_messages ?? []).length === 0 ? (
            <EmptyState icon={Bell} title="No recent triggers" message="Conversation and channel activity will appear here." />
          ) : (
            dashboard?.recent.conversation_messages.map((message) => (
              <div className="list-row" key={message.id}>
                <div>
                  <strong>{message.thread?.title ?? 'Conversation'}</strong>
                  <span>{message.sender_display_name}: {message.body}</span>
                </div>
                <div className="row-meta">
                  <StatusBadge status={message.sender_type} />
                  <small>{formatDate(message.sent_at)}</small>
                </div>
              </div>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
