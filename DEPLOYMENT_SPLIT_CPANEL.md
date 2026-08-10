# Edu-connect Split cPanel Deployment

Production test domains:

- Backend/API: `https://rod-api.ghvcameroon.com`
- Web frontend/admin: `https://rod.ghvcameroon.com`
- Edu-admin API used by the connector: `https://eduadmin-api.ghvcameroon.com`

## Important Shape

Edu-connect is now split like Edu-admin:

1. `rod-api.ghvcameroon.com` serves the Laravel backend/API only.
2. `rod.ghvcameroon.com` serves the standalone React/Vite admin frontend from `frontend/dist`.
3. The mobile app also talks to `rod-api.ghvcameroon.com`.

The older Laravel/Inertia admin pages can remain temporarily during migration, but the production web panel should use the standalone frontend.

## Packages

Use the generated deployment archives:

- Backend/runtime package: `rod-api-backend-runtime-YYYYMMDD-HHMMSS.zip`
- Standalone frontend package: `rod-frontend-standalone-YYYYMMDD-HHMMSS.zip`

The backend/runtime package contains the Laravel application and Composer dependencies. The standalone frontend package contains the static files from `frontend/dist`.

## Backend Domain

Recommended private folder:

```txt
/home/CPANEL_USER/rod-api/
```

The backend domain document root should point to:

```txt
/home/CPANEL_USER/rod-api/public
```

## Frontend Domain

Point `https://rod.ghvcameroon.com` to a normal static public folder, then extract the standalone frontend package there:

```txt
/home/CPANEL_USER/public_html/rod/
```

That folder should contain `index.html` and `assets/` after extraction.

## Backend `.env`

Create:

```txt
/home/CPANEL_USER/rod-api/.env
```

Start from `.env.production.example`, then set:

```env
APP_KEY=
DB_DATABASE=YOUR_DATABASE_NAME
DB_USERNAME=YOUR_DATABASE_USER
DB_PASSWORD=YOUR_DATABASE_PASSWORD
CORS_ALLOWED_ORIGINS=https://rod.ghvcameroon.com,https://rod-api.ghvcameroon.com

PUSHER_APP_ID=YOUR_REALTIME_APP_ID
PUSHER_APP_KEY=YOUR_REALTIME_APP_KEY
PUSHER_APP_SECRET=YOUR_REALTIME_APP_SECRET
PUSHER_HOST=api-mt1.pusher.com
PUSHER_PORT=443
PUSHER_SCHEME=https
REALTIME_ENABLED=true
REALTIME_DRIVER=pusher
REALTIME_HOST=ws-mt1.pusher.com
REALTIME_PORT=443
REALTIME_SCHEME=https

FCM_CREDENTIALS_PATH=/home/CPANEL_USER/secure/firebase/n3rod-4fb80-firebase-adminsdk.json
```

See `REALTIME_PRODUCTION_SETUP.md` for provider-specific realtime notes and the cPanel cron entries needed for smooth message/push delivery.

Place the Firebase service-account JSON outside public web roots, for example:

```txt
/home/CPANEL_USER/secure/firebase/n3rod-4fb80-firebase-adminsdk.json
```

## Commands

Run in cPanel Terminal:

```bash
cd ~/rod-api
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan educonnect:realtime-check
php artisan optimize
```

If needed, use the PHP 8.2 binary:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate --force
```

The admin login API uses Laravel Sanctum bearer tokens. The migration
`2026_08_08_000010_create_personal_access_tokens_table.php` must be present and
`php artisan migrate --force` must run successfully, otherwise login fails with
`Table '...personal_access_tokens' doesn't exist`.

## Edu-admin Connection

On Edu-admin production, issue an Edu-connect connector credential.

In Edu-connect production, create an integration connection with:

```txt
Base URL: https://eduadmin-api.ghvcameroon.com
Driver: http
API version: v1
Access token: the token issued by Edu-admin
Webhook secret: the webhook secret issued by Edu-admin
```

Use the Edu-admin API root URL only. Edu-connect appends
`/api/v1/integrations/edu-connect/...` automatically when it calls bootstrap,
resource sync, and attendance push endpoints.

If no Edu-connect tenant exists yet, leave the tenant selector on
`Create from Edu-admin complex`. Edu-connect will verify the credential against
Edu-admin bootstrap, create the tenant from the Academic Complex, then store the
connection.

Production must use the HTTP connector driver:

```env
EDU_ADMIN_CONNECTOR_DRIVER=http
```

After changing `.env`, run `php artisan optimize:clear` before retrying the
link. If this is left as `fixture`, production will try to read local test
fixtures instead of calling Edu-admin.

If linking fails while saving the webhook secret, make sure migration
`2026_08_08_000011_widen_ec_integration_connection_webhook_secret.php` has run.
Laravel encrypted webhook secrets are longer than the raw `ecwhsec_...` value,
so the database column must be `TEXT`.

The standalone frontend uses:

```txt
https://rod-api.ghvcameroon.com/api/admin/v2/auth/login
https://rod-api.ghvcameroon.com/api/admin/v2/dashboard
https://rod-api.ghvcameroon.com/api/admin/v2/integration-connections
https://rod-api.ghvcameroon.com/api/admin/v2/conversations
```

## Queue And Scheduler

Add a cron job every minute:

```bash
cd /home/CPANEL_USER/rod-api && php artisan schedule:run >> /dev/null 2>&1
```

If long-running workers are supported:

```bash
cd /home/CPANEL_USER/rod-api && php artisan queue:work --queue=edu-connect,default --tries=3 --timeout=120
```

## Testing

Backend health:

```txt
https://rod-api.ghvcameroon.com/up
```

Frontend:

```txt
https://rod.ghvcameroon.com/login
```

Mobile API:

```txt
https://rod-api.ghvcameroon.com/api/mobile/v2/config
```

Connector setup depends on the Edu-admin credentials being issued in production.
