import { useMutation } from '@tanstack/react-query';
import { LogOut, ShieldCheck, UserRound } from 'lucide-react';
import { useNavigate } from 'react-router';
import StatusBadge from '../components/StatusBadge';
import { authApi } from '../lib/api';
import { formatDate } from '../lib/format';
import { useAuthStore } from '../store/authStore';

export default function SettingsPage() {
  const navigate = useNavigate();
  const admin = useAuthStore((state) => state.admin);
  const clearAuth = useAuthStore((state) => state.clearAuth);

  const logoutMutation = useMutation({
    mutationFn: authApi.logout,
    onSettled: () => {
      clearAuth();
      navigate('/login', { replace: true });
    },
  });

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Account</p>
          <h1>Settings</h1>
          <p>Current administrator identity and frontend API environment.</p>
        </div>
      </section>

      <section className="two-column align-start">
        <div className="panel">
          <div className="panel-header">
            <div>
              <h2>Profile</h2>
              <p>Authenticated through Edu-connect admin API tokens.</p>
            </div>
            <UserRound size={20} />
          </div>
          <div className="settings-list">
            <div>
              <span>Name</span>
              <strong>{admin?.name ?? 'Admin'}</strong>
            </div>
            <div>
              <span>Email</span>
              <strong>{admin?.email ?? 'Not available'}</strong>
            </div>
            <div>
              <span>Role</span>
              <StatusBadge status={admin?.role ?? 'admin'} />
            </div>
            <div>
              <span>Last login</span>
              <strong>{formatDate(admin?.last_login_at)}</strong>
            </div>
          </div>
        </div>

        <div className="panel">
          <div className="panel-header">
            <div>
              <h2>Runtime</h2>
              <p>Values used by this standalone frontend.</p>
            </div>
            <ShieldCheck size={20} />
          </div>
          <div className="settings-list">
            <div>
              <span>API base URL</span>
              <strong>{import.meta.env.VITE_API_URL || 'http://localhost:8000/api'}</strong>
            </div>
            <div>
              <span>Frontend mode</span>
              <StatusBadge status="standalone" />
            </div>
          </div>
          <button className="secondary-button full-width" type="button" onClick={() => logoutMutation.mutate()} disabled={logoutMutation.isPending}>
            <LogOut size={18} />
            Log out
          </button>
        </div>
      </section>
    </div>
  );
}
