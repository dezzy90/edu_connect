import { useMutation, useQuery } from '@tanstack/react-query';
import {
  Bell,
  Building2,
  Gauge,
  Link2,
  LogOut,
  Menu,
  MessageSquareText,
  Settings,
  ShieldCheck,
  X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router';
import { adminApi, authApi } from '../lib/api';
import { useAuthStore } from '../store/authStore';

type NavItem = {
  label: string;
  to: string;
  icon: LucideIcon;
};

const navItems: NavItem[] = [
  { label: 'Dashboard', to: '/dashboard', icon: Gauge },
  { label: 'Integrations', to: '/integrations', icon: Link2 },
  { label: 'Conversations', to: '/conversations', icon: MessageSquareText },
  { label: 'Organization', to: '/organization', icon: Building2 },
  { label: 'Notifications', to: '/notifications', icon: Bell },
  { label: 'Settings', to: '/settings', icon: Settings },
];

export default function AppLayout() {
  const navigate = useNavigate();
  const admin = useAuthStore((state) => state.admin);
  const clearAuth = useAuthStore((state) => state.clearAuth);
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const foundationQuery = useQuery({
    queryKey: ['foundation'],
    queryFn: adminApi.foundation,
  });

  const logoutMutation = useMutation({
    mutationFn: authApi.logout,
    onSettled: () => {
      clearAuth();
      navigate('/login', { replace: true });
    },
  });

  return (
    <div className="app-shell">
      <aside className={`sidebar ${sidebarOpen ? 'sidebar-open' : ''}`}>
        <div className="sidebar-brand">
          <div className="brand-mark">
            <ShieldCheck size={24} />
          </div>
          <div>
            <strong>Edu Connect</strong>
            <span>Admin console</span>
          </div>
          <button className="icon-button sidebar-close" type="button" onClick={() => setSidebarOpen(false)} aria-label="Close menu">
            <X size={20} />
          </button>
        </div>

        <nav className="sidebar-nav" aria-label="Main navigation">
          {navItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              onClick={() => setSidebarOpen(false)}
              className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
            >
              <item.icon size={18} />
              <span>{item.label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-footer">
          <p>{foundationQuery.data?.mode ?? 'standalone'} mode</p>
          <span>API {foundationQuery.data?.api_version ?? 'v2'}</span>
        </div>
      </aside>

      <div className="content-shell">
        <header className="topbar">
          <button className="icon-button mobile-menu" type="button" onClick={() => setSidebarOpen(true)} aria-label="Open menu">
            <Menu size={22} />
          </button>

          <div className="topbar-title">
            <span>Operations workspace</span>
            <strong>{admin?.role === 'super_admin' ? 'Platform view' : 'School view'}</strong>
          </div>

          <div className="topbar-actions">
            <div className="user-chip">
              <span>{admin?.name?.slice(0, 1).toUpperCase() ?? 'A'}</span>
              <div>
                <strong>{admin?.name ?? 'Admin'}</strong>
                <small>{admin?.email ?? ''}</small>
              </div>
            </div>
            <button
              className="icon-button"
              type="button"
              onClick={() => logoutMutation.mutate()}
              disabled={logoutMutation.isPending}
              aria-label="Log out"
            >
              <LogOut size={20} />
            </button>
          </div>
        </header>

        <main className="page-shell">
          <Outlet />
        </main>
      </div>

      {sidebarOpen && <button className="scrim" type="button" aria-label="Close menu" onClick={() => setSidebarOpen(false)} />}
    </div>
  );
}
