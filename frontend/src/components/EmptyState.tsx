import type { LucideIcon } from 'lucide-react';

type EmptyStateProps = {
  icon: LucideIcon;
  title: string;
  message: string;
};

export default function EmptyState({ icon: Icon, title, message }: EmptyStateProps) {
  return (
    <div className="empty-state">
      <Icon size={26} />
      <h3>{title}</h3>
      <p>{message}</p>
    </div>
  );
}
