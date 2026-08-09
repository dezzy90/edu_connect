# Edu-connect Standalone Frontend Implementation

## Goal

Edu-connect now follows the same production shape as Edu-admin:

- `rod-api.ghvcameroon.com`: Laravel backend/API, queues, sync, push, realtime, mobile endpoints.
- `rod.ghvcameroon.com`: standalone React/Vite web admin panel.
- Flutter mobile app: consumes the Edu-connect mobile API on `rod-api`.
- Edu-admin: connects to Edu-connect through the connector APIs.

This removes the Laravel/Inertia asset coupling that caused `public/hot`, `/build/assets`, and document-root deployment issues.

## Backend Additions

New admin API routes:

```txt
POST /api/admin/v2/auth/login
GET  /api/admin/v2/auth/me
POST /api/admin/v2/auth/logout
GET  /api/admin/v2/dashboard
```

The login endpoint authenticates `AdminUser` records and issues Sanctum bearer tokens with `admin:*` ability. The dashboard endpoint reads from the v2 `ec_` tables and scopes school admins by mapping their legacy `admin_users.school_id` to `ec_schools.source_system=legacy` and `ec_schools.source_id`.

Because the standalone panel uses bearer-token login, the backend must include
and run `2026_08_08_000010_create_personal_access_tokens_table.php`. Without
that migration, successful password validation still fails when Sanctum tries to
insert the issued token.

Existing protected admin APIs are reused:

```txt
/api/admin/v2/integration-connections
/api/admin/v2/conversations
```

## Frontend Structure

New folder:

```txt
frontend/
```

Important files:

```txt
frontend/src/lib/api.ts
frontend/src/store/authStore.ts
frontend/src/components/AppLayout.tsx
frontend/src/pages/LoginPage.tsx
frontend/src/pages/DashboardPage.tsx
frontend/src/pages/IntegrationsPage.tsx
frontend/src/pages/ConversationsPage.tsx
frontend/src/pages/OrganizationPage.tsx
frontend/src/pages/NotificationsPage.tsx
frontend/src/pages/SettingsPage.tsx
```

Production environment:

```env
VITE_API_URL=https://rod-api.ghvcameroon.com/api
```

## Current Frontend Capabilities

- Token login/logout.
- Protected layout with sidebar navigation.
- Dashboard summary from live v2 backend data.
- Edu-admin integration connection creation.
- Initial and incremental sync triggers.
- Conversation list, thread reader, admin replies, close/archive controls.
- Organization visibility for tenants and schools.
- Push/realtime readiness view.
- Settings/profile/runtime view.

## Linking A School

Edu-admin issues connector credentials per Academic Complex. Edu-connect stores
that complex as a tenant, then imports the schools and class structure under it.

The Edu-connect admin app no longer requires a tenant to exist before linking.
If no tenant is selected, Edu-connect calls Edu-admin bootstrap with the issued
credential, creates the tenant from the returned Academic Complex, stores the
connection, and lets the admin run initial sync from the same screen.

When creating the Edu-admin connection in Edu-connect, enter the Edu-admin API
root URL:

```txt
https://eduadmin-api.ghvcameroon.com
```

Do not enter the full `/api/v1/integrations/edu-connect` path, because the
backend HTTP connector appends that path automatically.

## Deployment Notes

Backend `.env` must allow the frontend origin:

```env
FRONTEND_URL=https://rod.ghvcameroon.com
CORS_ALLOWED_ORIGINS=https://rod.ghvcameroon.com,https://rod-api.ghvcameroon.com
```

Build the frontend:

```bash
cd frontend
npm install --no-audit --no-fund
npm run build
```

Deploy the contents of `frontend/dist` to the public folder for `rod.ghvcameroon.com`.

## Verification

Completed locally:

```txt
php artisan test tests\Unit\V2
npm run lint
npm run build
```

The v2 backend suite passed with 54 tests and 537 assertions. The standalone frontend lint and production build passed. After adding the Sanctum token-table migration, `php artisan test --filter=AdminAuthApiTest` passed with 2 tests and 12 assertions, and a local login smoke test returned HTTP 200 with a bearer token.

## Next Work

1. Add full CRUD screens for local schools, devices, students, and admin users through v2 APIs.
2. Add realtime websocket binding in the standalone conversations page after production Pusher/Reverb credentials are final.
3. Add notification delivery drill-down by push token, provider response, and retry state.
4. Retire or redirect the old Laravel/Inertia admin routes once the standalone panel has feature parity.
