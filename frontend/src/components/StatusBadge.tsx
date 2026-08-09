import { titleCase } from '../lib/format';

type StatusBadgeProps = {
  status: string | boolean | null | undefined;
};

export default function StatusBadge({ status }: StatusBadgeProps) {
  const normalized = String(status ?? 'unknown').toLowerCase();
  const tone =
    normalized === 'active' || normalized === 'completed' || normalized === 'processed' || normalized === 'sent' || normalized === 'true'
      ? 'success'
      : normalized === 'pending' || normalized === 'queued' || normalized === 'paused' || normalized === 'running'
        ? 'warning'
        : normalized === 'failed' || normalized === 'error' || normalized === 'inactive' || normalized === 'false'
          ? 'danger'
          : 'neutral';

  return <span className={`status-badge status-badge-${tone}`}>{titleCase(String(status ?? 'unknown'))}</span>;
}
