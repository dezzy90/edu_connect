export type ApiEnvelope<T> = {
  status: 'success' | 'error';
  message?: string;
  data: T;
};

export type AdminUser = {
  id: number;
  name: string;
  email: string;
  role: 'super_admin' | 'school_admin' | string;
  school_id: number | null;
  phone: string | null;
  avatar: string | null;
  is_active: boolean;
  last_login_at: string | null;
  school?: {
    id: number;
    name: string;
    code?: string | null;
    type?: string | null;
  } | null;
};

export type AuthPayload = {
  token_type: 'Bearer';
  access_token: string;
  expires_at: string;
  admin: AdminUser;
};

export type FoundationPayload = {
  service: string;
  api_version: string;
  mode: string;
  table_prefix: string;
  features: Record<string, boolean>;
  server_time: string;
};

export type TenantSummary = {
  id: number;
  name: string;
  slug: string;
  code: string | null;
  status: string;
  schools_count: number;
};

export type SchoolSummary = {
  id: number;
  tenant_id: number;
  name: string;
  slug: string;
  code: string | null;
  type: string | null;
  status: string;
  source_system: string;
  source_id: string | null;
  students_count: number;
  classes_count: number;
  devices_count: number;
};

export type DashboardPayload = {
  summary: Record<string, number>;
  health: {
    mode: string;
    api_version: string;
    realtime_enabled: boolean;
    push_provider: string | null;
    active_connections: number;
    failed_sync_runs: number;
    pending_outbox: number;
    failed_outbox: number;
    online_devices: number;
    server_time: string;
  };
  organization: {
    tenants: TenantSummary[];
    schools: SchoolSummary[];
  };
  recent: {
    attendance_events: AttendanceEventSummary[];
    sync_runs: SyncRunSummary[];
    audit_events: AuditEventSummary[];
    outbox_events: OutboxEventSummary[];
    conversation_messages: ConversationMessageSummary[];
  };
};

export type AttendanceEventSummary = {
  id: number;
  event_key: string;
  event_type: string;
  event_time: string | null;
  confidence_score: string | number | null;
  processing_status: string;
  edu_admin_sync_status: string;
  student: { id: number; full_name: string; student_number: string } | null;
  school: { id: number; name: string } | null;
  device: { id: number; name: string; device_uid: string } | null;
};

export type SyncRunSummary = {
  id: number;
  connection_id: number;
  sync_type: string;
  direction: string;
  status: string;
  records_read: number;
  records_created: number;
  records_updated: number;
  records_failed: number;
  error_message: string | null;
  started_at: string | null;
  finished_at: string | null;
  tenant: { id: number; name: string } | null;
};

export type AuditEventSummary = {
  id: number;
  category: string;
  event_type: string;
  severity: string;
  status: string;
  summary: string;
  occurred_at: string | null;
  tenant: { id: number; name: string } | null;
};

export type OutboxEventSummary = {
  id: number;
  connection_id: number;
  event_type: string;
  event_key: string;
  status: string;
  attempts: number;
  last_error: string | null;
  available_at: string | null;
  sent_at: string | null;
  tenant: { id: number; name: string } | null;
};

export type ConversationMessageSummary = {
  id: number;
  thread_id: number;
  sender_type: string;
  sender_display_name: string;
  body: string | null;
  sent_at: string | null;
  thread: {
    id: number;
    type: string;
    title: string;
    school: { id: number; name: string } | null;
  } | null;
};

export type IntegrationConnection = {
  id: number;
  tenant_id: number;
  tenant: { id: number; name: string; slug: string; status: string } | null;
  provider: string;
  mode: 'standalone' | 'connected' | string;
  base_url: string | null;
  api_version: string;
  remote_tenant_id: string | null;
  status: 'inactive' | 'active' | 'paused' | 'error' | string;
  scopes: string[] | null;
  feature_flags: Record<string, boolean> | null;
  last_successful_sync_at: string | null;
  last_failed_sync_at: string | null;
  last_error: string | null;
  mappings_count: number;
  sync_runs_count: number;
  created_at: string | null;
  updated_at: string | null;
};

export type ConversationThread = {
  id: number;
  tenant_id: number;
  school_id: number;
  class_id: number | null;
  student_id: number | null;
  type: 'direct' | 'class_group' | 'school_channel' | string;
  title: string;
  status: 'open' | 'closed' | 'archived' | string;
  participants_count: number | null;
  messages_count: number | null;
  last_message_at: string | null;
  realtime_channel: string;
  school: { id: number; name: string; code: string | null } | null;
  class: { id: number; name: string; full_name: string } | null;
  student: { id: number; student_number: string; full_name: string } | null;
  last_message: ConversationMessage | null;
};

export type ConversationMessage = {
  id: number;
  thread_id: number;
  sender_type: string;
  sender_id: number | null;
  sender_display_name: string;
  message_type: string;
  body: string | null;
  status: string;
  sent_at: string | null;
  own_message: boolean;
};
