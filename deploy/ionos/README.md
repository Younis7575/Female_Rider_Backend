# Deploying FemaleRider to IONOS Web Hosting (femalerider.mytechrcm.com)

IONOS Web Hosting Plus is shared hosting: PHP 8.4, MySQL, SSH and cron, but **no Redis and no
long-running processes**. The app runs in *shared-hosting mode*:

| Concern | Shared-hosting setting |
|---|---|
| Driver presence / dispatch | `PRESENCE_DRIVER=sql` (driver_locations table) |
| Cache, locks, rate limits | `CACHE_STORE=database` |
| Queue (push notifications, events) | `QUEUE_CONNECTION=database` + a worker started by the scheduler every minute (`SHARED_HOSTING=true`) |
| Realtime | `BROADCAST_CONNECTION=null` → the app polls every 5–8 s. Optional: free Pusher Channels (`BROADCAST_CONNECTION=pusher` + `PUSHER_*`) for live sockets — no code change |
| OTP without SMS provider | `OTP_TEST_NUMBERS` + `OTP_TEST_CODE` (QA numbers only) |

## Pipeline
```
push to main ─► GitHub Actions: Pint + 108 tests (PHP 8.4, MySQL 8, SQL presence mode)
             ─► build Vite assets ─► force-push release commit to branch `production`
             ─► IONOS cron runs deploy/ionos/pull-deploy.sh every minute: fetch → composer → migrate
                → cache → REVISION   (automatic rollback on failure; log: storage/logs/deploy.log)
             ─► Actions smoke test waits until /api/v1/health reports the new revision
```
No secrets are stored in GitHub; the server needs no inbound deploy access.

## One-time setup
1. IONOS → Hosting → Databases → create a MariaDB database (note host, name, user, password).
2. Subdomain `femalerider.mytechrcm.com` → webspace folder `/femalerider-api/public` (done), PHP 8.4.
3. SSH in and run the bootstrap (asks for the database details at hidden prompts):
   ```bash
   ssh a2681360@access-5020843190.webspace-host.com
   bash <(curl -fsSL https://raw.githubusercontent.com/Younis7575/Female_Rider_Backend/main/deploy/ionos/bootstrap.sh)
   ```
4. IONOS → Cron jobs, every minute (use the PHP path printed by bootstrap):
   - `/usr/bin/php8.4-cli $HOME/femalerider-api/artisan schedule:run`
   - `bash $HOME/femalerider-api/deploy/ionos/pull-deploy.sh`
5. `php artisan fr:create-admin you@example.com` (password typed at a hidden prompt), then enable 2FA at `/admin`.
6. Copy the Firebase service account to `storage/app/private/firebase/service-account.json` (`chmod 600`).

## Operations
* Force a redeploy: `FORCE_DEPLOY=1 bash ~/femalerider-api/deploy/ionos/pull-deploy.sh`
* Logs: `~/femalerider-api/storage/logs/{deploy.log,laravel-*.log}`
* Health: `https://femalerider.mytechrcm.com/api/v1/health`
