import axios from 'axios';
import type {
  ApiEnvelope,
  AuthPayload,
  ConversationMessage,
  ConversationThread,
  DashboardPayload,
  FoundationPayload,
  IntegrationConnection,
} from '../types';
import { useAuthStore } from '../store/authStore';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

export const api = axios.create({
  baseURL: API_URL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

api.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token || localStorage.getItem('edu_connect_admin_token');

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      useAuthStore.getState().clearAuth();
      if (!window.location.pathname.includes('/login')) {
        window.location.href = '/login';
      }
    }

    return Promise.reject(error);
  },
);

function unwrap<T>(payload: ApiEnvelope<T>): T {
  return payload.data;
}

export function errorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    const firstValidationError = data?.errors ? Object.values(data.errors).flat()[0] : null;
    return firstValidationError || data?.message || fallback;
  }

  return fallback;
}

export const authApi = {
  async login(payload: { email: string; password: string; remember?: boolean; device_name?: string }) {
    const response = await api.post<ApiEnvelope<AuthPayload>>('/admin/v2/auth/login', payload);
    return unwrap(response.data);
  },
  async me() {
    const response = await api.get<ApiEnvelope<{ admin: AuthPayload['admin'] }>>('/admin/v2/auth/me');
    return unwrap(response.data).admin;
  },
  async logout() {
    await api.post('/admin/v2/auth/logout');
  },
};

export const adminApi = {
  async foundation() {
    const response = await api.get<ApiEnvelope<FoundationPayload>>('/admin/v2/foundation');
    return unwrap(response.data);
  },
  async dashboard() {
    const response = await api.get<ApiEnvelope<DashboardPayload>>('/admin/v2/dashboard');
    return unwrap(response.data);
  },
  async connections() {
    const response = await api.get<ApiEnvelope<IntegrationConnection[]>>('/admin/v2/integration-connections');
    return unwrap(response.data);
  },
  async createConnection(payload: {
    tenant_id?: number;
    mode?: string;
    status?: string;
    base_url?: string;
    api_version?: string;
    remote_tenant_id?: string;
    scopes?: string[];
    access_token?: string;
    webhook_secret?: string;
  }) {
    const response = await api.post<ApiEnvelope<IntegrationConnection>>('/admin/v2/integration-connections', payload);
    return unwrap(response.data);
  },
  async updateConnection(id: number, payload: Partial<IntegrationConnection> & {
    access_token?: string;
    webhook_secret?: string;
    clear_access_token?: boolean;
    clear_webhook_secret?: boolean;
  }) {
    const response = await api.patch<ApiEnvelope<IntegrationConnection>>(`/admin/v2/integration-connections/${id}`, payload);
    return unwrap(response.data);
  },
  async syncInitial(id: number) {
    const response = await api.post<ApiEnvelope<{ connection: IntegrationConnection; sync_run: unknown }>>(
      `/admin/v2/integration-connections/${id}/sync-initial`,
      {},
    );
    return unwrap(response.data);
  },
  async syncIncremental(id: number) {
    const response = await api.post<ApiEnvelope<{ connection: IntegrationConnection; sync_run: unknown }>>(
      `/admin/v2/integration-connections/${id}/sync-incremental`,
      {},
    );
    return unwrap(response.data);
  },
  async conversations(type?: string) {
    const response = await api.get<ApiEnvelope<{ items: ConversationThread[] }>>('/admin/v2/conversations', {
      params: type ? { type } : undefined,
    });
    return unwrap(response.data).items;
  },
  async conversation(id: number) {
    const response = await api.get<ApiEnvelope<{ thread: ConversationThread; messages: ConversationMessage[] }>>(
      `/admin/v2/conversations/${id}`,
    );
    return unwrap(response.data);
  },
  async postMessage(id: number, body: string) {
    const response = await api.post<ApiEnvelope<{ thread: ConversationThread; message: ConversationMessage }>>(
      `/admin/v2/conversations/${id}/messages`,
      { body },
    );
    return unwrap(response.data);
  },
  async updateConversationStatus(id: number, status: 'open' | 'closed' | 'archived') {
    const response = await api.patch<ApiEnvelope<ConversationThread>>(`/admin/v2/conversations/${id}/status`, { status });
    return unwrap(response.data);
  },
};
