# SeWaroeng — Staging Deployment Readiness and Integration Onboarding Guide

**Document Status:** Hardened Operational Runbook & Safety Closure (Sprint 9)  
**Target Architecture:** Linux (Ubuntu 22.04+ / Debian 12+) with Docker / Systemd or Managed Cloud (AWS/GCP/DigitalOcean)  
**Remote Deployment Status:** NOT DEPLOYED (Local deployment toolkit, build rehearsal, and safety closures verified; remote staging infrastructure awaiting provisioning)  
**Date:** October 2026  
**Audience:** DevOps Engineers, SecOps, Principal Laravel Engineers, Flutter Release Engineers, SREs  

---

## 1. Actual Deployment Target & Topology

### 1.1 Infrastructure Topology
The SeWaroeng multi-tenant SaaS platform follows an API-first service architecture with client-side Flutter distribution:

```
[ Internet / Mobile & Web Clients ]
                 │
                 ▼ (HTTPS / TLS 1.3)
      [ Reverse Proxy / Load Balancer (Nginx / Caddy / Cloudflare) ]
                 │
                 ├──► /api/v1/*   ──────► [ Laravel 13 App Cluster (PHP 8.4-FPM) ]
                 │                             │           │
                 ├──► /up (Liveness)           │           │
                 │                             ▼           ▼
                 ├──► Webhooks (Meta/Gateways) │   [ PostgreSQL 16 Cluster ]
                 │                             │     (RLS, UUIDv7, btree_gist)
                 └──► Web Static (Flutter)     │           │
                                               ▼           ▼
                                     [ Redis 7.x Cluster ]
                                      - Cache & Sessions
                                      - Queue (default)
                                      - Distributed Mutex Locks
                                               ▲
                                               │
                                     [ Queue Workers & Schedulers ]
                                      - php artisan queue:work redis --queue=default,notifications,webhooks
                                      - php artisan schedule:run
```

### 1.2 Target Prerequisites
- **Runtime**: PHP 8.4 CLI & FPM with extensions: `pdo_pgsql`, `pgsql`, `redis`, `mbstring`, `xml`, `ctype`, `iconv`, `intl`, `bcmath`, `curl`, `zip`.
- **Database**: PostgreSQL 16+ with `btree_gist` extension enabled.
- **Cache & Message Broker**: Redis 7.x (memory capped with `allkeys-lru` eviction policy for cache, or separate Redis DB for queues with persistence).
- **Web Server**: Nginx, Apache 2.4, or Caddy terminating TLS 1.3 with HTTPS certificates (Let's Encrypt / ACME / cPanel AutoSSL). For Apache & cPanel deployment details, see [`docs/deployment/CPANEL_APACHE.md`](file:///d:/A/SeWaroeng/docs/deployment/CPANEL_APACHE.md).
- **Process Supervision**: Systemd (VPS), Docker container orchestration (Kubernetes / ECS), or Cron-driven workers (cPanel).

---

## 2. Canonical Environment Variable Inventory & Reconciliation

All environment variables consumed by application code have been audited and reconciled. No real credentials are committed to version control. All staging and production deployments must populate `.env` derived from [`.env.example`](file:///d:/A/SeWaroeng/backend/.env.example).

| Variable Name | Required / Optional | Default Value | Consumer Code / Config | Purpose & Notes |
|---|---|---|---|---|
| `APP_NAME` | Required | `SeWaroeng` | `config/app.php` | Display name of the platform. |
| `APP_ENV` | Required | `local` | `bootstrap/app.php` | Set to `staging` or `production`. Disables test mocks. |
| `APP_KEY` | Required | None | `config/app.php` | 32-byte base64 encryption key (`php artisan key:generate`). |
| `APP_DEBUG` | Required | `false` | `config/app.php` | Must be `false` in staging/production to prevent stack traces. |
| `APP_URL` | Required | None | `config/app.php` | Public HTTPS domain (e.g. `https://<staging-api-host>`). |
| `TRUSTED_PROXIES` | Optional | `*` | `bootstrap/app.php` | Reverse proxy IP or CIDR block terminating TLS. |
| `CORS_ALLOWED_ORIGINS` | Required in Staging / Optional in Prod | None | `config/cors.php` | Comma-separated list of allowed frontend origins (e.g. `https://sewaroeng.neuracakrawira.asia` or explicit staging frontend origin). Fails closed in staging if unset. |
| `DB_CONNECTION` | Required | `pgsql` | `config/database.php` | Database driver (PostgreSQL only). |
| `DB_HOST` | Required | `127.0.0.1` | `config/database.php` | PostgreSQL host. |
| `DB_PORT` | Required | `5432` | `config/database.php` | PostgreSQL port. |
| `DB_DATABASE` | Required | None | `config/database.php` | Staging database (e.g. `sewaroeng_staging`). |
| `DB_USERNAME` | Required | None | `config/database.php` | Database role (`sewaroeng_app`, `NOSUPERUSER NOBYPASSRLS`). |
| `DB_PASSWORD` | Required | None | `config/database.php` | Database user password. |
| `REDIS_CLIENT` | Required | `phpredis` | `config/database.php` | High-performance C-extension Redis client. |
| `REDIS_HOST` | Required | `127.0.0.1` | `config/database.php` | Redis server hostname. |
| `REDIS_PORT` | Required | `6379` | `config/database.php` | Redis server port. |
| `REDIS_PASSWORD` | Optional | `null` | `config/database.php` | Redis auth password. |
| `CACHE_STORE` | Required | `redis` | `config/cache.php` | Must be `redis` in staging for distributed scheduler mutex. |
| `SESSION_DRIVER` | Required | `redis` | `config/session.php` | Distributed user session driver. |
| `SESSION_SECURE_COOKIE` | Required | `true` | `config/session.php` | Enforces HTTPS-only cookies in non-local environments. |
| `QUEUE_CONNECTION` | Required | `redis` | `config/queue.php` | Asynchronous background job queue connection. |
| `MIDTRANS_SERVER_KEY` | Optional / Required for Midtrans | `''` | `config/services.php:midtrans` | Fallback platform server key (Sandbox or Production). |
| `MIDTRANS_CLIENT_KEY` | Optional / Required for Midtrans | `''` | `config/services.php:midtrans` | Client key used for client-side token generation. |
| `MIDTRANS_MERCHANT_ID` | Optional | `''` | `config/services.php:midtrans` | Midtrans Merchant ID. |
| `MIDTRANS_IS_PRODUCTION`| Required | `false` | `config/services.php:midtrans` | Boolean flag switching Midtrans API between Sandbox & Prod. |
| `MIDTRANS_BASE_URL` | Optional | `null` | `config/services.php:midtrans` | Custom API base URL override for mock testing. |
| `DUITKU_MERCHANT_CODE` | Optional / Required for Duitku | `''` | `config/services.php:duitku` | Fallback platform merchant code (Sandbox or Production). |
| `DUITKU_API_KEY` | Optional / Required for Duitku | `''` | `config/services.php:duitku` | Merchant API key used for invoice generation & MD5 hashing. |
| `DUITKU_IS_PRODUCTION` | Required | `false` | `config/services.php:duitku` | Boolean flag switching Duitku API between Sandbox & Prod. |
| `META_WHATSAPP_GRAPH_VERSION` | Optional | `v21.0` | `config/services.php:whatsapp` | Meta Graph API version (`v21.0`, `v22.0`, `v25.0`). |
| `META_WHATSAPP_APP_SECRET` | Required for WABA | `null` | `config/services.php:whatsapp` | Platform Meta App Secret for inbound webhook HMAC-SHA256. |
| `META_WHATSAPP_VERIFY_TOKEN` | Required for WABA | `null` | `config/services.php:whatsapp` | Challenge token for Meta GET webhook subscription validation. |
| `SEWAROENG_SUPPORT_CONTACT` | Optional | `null` | `config/services.php:whatsapp` | Support contact phone number displayed in notification footers. |

> **Crucial Architecture Rule on Tenant Credentials**: Merchant-specific WhatsApp credentials (`access_token`, `phone_number_id`, `business_account_id`) and payment connections (`merchant_code`, `api_key`) are NOT configured in `.env`. They are configured per-merchant via authenticated management endpoints and stored encrypted in PostgreSQL (`tenant_whatsapp_connections` and `tenant_payment_connections` tables). The environment variables above represent platform-level fallbacks and webhook signature verification secrets only.

---

## 3. Flutter Client Fail-Closed Configuration & URL Normalization

### 3.1 Fail-Closed Configuration Contract (`AppConfig`)
The Flutter client implementation in [`frontend/lib/core/config/app_config.dart`](file:///d:/A/SeWaroeng/frontend/lib/core/config/app_config.dart) enforces strict compile-time and runtime validation:

1. **Explicit Environments**: Supported values for `APP_ENV` are `development`, `staging`, and `production`. Any other value immediately throws a `StateError`.
2. **Production Fail-Closed Guard**: In `production`, `API_BASE_URL` **must be explicitly provided** at build time via `--dart-define=API_BASE_URL=https://<domain>`. If omitted or empty, app initialization throws `StateError` immediately, preventing accidental fallback to staging or localhost.
3. **HTTPS Protocol Enforcement**: For both `staging` and `production` builds, the URL scheme must be `https://`. Any attempt to pass `http://` throws a `StateError`.
4. **Logging Suppression**: In `production`, `enableLogging` is unconditionally forced to `false` regardless of flags.
5. **No Embedded Secrets**: Client builds never contain server keys, database passwords, or webhook secrets.

### 3.2 URL Normalization Semantics (`ApiClient.buildUri`)
In [`frontend/lib/core/api/api_client.dart`](file:///d:/A/SeWaroeng/frontend/lib/core/api/api_client.dart), the `buildUri(path)` method eliminates route prefixing discrepancies:
- **Deduplication**: When `baseUrl` ends with `/api/v1` (standard deployment contract) and request paths also start with `/api/v1/` (e.g. `/api/v1/employees`), `buildUri` deduplicates `/api/v1` so the final path never doubles (`.../api/v1/employees`).
- **Standard Path Joining**: When request paths omit `/api/v1` (e.g. `/loyalty/program` or `/outlets/123/shifts`) and `baseUrl` includes `/api/v1`, the joined URI correctly resolves to `https://<domain>/api/v1/outlets/123/shifts`.
- **Slash Normalization**: Redundant trailing slashes on `baseUrl` and leading slashes on paths are safely trimmed and joined.
- **Unit Verification**: Verified by targeted unit tests in [`frontend/test/core/api_client_url_test.dart`](file:///d:/A/SeWaroeng/frontend/test/core/api_client_url_test.dart).

### 3.3 Supported Build Commands
```powershell
# Staging Web Build (Requires explicit staging API URL; fails closed if omitted)
flutter build web \
  --dart-define=APP_ENV=staging \
  --dart-define=API_BASE_URL=https://<staging-api-host>/api/v1

# Staging Android APK Build
flutter build apk --release \
  --dart-define=APP_ENV=staging \
  --dart-define=API_BASE_URL=https://<staging-api-host>/api/v1

# Production Web Build (Targets Planned Production Domain)
flutter build web \
  --dart-define=APP_ENV=production \
  --dart-define=API_BASE_URL=https://api.sewaroeng.neuracakrawira.asia/api/v1

# Production Android AppBundle Build (Targets Planned Production Domain)
flutter build appbundle --release \
  --dart-define=APP_ENV=production \
  --dart-define=API_BASE_URL=https://api.sewaroeng.neuracakrawira.asia/api/v1
```

---

## 4. Actual Queue Routing, Worker Configuration, and Dead-Letter Recovery

### 4.1 Queue Job Routing Audit
An audit of all job classes (`SendWhatsAppMessageJob.php`, `SendWhatsAppReceiptJob.php`) confirms:
- Neither job overrides the `$queue` property or calls `onQueue()`.
- Therefore, all current asynchronous jobs dispatch to the connection's **`default`** queue in Redis.
- If an operator launches a worker listening only to `notifications` or `webhooks`, **all current messages will starve**.

### 4.2 Canonical Worker Command & Backoff Semantics
To ensure both current jobs and any future partitioned queues are consumed in strict priority order, the worker command is:

```bash
php artisan queue:work redis --queue=default,notifications,webhooks --sleep=3 --tries=3 --backoff=5,15,45 --timeout=60
```

#### Framework Verification & Backoff Semantics:
- **Installed Framework Version**: `Laravel Framework 13.34.0`.
- **CLI Parameter Parsing**: In `Illuminate\Queue\Console\WorkCommand`, the `--backoff` option defaults to `0`. Passing comma-separated values (e.g. `--backoff=5,15,45`) is accepted by the CLI parser without error.
- **Worker Backoff Resolution (`Worker::calculateBackoff`)**:
  Internally, Laravel's queue worker calculates backoff delay as:
  ```php
  $backoff = explode(',', method_exists($job, 'backoff') && ! is_null($job->backoff())
      ? $job->backoff()
      : $options->backoff);

  return (int) ($backoff[$job->attempts() - 1] ?? last($backoff));
  ```
- **Job-Level Authority Precedence**:
  1. **Job Properties are Authoritative**: Both `SendWhatsAppMessageJob` and `SendWhatsAppReceiptJob` define explicit properties:
     - `public int $tries = 3;`
     - `public array $backoff = [5, 15, 45];`
     - `public int $timeout = 30;`
     When dispatched, Laravel serializes `$backoff` into the job payload and exposes it via `$job->backoff()`. Because `! is_null($job->backoff())` evaluates to true, **the job's own backoff takes precedence over the worker CLI flag**.
  2. **Interception of Transient Errors**: Domain notification jobs catch transient provider failures (such as temporary rate limits or 503 HTTP errors) inside `handle()` and invoke `$this->release($delay)` directly, using their internal backoff schedule (`$this->backoff[$this->attempts() - 1] ?? 30`). This prevents unhandled exceptions from terminating the worker process.
  3. **Uncaught Exception Handling**: If an unexpected exception occurs (e.g., database network severance), the worker's outer catch block invokes `calculateBackoff($job, $options)`, which still honors the job's serialized `[5, 15, 45]` schedule.
  4. **Fallback for Generic Jobs**: The CLI flag `--backoff=5,15,45` provides a consistent step-by-step delay schedule (attempt 1: 5s, attempt 2: 15s, attempt 3: 45s) for generic framework jobs that do not define their own backoff property or method.
- **Queue Priority Ordering**:
  `--queue=default,notifications,webhooks` consumes from `default` first, guaranteeing that all current notification and receipt jobs are processed immediately without starvation, while also listening for future dedicated queue partitions.
- **Timeout Buffer**:
  `--timeout=60` provides a safe 30s buffer over the individual job timeout of `$timeout = 30`, preventing the worker supervisor from prematurely terminating processes during slow network roundtrips.

### 4.3 Safe Dead-Letter Queue & Failed Job Recovery Workflow
When a job exhausts its 3 attempts or experiences an unrecoverable exception, Laravel writes an immutable record to the `failed_jobs` table in PostgreSQL.

#### Incident Recovery Rules:
1. **Never Assume Retrying is Safe**:
   In a multi-tenant POS and notification system, retrying a failed job is NOT automatically safe. Outbound payment captures or WhatsApp messages may have already completed externally even if an acknowledgment timed out (`AMBIGUOUS_TIMEOUT`). Blindly retrying can double-charge customers or spam duplicate messages.
2. **Never Use Destructive Bulk Cleanup (`queue:flush`)**:
   `php artisan queue:flush` permanently drops **all** failed job records across all tenants. It destroys audit trails, eliminates diagnostic evidence, and renders incident post-mortems impossible. It must **never** be used in routine incident response or as an alternative to `queue:forget`.

#### Standard Operational Workflow:
1. **List Failed Jobs**:
   ```bash
   php artisan queue:failed
   ```
2. **Mandatory Pre-Retry Investigation**:
   Before retrying, inspect the exception and payload in PostgreSQL (`failed_jobs` table) or via artisan:
   - **Check Error Classification**:
     - *Permanent Errors*: `CONSENT_REQUIRED`, `CUSTOMER_OPTED_OUT`, `INVALID_PHONE`, `FEATURE_UNENTITLED`, `TEMPLATE_NOT_APPROVED`, `EXPIRED_SERVICE_WINDOW`. Retrying will fail again. Do not retry.
     - *Transient Errors*: HTTP 500/503 from provider, temporary DNS resolution failure.
   - **Check Customer Window & State**:
     - For `TYPE_MERCHANT_QUERY_REPLY`, has the 24-hour customer service window expired?
     - Has the customer opted out of WhatsApp communication since the failure?
   - **Check Provider Side Effects**:
     - Verify provider logs (Meta Graph API dashboard, Midtrans MAP, Duitku portal) to ensure the message or charge was not actually executed.
3. **Retry Specific Failed Job (When Confirmed Safe)**:
   ```bash
   php artisan queue:retry <uuid>
   ```
   *Note: Avoid `php artisan queue:retry all` during active incidents unless all failures have been classified and verified as transient with idempotent handlers.*
4. **Remove Handled or Non-Retryable Record**:
   ```bash
   php artisan queue:forget <uuid>
   ```
   *Only remove an individual job after root cause is resolved, manual alternative delivery is completed, or the failure is determined to be non-recoverable.*

### 4.4 Scheduler Execution, Process Supervision, and Distributed Mutex Locks
- **Registration vs Active Execution**:
  Registering scheduled tasks in `backend/routes/console.php` does **not** execute them. An external supervisor or system cron must actively invoke `php artisan schedule:run` every minute.
- **Queue Workers vs Configuration**:
  Configuring `QUEUE_CONNECTION=redis` does not start background workers. Process supervisors must actively launch and manage worker instances.
- **Cron Configuration**:
  ```bash
  * * * * * www-data cd /var/www/sewaroeng/backend && php artisan schedule:run >> /dev/null 2>&1
  ```
- **Systemd Process Supervision**:
  In staging and production, systemd services or Docker supervisord should oversee workers and schedulers:
  - `sewaroeng-worker.service`: Runs `php artisan queue:work redis --queue=default,notifications,webhooks --sleep=3 --tries=3 --backoff=5,15,45 --timeout=60` with `Restart=always`.
  - `sewaroeng-scheduler.timer`: Runs `php artisan schedule:run` every 60 seconds.
- **Redis Cache Mutex Requirement**:
  In `backend/routes/console.php`, all 4 scheduled commands use `->withoutOverlapping()`:
  - `whatsapp:outbox-recover` (`everyFiveMinutes()`)
  - `whatsapp:booking-reminders` (`hourly()`)
  - `whatsapp:send-daily-summary --check-hour` (`hourly()`)
  - `whatsapp:send-daily-summary` (`dailyAt('22:00')`)
  These commands acquire a distributed lock in the default cache store (`REDIS`). If Redis is unreachable, `schedule:run` fails closed and throws a `RedisException`. Redis must be fully operational before starting the scheduler.

---

## 5. Hardened Liveness & Readiness Probes and Operational Reality

### 5.1 Probe Differentiation
- **Liveness Probe**: `GET /up`
  - Registered in `bootstrap/app.php`.
  - Confirms the PHP runtime and web server process are alive.
  - Returns HTTP 200 OK.
  - Used by container orchestrators (Kubernetes / ECS / Docker healthcheck) to detect process deadlocks and restart stuck containers.
- **Readiness Probe**: `GET /api/v1/health`
  - Registered in `routes/api.php`.
  - Evaluates actual infrastructure operational readiness:
    - Tests database connection via `DB::connection()->getPdo()`.
    - Tests Redis connectivity via `Redis::connection()->ping()` conditionally when Redis is configured as cache, session, or queue driver.
  - Returns HTTP 200 with `status: ok` when all dependencies respond.
  - Returns HTTP 503 with `status: degraded` if database or Redis fails.
  - Used by load balancers and ingress routers to pause routing incoming traffic during startup, database reconnects, or network blips without restarting the process.
  - **Zero Leak Guarantee**: Catches exceptions internally; returns sanitized JSON only (`status` and `timestamp`). Zero database names, usernames, hostnames, connection strings, or stack traces are ever exposed.

Verified by automated tests in [`backend/tests/Feature/HealthAndReadinessTest.php`](file:///d:/A/SeWaroeng/backend/tests/Feature/HealthAndReadinessTest.php).

### 5.2 Operational Boundaries: Readiness vs Remote Deployment
Operational documentation and deployment runbooks must strictly maintain clear boundaries between configuration and active execution:
1. **Liveness vs Readiness**: A live web server (`/up` 200) does not mean the application is ready to serve tenant requests (`/api/v1/health` 503 when PostgreSQL or Redis is unreachable).
2. **Local Scheduler Registration vs Running Scheduler**: Defining schedules in `backend/routes/console.php` only registers the cron jobs within the Laravel kernel. No schedule runs unless an active OS-level process (systemd timer or crontab) executes `php artisan schedule:run` every minute.
3. **Queue Configuration vs Running Workers**: Defining `QUEUE_CONNECTION=redis` only designates Redis as the broker. No queued jobs will execute unless worker processes (`php artisan queue:work`) are actively running and monitored by a supervisor.
4. **Local Build Success vs Remote Staging Deployment**: A successful local Flutter Web compilation or local Laravel test run verifies code correctness and artifact hygiene, but does NOT establish that remote staging infrastructure exists or is accessible.
5. **Contract Test Pass vs Live Integration**: Automated contract tests verifying HTTP mocks, payloads, and HMAC signatures confirm adapter correctness, but do NOT constitute live integration verification with Meta, Midtrans, or Duitku.

---

## 6. Database Migration, Pre-Flight Safety, and Rollback Evidence

### 6.1 Pre-Flight Safety Protocol
Before running any schema migration on staging or production:
1. Confirm the target database using `php artisan migrate:status`.
2. Ensure database user is non-superuser with `NOSUPERUSER NOBYPASSRLS`.
3. Create an automated binary dump prior to running migrations:
   ```bash
   pg_dump -U sewaroeng_app -h 127.0.0.1 -Fc sewaroeng_staging > /backups/pre_migrate_$(date +%Y%m%d_%H%M%S).dump
   ```
4. Run migrations with force flag:
   ```bash
   php artisan migrate --force
   ```

### 6.2 Rollback Rehearsal Evidence
- Rehearsed on PostgreSQL 16 database (`sewaroeng_test`):
  - 1-step rollback: Successfully dropped and re-created customer consent column.
  - 3-step rollback: Successfully dropped and re-created reconciliation tables, WhatsApp tables, and customer consent columns without foreign key lockups.
- All 38 migrations in the repository are reversible with symmetric `down()` methods.

---

## 7. Web Release Artifact Verification Results

- **Build Rehearsal**: Executed `flutter build web --dart-define=APP_ENV=staging --dart-define=API_BASE_URL=https://staging-api.sewaroeng.id/api/v1`.
- **Compile Time**: 45.7s, exit code 0.
- **Security Audit of Compiled Artifacts** (`frontend/build/web/main.dart.js`):
  - `staging-api.sewaroeng.id/api/v1` confirmed embedded at lines 18584 and 18587.
  - Audited for server keys (`MIDTRANS_SERVER_KEY`, `DUITKU_API_KEY`, `META_WHATSAPP_APP_SECRET`): **Zero occurrences found**.
  - Audited for database credentials (`sewaroeng_password`): **Zero occurrences found**.
  - Client bundle is completely free of server secrets.

---

## 8. External Integration Verification Status

| Provider / Environment | Automated Contract / Mock Status | Live Sandbox / Production Status | Prerequisites for Live Verification |
|---|---|---|---|
| **Remote Staging Infrastructure** | `LOCAL READY` | `NOT DEPLOYED` | Requires remote Linux host, DNS records, and SSL certificates. |
| **Meta Cloud WhatsApp API** | `VERIFIED` | `NOT VERIFIED` | Requires Meta System User access token, Phone Number ID, and verified WABA account. |
| **Midtrans Payment Gateway** | `VERIFIED` | `NOT VERIFIED` | Requires live Midtrans MAP sandbox server/client keys and public notification URL. |
| **Duitku Payment Gateway** | `VERIFIED` | `NOT VERIFIED` | Requires live Duitku sandbox merchant code and API key. |

---

## 9. Reproducible Staging Deployment Toolkit & Operational Runbook

### 9.1 Deployment Toolkit Architecture & Artifact Inventory
In Sprint 8, a complete, reproducible staging deployment toolkit was authored under `deploy/`:

| Artifact Path | Component | Purpose & Operational Safety Invariants |
|---|---|---|
| [`deploy/preflight.sh`](file:///d:/A/SeWaroeng/deploy/preflight.sh) | Preflight Inspector | Non-mutating environment inspection. Validates OS, PHP 8.4+, required extensions, permissions, `.env` presence, RLS privileges, and Redis/Postgres connectivity without leaking secrets. |
| [`deploy/deploy-staging.sh`](file:///d:/A/SeWaroeng/deploy/deploy-staging.sh) | Guarded Deployment Pipeline | Explicit deployment script. **Defaults to dry-run**. Requires `--apply` and explicit staging hostnames (`--api-host`, `--web-host`). Enforces `APP_ENV=staging`, `APP_DEBUG=false`, prevents targeting production databases, takes pre-migration `pg_dump` backups, runs forward-only migrations, and verifies post-deploy health. |
| [`deploy/validate-domains.sh`](file:///d:/A/SeWaroeng/deploy/validate-domains.sh) | Domain & Environment Validator | Reusable validator for RFC 1123 hostname syntax, URI scheme safety, cross-environment boundary separation, and Flutter API URL matching. |
| [`deploy/render-nginx-config.sh`](file:///d:/A/SeWaroeng/deploy/render-nginx-config.sh) | Safe Nginx Host Renderer | Generates host-specific configuration files into distinct destinations (`<api-host>.conf` and `<web-host>.conf`) without collision risk, creates atomic symlinks, and validates syntax before reloading. |
| [`deploy/systemd/sewaroeng-worker.service`](file:///d:/A/SeWaroeng/deploy/systemd/sewaroeng-worker.service) | Queue Worker Unit | Systemd service unit running `php artisan queue:work redis --queue=default,notifications,webhooks --sleep=3 --tries=3 --backoff=5,15,45 --timeout=60` under `www-data` with bounded restart and graceful 70s stop timeout. |
| [`deploy/systemd/sewaroeng-scheduler.service`](file:///d:/A/SeWaroeng/deploy/systemd/sewaroeng-scheduler.service) | Scheduler Oneshot Unit | Invokes `php artisan schedule:run` under `www-data`. |
| [`deploy/systemd/sewaroeng-scheduler.timer`](file:///d:/A/SeWaroeng/deploy/systemd/sewaroeng-scheduler.timer) | Scheduler Timer | Fires every minute (`OnCalendar=*-*-* *:*:00`) with persistent catch-up. |
| [`deploy/nginx/templates/api.conf.template`](file:///d:/A/SeWaroeng/deploy/nginx/templates/api.conf.template) | Nginx API Template | Reusable virtual host template for API routing, PHP 8.4-FPM proxy, ACME HTTP-01 passthrough, and liveness probes. |
| [`deploy/nginx/templates/web.conf.template`](file:///d:/A/SeWaroeng/deploy/nginx/templates/web.conf.template) | Nginx Web Template | Reusable virtual host template for Flutter Web SPA client-side routing, static caching, and ACME challenge passthrough. |
| [`deploy/nginx/production/`](file:///d:/A/SeWaroeng/deploy/nginx/production/) | Production Nginx Templates | Dedicated, INACTIVE templates for planned production hosts `api.sewaroeng.neuracakrawira.asia` and `sewaroeng.neuracakrawira.asia`. |
| [`deploy/README.md`](file:///d:/A/SeWaroeng/deploy/README.md) | Operator Guide | Complete manual for server engineers, command cheatsheet, systemd installation instructions, and rollback guidance. |
| [`.github/workflows/deploy-staging.yml`](file:///d:/A/SeWaroeng/.github/workflows/deploy-staging.yml) | Manual Deployment CI | Protected GitHub Actions workflow triggered exclusively via `workflow_dispatch`. Requires explicit staging hostname inputs, protected environment review, and trusted SSH host keys. |

---

### 9.2 Safe Execution Protocol

#### 1. Non-Mutating Preflight Inspection
Run from the repository root on the staging host:
```bash
bash deploy/preflight.sh
```
- Performs read-only inspections.
- Never modifies the database or starts services unexpectedly.
- Exits 0 if all critical prerequisites pass; exits 1 with actionable remediation guidance if any check fails.

#### 2. Dry-Run Deployment Plan (Default)
```bash
bash deploy/deploy-staging.sh
```
- Validates preflight prerequisites.
- Confirms environment target (`APP_ENV=staging`, `APP_DEBUG=false`, non-production database).
- Displays the complete step-by-step execution plan without altering any disk or database state.

#### 3. Real Staging Deployment Execution (`--apply`)
```bash
bash deploy/deploy-staging.sh --apply
```
- Installs locked PHP dependencies: `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`.
- Prepares Flutter Web release bundle using `--dart-define=APP_ENV=staging --dart-define=API_BASE_URL=https://staging-api.sewaroeng.id/api/v1` (or uses existing bundle via `--skip-frontend-build`).
- Creates pre-migration binary backup: `pg_dump -Fc sewaroeng_staging > /var/backups/sewaroeng/pre_migrate_...dump`.
- Executes forward-only database schema migrations: `php artisan migrate --force`.
- Caches Laravel configuration, routes, views, and events.
- Dispatches graceful worker restart signal: `php artisan queue:restart` (and restarts systemd worker service).
- Verifies post-deployment operational health via internal PDO/Redis probe and HTTP `/up`.

---

### 9.3 Database Role & Extension Provisioning (PostgreSQL 16)
PostgreSQL extensions are installed **per-database**. The `btree_gist` extension is required by catalog and recipe migrations for exclusion constraints (`EXCLUDE USING gist (...)`). It must be created directly in the application database (`sewaroeng_staging`), not merely in the default `postgres` database.

```bash
# 1. Execute setup-db-staging.sql as PostgreSQL superuser (creates ONLY sewaroeng_staging):
sudo -u postgres psql -f /var/www/sewaroeng/scripts/setup-db-staging.sql

# 2. Or verify sewaroeng_staging directly:
sudo -u postgres psql -c "SELECT 'CREATE DATABASE sewaroeng_staging OWNER sewaroeng_app' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sewaroeng_staging')\gexec"
sudo -u postgres psql -d sewaroeng_staging -c "CREATE EXTENSION IF NOT EXISTS btree_gist;"
sudo -u postgres psql -d sewaroeng_staging -c "GRANT ALL ON SCHEMA public TO sewaroeng_app;"

# 3. Verify extension availability in sewaroeng_staging:
sudo -u postgres psql -d sewaroeng_staging -c "SELECT extname, extversion FROM pg_extension WHERE extname = 'btree_gist';"
# Expected: btree_gist | <version>
```

> **Security Invariant:** The application role (`sewaroeng_app`) is strictly configured with `NOSUPERUSER NOBYPASSRLS` to preserve PostgreSQL Row-Level Security (RLS) tenant isolation.

---

### 9.4 Systemd Process Supervision Activation

```bash
# 1. Install Queue Worker service
sudo cp deploy/systemd/sewaroeng-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sewaroeng-worker.service

# 2. Install Scheduler Timer & Service
sudo cp deploy/systemd/sewaroeng-scheduler.service /etc/systemd/system/
sudo cp deploy/systemd/sewaroeng-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sewaroeng-scheduler.timer

# 3. Verify active timers & worker logs
systemctl list-timers --all | grep sewaroeng
sudo journalctl -u sewaroeng-worker.service -f
```

---

### 9.5 Web Server Configuration & 5-Phase TLS Bootstrap (Nginx)

Virtual host installation is performed via `deploy/render-nginx-config.sh`, ensuring that API and Flutter Web configurations have distinct destination filenames (`/etc/nginx/sites-available/<api-host>.conf` and `/etc/nginx/sites-available/<web-host>.conf`) and cannot overwrite each other:

```bash
# 1. Render and install HTTP bootstrap configuration (Port 80 with ACME passthrough):
sudo bash deploy/render-nginx-config.sh \
    --api-host <staging-api-host> \
    --web-host <staging-web-host> \
    --install \
    --reload

# 2. Verify enabled symlinks point to expected targets:
test -L /etc/nginx/sites-enabled/<staging-api-host>.conf && \
test -L /etc/nginx/sites-enabled/<staging-web-host>.conf && \
sudo nginx -t

# 3. Verify public DNS resolution & HTTP reachability:
dig +short <staging-api-host>
dig +short <staging-web-host>
curl -I http://<staging-api-host>/.well-known/acme-challenge/test
curl -I http://<staging-web-host>/.well-known/acme-challenge/test

# 4. Issue Let's Encrypt certificates via Certbot webroot:
sudo certbot certonly --webroot -w /var/www/sewaroeng/backend/public -d <staging-api-host>
sudo certbot certonly --webroot -w /var/www/sewaroeng/frontend/build/web -d <staging-web-host>

# 5. Activate TLS in virtual hosts, test syntax, and reload:
sudo nginx -t && sudo systemctl reload nginx
```

---

### 9.6 Incident Rollback Protocol
If a staging release introduces regressions:
1. **Redeploy Prior Code Version**:
   ```bash
   cd /var/www/sewaroeng
   git checkout <previous-stable-tag-or-commit>
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
2. **Restore Pre-Migration Database Dump**:
   ```bash
   pg_restore -U sewaroeng_app -h 127.0.0.1 -d sewaroeng_staging --clean --if-exists /var/backups/sewaroeng/pre_migrate_<dump_file>.dump
   ```
3. **Restart Workers Gracefully**:
   ```bash
   php artisan queue:restart
   sudo systemctl restart sewaroeng-worker.service
   ```
4. **Dead-Letter Handling Rule**: Never use `php artisan queue:flush`. Use `php artisan queue:failed` to triage records, verify external provider dashboards before retrying, and remove handled items with `php artisan queue:forget <uuid>`.

---

### 9.7 Outstanding External Blockers Before Live Remote Staging

| Blocker | Category | Required Remediation |
|---|---|---|
| **Remote Staging Host & Public DNS** | Infrastructure | Provision Ubuntu 22.04+ VPS with domain DNS A records pointing to staging IP once staging hostnames are selected. |
| **Public SSL Certificates** | Security | Run Certbot webroot on host for the selected staging hostnames once public DNS resolves. |
| **Third-Party Sandbox Credentials** | Integration | Obtain live sandbox credentials from Midtrans MAP, Duitku Merchant Portal, and Meta Developer Portal to populate staging `.env`. |
| **Inbound Webhook Verification** | Integration | Register public webhook URLs on Meta, Midtrans, and Duitku developer dashboards once public DNS is live. |
| **GitHub Environment Secrets** | CI/CD | Configure `STAGING_SSH_HOST`, `STAGING_SSH_USER`, `STAGING_SSH_KEY`, and `STAGING_SSH_KNOWN_HOSTS` in GitHub repository Settings -> Environments -> `staging`. |

---

## 10. Cross-Origin, Authentication, and Domain Integration Architecture (Sprint 11)

### 10.1 Planned Hostnames and Origins
The planned production origins for SeWaroeng are:
- **Production Frontend**: `https://sewaroeng.neuracakrawira.asia`
- **Production API**: `https://api.sewaroeng.neuracakrawira.asia`

> **Domain Notice**: These are planned architectural hostnames, not verified public DNS records or provisioned remote servers. Staging hostnames remain unselected and must be explicitly configured prior to deployment.

### 10.2 Discovered Authentication Architecture
- **Stateless Bearer Token Authentication**: The application authenticates API requests exclusively via Laravel Sanctum Personal Access Tokens (`auth:sanctum`).
- **Token Lifecycle**:
  - The Flutter client logs in via `POST /api/v1/auth/login`.
  - The API returns a plaintext token string (`data.token`).
  - The client persists the token securely using `FlutterSecureStorage` (Keychain on iOS/macOS, EncryptedSharedPreferences on Android, and Web Crypto on Web).
  - Subsequent requests pass the token in the standard HTTP header: `Authorization: Bearer <token>`.
  - On `POST /api/v1/auth/logout`, the active Personal Access Token record is deleted from the database.
- **Session/Cookie Boundaries**:
  - The API **does not** rely on cookie-based SPA session authentication or CSRF tokens for API operations.
  - To prevent cross-site request forgery and avoid credentialed wildcard vulnerabilities, CORS configuration sets `supports_credentials => false`.

### 10.3 Environment-Driven CORS Architecture (`config/cors.php`)
Cross-Origin Resource Sharing is enforced via `Illuminate\Http\Middleware\HandleCors` using environment-driven rules:
- **Production (`APP_ENV=production`)**: Permits the planned frontend origin `https://sewaroeng.neuracakrawira.asia` by default, plus any explicit origins configured in `CORS_ALLOWED_ORIGINS`.
- **Staging (`APP_ENV=staging`)**: **Fails closed**. Staging origins are never guessed or defaulted. All permitted staging origins must be explicitly specified in `CORS_ALLOWED_ORIGINS` (comma-separated). If unset, no origins are permitted.
- **Local Development (`APP_ENV=local` / `APP_ENV=testing`)**: Permits `localhost` and `127.0.0.1` on standard ports (3000, 8000, 8080) and regex port patterns (`#^http://localhost:\d+$#`) to facilitate Flutter Web ephemeral development ports.
- **Allowed Methods**: `['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']`
- **Allowed Headers**:
  - `Accept`
  - `Authorization`
  - `Content-Type`
  - `Idempotency-Key`
  - `X-Tenant-ID`
  - `X-Request-ID`
  - `X-Requested-With`
- **Exposed Headers**: `['X-Request-ID']`
- **Preflight Cache (`max_age`)**: `86400` seconds (24 hours).
- **Credentials (`supports_credentials`)**: `false` (Bearer token architecture).
- **Webhook Exemption**: Server-to-server webhook endpoints (`/api/v1/webhooks/*`) invoked by external payment/messaging gateways do not supply a browser `Origin` header and are processed directly without CORS restrictions.

### 10.4 Preflight and Error Handling Invariants
1. **Preflight `OPTIONS` Requests**: Handled by `HandleCors` middleware before route matching, returning HTTP 200/204 with allowed methods, headers, and max-age for approved origins without requiring authentication.
2. **Error Responses on Protected Endpoints**: When an unauthenticated or invalid token request reaches a protected route (`/api/v1/tenants`, etc.), the API returns HTTP 401 (`UNAUTHENTICATED`) along with CORS response headers for approved origins. This ensures web browsers surface the structured JSON error rather than masking it with a generic `TypeError: Failed to fetch` CORS network failure.
3. **Token Leakage Prevention**: Invalid tokens or authentication failures never echo the provided token in the response payload or error body.

### 10.5 Flutter Build Commands

#### Production Web Build Command
```bash
flutter build web --release \
  --dart-define=APP_ENV=production \
  --dart-define=API_BASE_URL=https://api.sewaroeng.neuracakrawira.asia/api/v1
```

#### Staging Web Build Command (Explicit Hostname Required)
```bash
flutter build web --release \
  --dart-define=APP_ENV=staging \
  --dart-define=API_BASE_URL=https://<staging-api-host>/api/v1
```

### 10.6 Focused Validation Summary (Sprint 11)
- **Backend CORS & Authentication Tests (`php artisan test`)**:
  - `Tests\Feature\CorsIntegrationTest`: 9 passed, 37 assertions.
  - `Tests\Feature\AuthenticationTest`: 6 passed, 21 assertions.
  - `Tests\Feature\AuthorizationTest`: 5 passed, 15 assertions.
  - `Tests\Feature\HealthAndReadinessTest`: 2 passed, 8 assertions.
  - `Tests\Feature\IdentityAndAuthenticationTest`: 9 passed, 50 assertions.
  - **Total**: 31 passed, 131 assertions.
- **Backend Code Style (`./vendor/bin/pint --test`)**: Passed (0 violations).
- **Frontend URL & Header Tests (`flutter test`)**:
  - `test/core/app_config_test.dart`: 3 passed.
  - `test/core/api_client_url_test.dart`: 8 passed (URL normalization, header injection, 401 callback).
  - `test/core/auth_service_test.dart`: 7 passed (Session lifecycle, storage, revocation).
  - **Total**: 18 passed.
- **Frontend Static Analysis (`flutter analyze lib test`)**: Passed (0 issues).

---

## 11. Final Pre-Staging Readiness Decision & Safety Gate Matrix (Sprint 15)

### 11.1 Verification Boundary & Readiness Breakdown

The table below delineates what has been conclusively verified through offline local automation versus prerequisites that remain unverified pending remote infrastructure provisioning:

| Subsystem / Layer | Verification Scope | Status | Evidence & Verification Boundary |
|---|---|:---:|---|
| **Application Code & Logic** | Local Engine | `VERIFIED` | Laravel 13 code, RLS tenant isolati| **CLI Deployment Pipeline** | Local Automation | `VERIFIED` | 36 automated CLI safety regression tests passed (`deploy/tests/test_cli_safety_regression.sh`). All bypass flags, mode conflicts, fixture leaks, syntax anomalies, and command injections blocked. |
| **Configuration Parser Safety** | Local Engine | `VERIFIED` | `.env` parser reads key-value pairs without shell execution (`eval`/`source`). 14/14 automated parity tests with Laravel `vlucas/phpdotenv` passed (`deploy/tests/run_parity_check.php`). |
| **Side-Effect Containment** | Local Test Harness | `VERIFIED` | Spy stubs covering 18 commands confirm zero mutating calls (`composer`, `flutter`, `pg_dump`, `psql`, `redis-cli`, `ssh`, `systemctl`, `sudo`) occurred during dry-run simulations and rejected tests. |
| **Hosting: VPS Nginx (Profile B)** | Target Server | `UNVERIFIED` | Templates rendered cleanly, but live Nginx syntax (`nginx -t`) and systemd unit verification (`systemd-analyze verify`) require the provisioned Ubuntu target host. |
| **Hosting: cPanel Apache (Profile A)** | Target Server | `UNVERIFIED` | Document root split and `.htaccess` rules documented in `docs/deployment/CPANEL_APACHE.md`, but module availability (`mod_rewrite`, `mod_proxy_fcgi`) requires live cPanel testing. |
| **Hosting: Hybrid Managed (Profile C)** | Target Provider | `UNVERIFIED` | External managed PostgreSQL & Redis connectivity from cPanel shared hosting depends on provider firewall policies (outbound port 5432/6379). |
| **Public DNS Resolution** | Remote Network | `UNPROVISIONED` | Staging hostnames remain unselected; no public DNS A records exist. |
| **TLS Certificates** | Remote Network | `UNPROVISIONED` | ACME Let's Encrypt HTTP-01 challenge cannot run until DNS points to active server. |
| **Remote Database & Redis** | Remote Host | `UNPROVISIONED` | Target PostgreSQL 16 database (`sewaroeng_staging`) and Redis instance not yet created on remote host. |
| **Remote Staging Deployment** | Live System | `NOT DEPLOYED` | Remote deployment has NOT been executed. Staging remains offline and safe. |

### 11.2 Operational Pre-Deployment Sequence (When Server Is Provisioned)

When target infrastructure becomes available, execute the following sequence:

1. **DNS & Host Provisioning**:
   - Provision Ubuntu 22.04+ LTS VPS (or cPanel account).
   - Point DNS A records for explicitly chosen staging API and Web hostnames to the server IP.
2. **Database Provisioning**:
   - Run `sudo -u postgres psql -f scripts/setup-db-staging.sql` to initialize `sewaroeng_staging` and grant least-privilege role `sewaroeng_app`.
3. **Environment Setup**:
   - Populate `/var/www/sewaroeng/backend/.env` with authentic staging credentials (`APP_ENV=staging`, `APP_DEBUG=false`, `CORS_ALLOWED_ORIGINS=https://<staging-web>`).
4. **Strict Preflight Execution**:
   - Run `bash deploy/preflight.sh --strict`. Confirm status is `FULL_PREFLIGHT_PASSED`.
5. **Guarded Deployment**:
   - Run `bash deploy/deploy-staging.sh --apply --api-host <staging-api> --web-host <staging-web>`.

---

## 12. Environment Parser Parity & Staging Operational Handoff (Sprint 16)

### 12.1 Environment Parser Parity: Bash (`get_env_val`) vs Laravel (`vlucas/phpdotenv`)

In Sprint 16, a systematic verification of configuration reading consistency was conducted between deployment shell scripts (`deploy/deploy-staging.sh`, `deploy/preflight.sh`) and Laravel's actual environment loader (`vlucas/phpdotenv` v5.6+).

Deployment scripts evaluate configuration through pure bash text parsing without using `eval` or `source`. Automated parity testing in [`deploy/tests/run_parity_check.php`](file:///d:/A/SeWaroeng/deploy/tests/run_parity_check.php) compared parsed values between bash `get_env_val()` and PHP `Dotenv\Dotenv::parse()`.

#### Test Matrix and Parity Results (14/14 Passed)

| Test Case | Scenario / Configuration Example | Laravel Dotenv Interpretation | Deployment Parser Interpretation | Parity Status |
|:---:|---|---|---|:---:|
| 01 | Double-quoted string with spaces: `APP_NAME="SeWaroeng Cafe & Resto"` | `SeWaroeng Cafe & Resto` | `SeWaroeng Cafe & Resto` | **EXACT MATCH** |
| 02 | Single-quoted string: `APP_ENV='staging'` | `staging` | `staging` | **EXACT MATCH** |
| 03 | Hash (`#`) inside quoted string: `APP_KEY="base64:...=#123"` | `base64:...=#123` | `base64:...=#123` | **EXACT MATCH** |
| 04 | Multiple hashes inside quotes: `SECRET_TOKEN='secret#with#hashes'` | `secret#with#hashes` | `secret#with#hashes` | **EXACT MATCH** |
| 05 | Unquoted inline comment: `APP_DEBUG=false # comment` | `false` | `false` | **EXACT MATCH** |
| 06 | Unquoted URL: `APP_URL=https://staging-api.internal` | `https://staging-api.internal` | `https://staging-api.internal` | **EXACT MATCH** |
| 07 | Unquoted hash without leading space: `ATTACHED_HASH=redis#comment` | `redis` | `redis` | **EXACT MATCH** |
| 08 | Empty variable value: `EMPTY_VAR=` | `""` (empty string) | `""` (empty string) | **EXACT MATCH** |
| 09 | Numeric integer port: `DB_PORT=5432` | `5432` | `5432` | **EXACT MATCH** |
| 10 | Identifier string: `DB_DATABASE=sewaroeng_staging` | `sewaroeng_staging` | `sewaroeng_staging` | **EXACT MATCH** |
| 11 | Duplicate key resolution: `DUPLICATE_KEY=first` then `DUPLICATE_KEY=second` | `second` (last wins) | `second` (`tail -n 1`) | **EXACT MATCH** |
| 12 | Unquoted value with spaces: `APP_NAME=SeWaroeng Cafe` | Throws `InvalidFileException` | Syntax check fails closed | **EXACT MATCH** |
| 13 | Unclosed quotes: `APP_NAME="SeWaroeng` | Swallows subsequent lines | Syntax check fails closed | **EXACT MATCH** |
| 14 | Subshell command substitution: `INJECTION=$(touch /tmp/flag)` | Literal text `$(touch ...)` | Neutralized (no execution) | **EXACT MATCH** |

### 12.2 Proven Discrepancies and Enforced Boundary Rules

1. **Unquoted Whitespace Discrepancy (Resolved via Fail-Closed Syntax Validation)**:
   - *Behavior in Laravel*: `vlucas/phpdotenv` strictly forbids unquoted whitespace. A line like `APP_NAME=SeWaroeng Cafe` throws `Dotenv\Exception\InvalidFileException: Encountered unexpected whitespace at [Cafe]`.
   - *Discrepancy*: Naive bash parsers might silently capture `SeWaroeng Cafe` or truncate it to `SeWaroeng`. If deployment scripts accepted this, deployment would succeed but Laravel would immediately crash upon application boot.
   - *Enforced Boundary*: `validate_env_file_syntax` in `deploy/deploy-staging.sh` and `deploy/preflight.sh` now validates every value. If a value contains whitespace without surrounding single or double quotes, the script immediately aborts with:
     ```
     Syntax Error in .env line: Unquoted value contains whitespace. Values with spaces must be enclosed in quotes (e.g. KEY="value with spaces").
     ```

2. **Unclosed Quotes and Multiline Variable Swallowing (Resolved)**:
   - *Behavior in Laravel*: An unclosed quote (e.g., `APP_NAME="SeWaroeng Cafe\nAPP_ENV=staging`) causes `phpdotenv` to treat subsequent lines as part of the multiline string value, silently swallowing critical deployment guards like `APP_ENV`.
   - *Enforced Boundary*: Deployment syntax validation requires matching opening and closing quotes on each line. Unclosed single or double quotes are rejected immediately prior to reading variables.

3. **Variable Interpolation (`${VAR}`) Boundary**:
   - *Behavior in Laravel*: `phpdotenv` supports nested variable expansion (e.g., `MAIL_FROM_NAME="${APP_NAME}"`).
   - *Enforced Boundary*: For application-level configuration, Laravel expands nested variables during bootstrap. However, deployment guard variables (`APP_ENV`, `DB_DATABASE`, `APP_URL`, `DB_PORT`) must be deterministic, literal strings. Interpolation expressions in deployment guard values are intentionally disallowed in preflight to ensure fail-closed security.

4. **Command Execution Neutralization**:
   - The deployment parser never uses `eval`, `source`, or `.`. Any command substitutions (`$(...)`, `` `...` ``) in `.env` are treated strictly as string literals and never executed as shell processes.

---

### 12.3 Staging Operational Handoff Checklist

To transition from local readiness to staging deployment safely, operational tasks are categorized into four distinct sequential tiers:

```
[Tier 1: Locally Verified] ────► [Tier 2: Owner Decisions] ────► [Tier 3: Post-Provisioning] ────► [Tier 4: Guarded Execution]
(Complete & Rehearsed)          (Required from Project Owner)   (After Server Online)           (Live Deployment Only)
```

#### Tier 1: Prasyarat yang Sudah Diuji Lokal (Locally Verified Prerequisites)
*These checks have been completely automated, verified offline, and require no further code changes:*
- [x] **Backend Application Code**: Laravel 13 running on PHP 8.4, strict PostgreSQL 16+ RLS policies, stateless Sanctum Bearer tokens, zero token leakage on 401.
- [x] **Frontend Web Build**: Flutter Web build reproducibility verified with `--dart-define=APP_ENV=staging`; build artifact contains zero server secrets or database passwords.
- [x] **Database Migration & Reversibility**: Reversible migration suite (38 migrations) with symmetric `down()` methods and safe rollback rehearsal.
- [x] **Environment Parser Parity**: Bash `get_env_val()` and syntax guards exhibit 100% parity with Laravel's `vlucas/phpdotenv` (14/14 parity tests passed).
- [x] **CLI Guard & Safety Regression**: 36 CLI safety tests passed (`deploy/tests/test_cli_safety_regression.sh`). All dry-run bypass flags, fixture leaks, duplicate options, and command injections blocked.
- [x] **Side-Effect Containment**: 18 spy stubs prove zero calls to mutating commands (`composer`, `flutter`, `pg_dump`, `psql`, `redis-cli`, `ssh`, `systemctl`, `sudo`) during local simulation.
- [x] **Deployment Shell Scripts Syntax**: All scripts in `deploy/` pass `bash -n` validation with 0 syntax errors.

#### Tier 2: Prasyarat yang Membutuhkan Keputusan atau Akses Pemilik Proyek (Owner Decisions & Access)
*These items require explicit inputs, decisions, or credential provisioning from the project owner before staging can begin:*
- [ ] **Staging Domain Selection**:
  - The project owner must explicitly designate staging hostnames (e.g., `staging-api.sewaroeng.example.com` and `staging.sewaroeng.example.com`).
  - *Hard Invariant*: Planned production domains (`api.sewaroeng.neuracakrawira.asia` and `sewaroeng.neuracakrawira.asia`) must NEVER be used for staging.
- [ ] **Hosting Architecture Profile Selection**:
  - Decision between **Profile B** (Ubuntu 22.04+ VPS with Nginx and systemd), **Profile A** (cPanel Apache shared hosting), or **Profile C** (Hybrid managed).
- [ ] **Server Infrastructure Access**:
  - SSH key access with non-root sudo privileges (for VPS) or cPanel credentials (for shared hosting).
- [ ] **Sandbox Integration Credentials**:
  - Meta Cloud WhatsApp sandbox System User Token and test Phone Number ID.
  - Midtrans MAP Sandbox Server Key and Client Key.
  - Duitku Sandbox Merchant Code and API Key.
  - SMTP / Mailgun staging credentials.

#### Tier 3: Pemeriksaan yang Baru Dapat Dilakukan Setelah Server Tersedia (Post-Provisioning Checks)
*These checks can ONLY be executed after the remote host has been provisioned and reached:*
- [ ] **OS and Runtime Validation**:
  - Verify target OS is Ubuntu 22.04+ LTS (or cPanel Linux kernel).
  - Verify PHP 8.4 CLI and PHP 8.4-FPM are installed with extensions: `pdo_pgsql`, `pgsql`, `redis`, `sodium`, `bcmath`, `intl`, `mbstring`, `xml`, `curl`, `zip`.
- [ ] **Database & Cache Instance Availability**:
  - Confirm PostgreSQL 16+ is running and listening on localhost or private network.
  - Run `scripts/setup-db-staging.sql` to initialize database `sewaroeng_staging` and dedicated role `sewaroeng_app`.
  - Confirm database user `sewaroeng_app` has `NOSUPERUSER NOBYPASSRLS`.
  - Confirm Redis 7+ is running with password authentication and `maxmemory-policy noeviction`.
- [ ] **Public DNS Resolution**:
  - Confirm DNS A records for the selected staging hostnames resolve to the server's public IP (`dig +short <staging-api-host>`).
- [ ] **TLS Certificate Issuance**:
  - Run Certbot ACME HTTP-01 challenge for both staging domains.
- [ ] **Live Strict Preflight Verification**:
  - Execute:
    ```bash
    bash deploy/preflight.sh --strict
    ```
  - Verify output displays `OVERALL STATUS: FULL_PREFLIGHT_PASSED` with 0 errors.

#### Tier 4: Langkah yang Baru Boleh Dijalankan Setelah Target Staging Dipastikan Benar (Post-Confirmation Steps)
*Only execute these commands once Tiers 1, 2, and 3 are completely verified and confirmed:*
- [ ] **Step 1: Deploy Staging `.env` Configuration**:
  - Place `.env` at `/var/www/sewaroeng/backend/.env` with `APP_ENV=staging`, `APP_DEBUG=false`, real staging secrets, and `CORS_ALLOWED_ORIGINS=https://<staging-web-host>`.
- [ ] **Step 2: Run Guarded Deployment Pipeline**:
  - Execute:
    ```bash
    bash deploy/deploy-staging.sh --apply \
      --api-host <staging-api-host> \
      --web-host <staging-web-host>
    ```
- [ ] **Step 3: Verify Liveness and Readiness Probes**:
  - Check process liveness: `curl -fsS https://<staging-api-host>/up` (must return HTTP 200).
  - Check deep dependency readiness: `curl -fsS https://<staging-api-host>/api/v1/health` (must return HTTP 200 with `status: ok`).
- [ ] **Step 4: Supervise Background Workers & Schedulers**:
  - Start systemd units:
    ```bash
    sudo systemctl enable --now sewaroeng-worker.service sewaroeng-scheduler.timer
    sudo systemctl status sewaroeng-worker.service sewaroeng-scheduler.timer
    ```
- [ ] **Step 5: Verify Web Application in Browser**:
  - Access `https://<staging-web-host>` in a clean browser session.
  - Verify preflight `OPTIONS` requests succeed, CORS headers return properly, and login/auth flows operate against the staging API.

---

### 12.4 Planned Production Domains vs Staging Domains Reminder

| Environment | Purpose | Target API Host | Target Web Host | Status |
|---|---|---|---|:---:|
| **Production (Planned)** | Live Production Service | `api.sewaroeng.neuracakrawira.asia` | `sewaroeng.neuracakrawira.asia` | `PLANNED / NOT DEPLOYED` |
| **Staging** | Pre-Production Testing | *To be explicitly decided by owner* | *To be explicitly decided by owner* | `NOT DEPLOYED` |

> [!CAUTION]
> **Zero Guessing Policy for Staging Hostnames**: Deployment scripts will strictly abort if either planned production domain is passed to `--api-host` or `--web-host` during a staging deployment. Staging hostnames must be explicitly supplied by the project owner.
