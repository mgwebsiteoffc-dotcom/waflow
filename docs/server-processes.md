# Running the queue worker and scheduler

The app uses `QUEUE_CONNECTION=database`. When a webhook arrives the request
only **stores a row in the `jobs` table and returns immediately** — nothing is
processed until a worker consumes that row. If no worker is running, jobs pile
up silently and `storage/logs/laravel.log` stays empty because job code never
executes.

The scheduler is a second required process: it runs the every-minute health
heartbeat, rotates Shopify tokens every 30 minutes (without it tokens expire
and order sync returns 401), recovers stalled checkouts every 15 minutes and
prunes old data daily.

## 60-second diagnosis

```bash
cd /var/www/waflow

# Jobs waiting for a worker (0 = queue is empty)
php artisan tinker --execute="echo DB::table('jobs')->count();"

# Jobs that crashed after all retries
php artisan queue:failed

# What the scheduler would run and last heartbeat state
php artisan schedule:list

# Process one job in the foreground — errors print directly
php artisan queue:work --once
```

If the jobs count keeps growing and no `queue:work` process appears in
`ps aux | grep queue:work`, the worker is simply not running.

## Option A — Supervisor (recommended on Ubuntu/Debian)

Ready configs are in `deploy/supervisor/`:

```bash
sudo cp deploy/supervisor/whatify-worker.conf    /etc/supervisor/conf.d/
sudo cp deploy/supervisor/whatify-scheduler.conf /etc/supervisor/conf.d/
# Edit both files if the project path, PHP path or user is different
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Both services auto-start on boot and restart after a crash. Worker log:
`/var/log/whatify-worker.log`; scheduler log: `/var/log/whatify-scheduler.log`.

## Option B — systemd

```bash
sudo cp deploy/systemd/whatify-worker.service    /etc/systemd/system/
sudo cp deploy/systemd/whatify-scheduler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now whatify-worker whatify-scheduler
systemctl status whatify-worker whatify-scheduler
journalctl -u whatify-worker -f
```

## Option C — no daemons (cron only)

Paste `deploy/cron/whatify.cron` into `crontab -e`. Every minute cron runs the
scheduler and a worker that drains the queue then exits. Suitable for low
volume or shared hosting.

### Simplest fallback: synchronous queue

If running any background process is impossible, set in `.env`:

```
QUEUE_CONNECTION=sync
```

then `php artisan config:clear`. Webhook jobs then run inside the incoming
HTTP request instead of via a worker. The scheduler is still needed for token
rotation, so keep the cron `schedule:run` line. Downside: slow sends delay
Shopify's webhook response and a failure triggers Shopify retries — use only
as a stopgap.

## Logs and permissions

- Application log: `storage/logs/laravel.log` (daily channel available via
  `LOG_STACK=daily`).
- The `storage/` and `bootstrap/cache/` directories must be writable by the
  web user:
  ```bash
  sudo chown -R www-data:www-data storage bootstrap/cache
  sudo chmod -R 775 storage bootstrap/cache
  ```
- An empty log file usually means no events have been processed yet — check
  the Webhook events page in the app first; if Shopify has never delivered,
  there is nothing for the worker to log.

## After every code deploy

Workers hold code in memory, so restart them after `git pull`:

```bash
php artisan config:clear
php artisan queue:restart        # graceful: the supervisor/systemd process respawns
```

## Process checklist

| Process | Command | Required for |
|---|---|---|
| Queue worker | `php artisan queue:work database` | Webhook processing, WhatsApp sends, replies |
| Scheduler | `php artisan schedule:work` (or cron `schedule:run`) | Token refresh, heartbeat, cart recovery, pruning |
| Web server | nginx/apache + PHP-FPM | Serving the app and HTTPS webhook endpoint |

The Automation health page shows *Scheduler heartbeat missing* until the
scheduler has run at least once, and the Webhook events page shows
*Queued / waiting for worker* for any event the worker has not consumed.
