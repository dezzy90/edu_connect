import { Loader2 } from 'lucide-react';

export default function LoadingState({ label = 'Loading workspace' }: { label?: string }) {
  return (
    <div className="state-panel">
      <Loader2 size={22} className="spin" />
      <span>{label}</span>
    </div>
  );
}
