import type { LucideIcon } from 'lucide-react';
import { formatNumber } from '../lib/format';

type MetricTileProps = {
  label: string;
  value: number;
  helper?: string;
  icon: LucideIcon;
};

export default function MetricTile({ label, value, helper, icon: Icon }: MetricTileProps) {
  return (
    <div className="metric-tile">
      <div className="metric-icon" aria-hidden="true">
        <Icon size={20} />
      </div>
      <div>
        <p className="metric-label">{label}</p>
        <p className="metric-value">{formatNumber(value)}</p>
        {helper && <p className="metric-helper">{helper}</p>}
      </div>
    </div>
  );
}
