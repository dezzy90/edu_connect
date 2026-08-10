# EduConnect Realtime Production Setup

EduConnect already emits realtime events for mobile notifications, attendance updates, child linking, and chats. Production needs a Pusher-compatible websocket provider or a properly hosted Laravel Reverb/Soketi server.

For HostGator/cPanel, use a managed Pusher-compatible provider first. It avoids keeping a long-running websocket process alive on shared hosting.

## Required `.env` Values

```env
BROADCAST_CONNECTION=pusher

# Server-side event publishing REST/API endpoint.
PUSHER_APP_ID=your-provider-app-id
PUSHER_APP_KEY=your-provider-app-key
PUSHER_APP_SECRET=your-provider-app-secret
PUSHER_APP_CLUSTER=mt1
PUSHER_HOST=api-mt1.pusher.com
PUSHER_PORT=443
PUSHER_SCHEME=https

# Client websocket endpoint returned to the mobile app.
REALTIME_ENABLED=true
REALTIME_DRIVER=pusher
REALTIME_APP_KEY="${PUSHER_APP_KEY}"
REALTIME_APP_SECRET="${PUSHER_APP_SECRET}"
REALTIME_HOST=ws-mt1.pusher.com
REALTIME_PORT=443
REALTIME_SCHEME=https
```

If the provider is not Pusher Channels, replace `PUSHER_HOST` and `REALTIME_HOST` with the provider's REST/API host and websocket host. They may be the same for Soketi or different for managed providers.

## Queue And Scheduler

Realtime chat events broadcast immediately, but mobile message publishing, push dispatch, sync, and attendance outbox work depend on Laravel scheduled jobs and/or queue workers.

On cPanel, add this cron entry:

```bash
* * * * * cd /home4/ghvcamer/public_html/website_9d3cb424 && /opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

If persistent queue workers are unavailable on shared hosting, run this cron every minute too:

```bash
* * * * * cd /home4/ghvcamer/public_html/website_9d3cb424 && /opt/cpanel/ea-php82/root/usr/bin/php artisan queue:work database --queue=edu-connect,default --stop-when-empty --tries=3 >> /dev/null 2>&1
```

## Verification

After setting `.env`, deploy, then run:

```bash
cd /home4/ghvcamer/public_html/website_9d3cb424
/opt/cpanel/ea-php82/root/usr/bin/php artisan optimize:clear
/opt/cpanel/ea-php82/root/usr/bin/php artisan educonnect:realtime-check
```

Expected result:

```txt
EduConnect realtime status: ready
Realtime is ready for mobile websocket connections.
```

From the mobile app, sign in and open a chat. A sent message should appear immediately for the sender, and the recipient should receive the message through websocket while the app is open. Push notifications remain the offline/background fallback.
