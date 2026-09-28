# Deployment / Operations — DR.SCENT WhatsApp AI MVP

## 1. Production environment requirements

- PHP `^8.3` with `pdo_mysql`, Composer
- MySQL 8.x, reachable from the app server
- Public HTTPS host (Meta webhooks require HTTPS)
- A process supervisor (systemd, Supervisor, or the container platform's
  process manager — whichever the deployment already uses)

## 2. Laravel deployment basics

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env   # then fill production values, never commit them
php artisan key:generate
php artisan migrate --force
```

## 3. MySQL migration

Create the production database (`utf8mb4`) and a least-privilege user, then
`php artisan migrate --force`. The `jobs`, `job_batches`, and `failed_jobs`
tables come from the default Laravel migration — no extra setup needed.

## 4. APP_KEY

Generate once per environment (`php artisan key:generate`) and back it up
securely. Changing it later invalidates encrypted sessions/cookies.

## 5. Production `.env`

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<production-host>
DB_*=<production database>
QUEUE_CONNECTION=database
GEMINI_API_KEY=<secret>
GEMINI_MODEL=gemini-3.5-flash-lite
WHATSAPP_ACCESS_TOKEN=<secret>
WHATSAPP_PHONE_NUMBER_ID=<id>
WHATSAPP_VERIFY_TOKEN=<chosen random string>
WHATSAPP_APP_SECRET=<secret>
WHATSAPP_API_VERSION=<current version from Meta dashboard>
```

No secrets in tracked files, docs, logs, or chat transcripts.

## 6. HTTPS requirement

`APP_URL` must be `https://`. Meta only delivers webhooks to HTTPS callback
URLs.

## 7. Queue database configuration

Default `QUEUE_CONNECTION=database` uses the `jobs` table. No Redis/Horizon
for this MVP.

## 8. ONE queue worker

Run exactly one worker process:

```bash
php artisan queue:work --tries=1 --timeout=60
```

Job-level `tries = 1` and `timeout = 60` (below the driver's 90s
`retry_after`) already enforce no-retry semantics; the CLI flags restate them
for the supervisor config. Never run two workers: per-conversation
serialization does not exist yet, and concurrent workers could race
duplicate-prone paths.

## 9. Process supervision

Supervise the single worker with the platform's standard tool:

- systemd unit with `Restart=always`, OR
- Supervisor program (`autorestart=true`, `numprocs=1`), OR
- the container orchestrator's restart policy.

Any one of these is fine; pick what the host already uses.

## 10. Laravel cache commands

After each deploy:

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache   # only if the app ever defines events/listeners
```

`php artisan optimize` covers config+routes. Re-run after every `.env` or
code change; run `php artisan optimize:clear` when debugging.

## 11. Permissions

The web-server/worker user must own (or be able to write):

- `storage/` (logs, framework cache, `app/private/business_profile.json` reads)
- `bootstrap/cache/` (config/route caches)

## 12. Health check

`GET /up` returns the framework health status. Point the load balancer or
uptime monitor at it.

## 13. Webhook callback

```text
https://<production-host>/api/webhooks/whatsapp
```

`GET` verifies the token; signed `POST` events enqueue jobs. Subscribe the
`messages` field in the Meta app. Keep the callback stable — changing it
requires re-verification in the Meta dashboard.

## 14. Restart/reload workers after deployment

Queue workers boot the app once and keep running old code. After every
deploy (and every `.env` change):

```bash
php artisan queue:restart
```

This signals workers to exit after their current job; the supervisor starts
fresh ones on the new code.

## 15. Log / failed-job inspection

- Application log: `storage/logs/laravel.log` (failures log warning/error
  with error class + status only — never tokens, secrets, profiles, or full
  conversations).
- Failed jobs: `php artisan queue:failed` / `failed_jobs` table. With
  `tries = 1`, any unexpected job exception lands here instead of retrying.
  Note: a failure recorded AFTER a confirmed Meta send must be investigated
  (outgoing delivery may have happened) but must never be retried manually
  with a re-send — reconcile via the stored messages and Meta dashboard.

## 16. Rollback considerations

- Code rollback: redeploy the previous release, re-run caches, restart the
  worker. Migrations in this MVP are additive; no destructive rollback steps
  exist yet — verify before rolling back across a migration change.
- Pausing the bot safely: stopping the worker only pauses processing —
  already-queued jobs wait in `jobs` and run on restart, and the webhook keeps
  returning 200 while dispatching. There is no kill switch yet; to fully
  silence automation without code changes, stop the worker AND drain/delete
  pending `jobs` rows deliberately (data loss is then explicit and auditable).

---

## Future: production Coexistence checklist (NOT yet performed)

After test-number + deployed-server end-to-end passes:

1. Audit current production WhatsApp Business App state.
2. Identify current WABA/provider associations.
3. Document current linked devices.
4. Use the official WhatsApp Business App + Cloud API Coexistence onboarding.
5. Do NOT full-migrate/deregister blindly.
6. Verify the Business App still works.
7. Verify the API webhook.
8. Verify API send.
9. Verify manual employee replies.
10. Verify linked devices / re-link if required.
11. Verify how employee/manual messages appear in webhook/history.
12. Only then launch.

### Known validation item: manual human-reply history

During Coexistence, when a human employee replies manually from the WhatsApp
Business App while a conversation is `waiting_human`, we must verify whether
those employee messages arrive through Meta webhooks in a form this
application can observe. If the AI is later resumed, its history should
ideally reflect the human exchange. No speculative support is implemented —
validate first during Coexistence testing.
