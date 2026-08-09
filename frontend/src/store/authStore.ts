import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { AdminUser } from '../types';

type AuthState = {
  admin: AdminUser | null;
  token: string | null;
  expiresAt: string | null;
  isAuthenticated: boolean;
  setAuth: (admin: AdminUser, token: string, expiresAt: string) => void;
  setAdmin: (admin: AdminUser) => void;
  clearAuth: () => void;
};

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      admin: null,
      token: null,
      expiresAt: null,
      isAuthenticated: false,
      setAuth: (admin, token, expiresAt) => {
        localStorage.setItem('edu_connect_admin_token', token);
        set({ admin, token, expiresAt, isAuthenticated: true });
      },
      setAdmin: (admin) => set({ admin, isAuthenticated: true }),
      clearAuth: () => {
        localStorage.removeItem('edu_connect_admin_token');
        set({ admin: null, token: null, expiresAt: null, isAuthenticated: false });
      },
    }),
    {
      name: 'edu-connect-admin-auth',
      partialize: (state) => ({
        admin: state.admin,
        token: state.token,
        expiresAt: state.expiresAt,
        isAuthenticated: state.isAuthenticated,
      }),
    },
  ),
);
