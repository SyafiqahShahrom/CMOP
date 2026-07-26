# DEPLOYMENT.md — CMOP / TBIP Deployment & Operations

## 1. Local Development

Docker Compose is the only supported local setup — no "install PHP/MySQL/Redis natively" path, so environment drift between contributors (and between local and CI) is not a class of bug the project has to deal with.

```
docker compose up
```

Services: `app` (PHP-FPM), `nginx`, `mysql`, `redis`, `queue` (worker), `scheduler`, `mailpit` (SMTP catcher for local email testing), `vite` (frontend dev server with HMR). `minio` is optional, used only if S3-compatible object storage is being exercised locally instead of the local disk driver.

## 2. Docker & Compose

- Multi-stage `Dockerfile`: a `base` stage (PHP 8.3-fpm + extensions), a `dependencies` stage (composer install, cached separately from app code so dependency layers aren't invalidated by every code change), and separate `development`/`production` targets — production excludes dev dependencies, Xdebug, and source maps.
- `docker-compose.yml` for local development (bind-mounted source, Vite dev server); a separate `docker-compose.prod.yml` overlay for production-shaped local testing (built image, no bind mounts) — never the same compose file serving both purposes with a pile of conditionals.
- The `queue` and `scheduler` services run the same application image as `app`, differing only in container command (`queue:work`, `schedule:work`) — one image, multiple roles, so there is never a drift between what the web process and the worker process can do.

## 3. Environment Variables

Standard Laravel `.env` — never committed; `.env.example` documents every required key with a safe placeholder. Categories:

- **App**: `APP_KEY`, `APP_ENV`, `APP_URL`, `APP_DEBUG` (always `false` outside local).
- **Database**: `DB_*` — connection to MySQL.
- **Redis**: `REDIS_*` — session, cache, queue connections.
- **Mail**: `MAIL_*` — Mailpit locally, real SMTP/provider in higher environments.
- **Queue**: `QUEUE_CONNECTION=redis`.
- **Storage**: `FILESYSTEM_DISK` (local in dev, S3-compatible in production for evidence attachments and generated reports — see SECURITY.md §6 on data at rest).

Secrets in non-local environments are injected via the hosting platform's secret store (e.g., Render environment groups), never baked into the image or committed alongside code.

## 4. Queue Workers

- Redis-backed (`QUEUE_CONNECTION=redis`), run via `php artisan queue:work --tries=3 --backoff=10,30,60` under Supervisor (or the platform's process manager) so a crashed worker restarts automatically rather than silently dropping the queue.
- Separate queues by workload so a slow report-generation job never starves time-sensitive notification dispatch: `default`, `imports`, `matching`, `notifications`, `reports` — each with its own worker process/concurrency tuned to its job's cost (see ARCHITECTURE.md §7).
- Failed jobs land in Laravel's `failed_jobs` table for inspection/manual retry — never silently discarded.

## 5. Scheduler

`php artisan schedule:work` (or a cron entry invoking `schedule:run` every minute in a non-containerized target) drives: trade/payment file polling, the hourly SLA breach sweep, scheduled report generation, and routine cleanup (expired sessions, stale password-reset tokens). The scheduler process is a single instance — never run redundantly across multiple app containers, which would double-fire scheduled jobs; this is enforced by running it as its own dedicated `scheduler` service/container, not embedded in each `app` replica.

## 6. Production Topology

```
                        [ Load Balancer / TLS termination ]
                                   |
                    -----------------------------
                    |                           |
              [ Nginx + app (web) ]      [ Nginx + app (web) ]   (horizontally scaled)
                    |                           |
                    -----------------------------
                                   |
              -------------------------------------------
              |                |               |
        [ MySQL (primary) ] [ Redis ]   [ Object storage (S3-compatible) ]
              |
        [ queue workers ]  [ scheduler (single instance) ]
```

Web tier scales horizontally behind the load balancer; queue workers scale independently per queue based on backlog; MySQL and Redis are managed services in production rather than self-hosted containers, consistent with ARCHITECTURE.md §10's "vertical/managed-services-first" scaling stance.

## 7. CI/CD (GitHub Actions)

Pipeline stages on every PR and on merge to `main`:

1. **Lint** — Laravel Pint (PHP style), ESLint/Prettier (frontend).
2. **Static analysis** — PHPStan.
3. **Test** — Pest (unit + feature), against a real MySQL service container (not SQLite-in-memory, to catch MySQL-specific behavior — e.g., collation, `bigint` money columns — before production).
4. **Build** — frontend asset build (Vite), Docker image build.
5. **Deploy** (main branch only, after all above pass) — push image, trigger platform deploy, run `php artisan migrate --force` as a release step before traffic cutover.

No stage is skippable via a manual override in the pipeline — a red pipeline blocks merge, matching the project's "auditability and correctness over velocity shortcuts" philosophy (PROJECT.md §3).

## 8. Render Deployment

Render is the target hosting platform for the demoable/portfolio deployment: a `web` service (Docker, the `production` Dockerfile target), a `worker` service (queue), and a `cron` job (scheduler tick, since Render's native cron suits the single-instance scheduler requirement in §5 well).

**Note on database**: Render's managed database offering is Postgres-first. The project's managed MySQL requirement (§6) is provisioned via a third-party managed MySQL add-on rather than Render's native offering — this is documented here as a deployment-target-specific detail, not a change to the stack decision recorded in ARCHITECTURE.md/DECISIONS.md.

## 9. Migration Strategy

- Migrations run as an explicit release step (`migrate --force`), never automatically on container boot — boot-time auto-migration risks multiple web replicas racing to migrate simultaneously.
- All migrations are additive-first (add column/table, backfill, then a later deploy removes the old column) for zero-downtime deploys — a single migration is never both "add new schema" and "drop old schema" in the same deploy.
- Destructive migrations (column/table drops) require a documented rollback plan in the PR description and are never bundled with unrelated feature migrations.

## 10. Rollback Strategy

- Application code: redeploy the previous image tag (all images are immutably tagged with commit SHA) — the platform keeps the last N successful builds available for one-click rollback.
- Database: because migrations are additive-first (§9), rolling back application code never requires rolling back a migration in the same motion — the old code simply ignores new columns it doesn't use yet. A genuine schema rollback (rare) uses a paired `down()` migration, tested in CI, not a manual production hotfix.
- Queue/scheduler: rolling back the app image rolls back worker/scheduler images identically, since they share the same image (§2) — no separate rollback procedure to keep in sync.
