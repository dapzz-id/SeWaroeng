# SeWaroeng — Production Deployment Guide: cPanel (Apache)

**Document Status:** Production Operational Runbook (Sprint 17)  
**Target Architecture:** Production on cPanel Shared / Managed Hosting (Apache 2.4, PHP 8.4-FPM, Split-Root)  
**Future Target:** Dedicated VPS (Ubuntu 22.04+ LTS, Nginx, Systemd)  
**Security Classification:** Strict Defense-in-Depth (PostgreSQL 16+ RLS Enforced, Stateless Bearer Tokens, Zero-Credential Exposure)  
**Current Status:** **`NOT DEPLOYED`** (All remote servers, DNS, TLS, and databases are unprovisioned)  

---

## 1. Executive Summary & Project Decision

### 1.1 Project Decision Context
The project owner has decided to **launch directly to production** via cPanel Apache for the initial deployment because the application currently has no active users. The intermediate staging environment requirement is bypassed for this initial release; however, all staging safety guards and regression suites are strictly maintained to prevent accidental cross-environment pollution.

### 1.2 Planned Production Domains
The following canonical production domains are planned for the project:

- **Frontend Web Application:** `https://sewaroeng.neuracakrawira.asia`
- **Backend API & Webhooks:** `https://api.sewaroeng.neuracakrawira.asia`

> [!CAUTION]
> **Important Operating Invariant**: These production domains are **planned targets only**. They are NOT yet confirmed active on the cPanel hosting server. Do NOT assume that DNS records, TLS certificates, database clusters, or external provider webhooks are provisioned.

---

## 2. Infrastructure Prerequisites & Hosting Compatibility Matrix

SeWaroeng is built with an **API-first, framework-decoupled architecture**. Below is the compatibility matrix comparing standard cPanel hosting with the application's actual dependencies:

| Component | Minimum Requirement | Standard Shared cPanel Reality | Status / Required Architecture |
|---|---|---|:---:|
| **Web Server** | Apache 2.4+ with `mod_rewrite`, `mod_headers`, `mod_proxy_fcgi` | Standard on almost all cPanel providers | **COMPATIBLE** (via `.htaccess`) |
| **PHP Runtime** | PHP >= 8.4.0 (CLI & FPM) | Available via cPanel "MultiPHP Manager" / "Select PHP Version" | **VERIFIED** (Requires PHP 8.4 selection) |
| **PHP Extensions** | `pdo_pgsql`, `pgsql`, `mbstring`, `xml`, `ctype`, `iconv`, `intl`, `bcmath`, `curl`, `zip` (`redis` optional) | Usually selectable in cPanel PHP Extensions / Extensions list | **VERIFIED** (Must enable `pdo_pgsql` and `pgsql`; `redis` optional) |
| **Composer** | Composer 2.x | Available in cPanel Terminal / SSH | **COMPATIBLE** (or vendor pre-packaged) |
| **Database Engine** | **PostgreSQL 16+ with `btree_gist` and `NOSUPERUSER NOBYPASSRLS` role** | **RARELY PROVIDED** (Standard shared cPanel offers MySQL only) | 🚨 **MANAGED POSTGRESQL REQUIRED** |
| **Cache & Distributed Mutex** | **Database Cache (`CACHE_STORE=database`)** with `cache` & `cache_locks` | Uses same PostgreSQL database; no Redis needed | **NATIVELY SUPPORTED** |
| **Queue Worker** | **Database Queue (`QUEUE_CONNECTION=database`)** with `jobs` & `failed_jobs` | Uses same PostgreSQL database; no Redis needed | **NATIVELY SUPPORTED** (Cron-driven worker with `--stop-when-empty`) |
| **Scheduler** | Cron runner executing every minute (`schedule:run`) | Native cPanel "Cron Jobs" interface | **COMPATIBLE** (Cron every minute) |
| **TLS / SSL** | Automated TLS 1.3 certificate | cPanel "AutoSSL" (Let's Encrypt / Sectigo) | **COMPATIBLE** (1-click issuance) |

### 2.1 The PostgreSQL 16+ Requirement (Managed Cloud Database) & Zero Redis Dependency
- **Non-Negotiable Architecture Invariant**: Multi-tenant data isolation in SeWaroeng is enforced at the database engine level via PostgreSQL Row-Level Security (RLS) policies and `btree_gist` exclusion constraints. **The database engine cannot and will not be downgraded to MySQL, and RLS will never be disabled.**
- **PostgreSQL Hosting Requirement**: If your cPanel hosting package does not natively offer PostgreSQL 16+ with `btree_gist`, use an External Managed PostgreSQL 16+ service (e.g. Neon, Supabase, AWS RDS, DigitalOcean Managed Database) configured via `DB_HOST`, `DB_PORT=5432`, and SSL mode (`DB_SSLMODE=require`).
- **No Redis Dependency (cPanel Profile)**: SeWaroeng uses database-backed cache (`CACHE_STORE=database`), database-backed queue (`QUEUE_CONNECTION=database`), and database-backed sessions (`SESSION_DRIVER=database`). All cache storage, distributed lock mutexes (`cache_locks`), and background queues are handled directly by PostgreSQL. Local or remote Redis is strictly optional.

---

## 3. Dual Domain & Secure Split-Root Architecture

### 3.1 Document Root Allocation
In cPanel (**cPanel -> Domains -> Domains**), allocate two distinct domains with completely separate document roots:

| Domain / FQDN | Role | Document Root Path | Content |
|---|---|---|---|
| `sewaroeng.neuracakrawira.asia` | Frontend Web Client | `/home/username/sewaroeng_web` | Flutter Web static release bundle |
| `api.sewaroeng.neuracakrawira.asia` | Backend API Service | `/home/username/sewaroeng_api_public` | Public entry point (`index.php`, `.htaccess`) |

### 3.2 Secure Split-Root Directory Structure
Application source code, `.env`, `vendor/`, `storage/`, and database migration files must **never be placed inside a web-accessible document root**.

```
/home/username/
├── sewaroeng_core/                        <-- PRIVATE DIRECTORY (NOT accessible via HTTP)
│   ├── app/                               <-- Core domain logic & controllers
│   ├── bootstrap/
│   │   ├── app.php                        <-- Application configuration
│   │   └── cache/                         <-- Writable cache (chmod 775)
│   ├── config/                            <-- Application configuration
│   ├── database/                          <-- Migrations & seeders
│   ├── routes/                            <-- API and console routes
│   ├── storage/                           <-- Logs, framework cache, sessions (chmod 775)
│   │   ├── app/
│   │   ├── framework/
│   │   └── logs/
│   ├── vendor/                            <-- Composer dependencies
│   ├── artisan                            <-- Laravel CLI entry point
│   ├── composer.json
│   └── .env                               <-- CHMOD 600 (Strictly protected production secrets)
│
├── sewaroeng_api_public/                  <-- PUBLIC ROOT for api.sewaroeng.neuracakrawira.asia
│   ├── .htaccess                          <-- Apache rewrite & Authorization header rules
│   ├── index.php                          <-- Modified bootstrap loader
│   ├── robots.txt                         <-- Disallow crawling on API
│   └── favicon.ico
│
└── sewaroeng_web/                         <-- PUBLIC ROOT for sewaroeng.neuracakrawira.asia
    ├── .htaccess                          <-- Apache SPA rewrite & caching rules
    ├── index.html                         <-- Flutter Web entry point (no-cache)
    ├── main.dart.js                       <-- Compiled application logic
    ├── flutter.js                         <-- Flutter engine loader
    ├── version.json                       <-- Build version (no-cache)
    ├── assets/                            <-- Static fonts, icons, images (immutable cache)
    └── canvaskit/                         <-- WebAssembly engine (immutable cache)
```

---

## 4. Bootstrap Adjustment (`public/index.php`)

When the public document root (`sewaroeng_api_public`) is separated from the application core (`sewaroeng_core`), the front controller must be updated to load the autoloader and application from the private directory, and bind the public path.

### 4.1 Implementation for `/home/username/sewaroeng_api_public/index.php`
```php
<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// 1. Determine if the application is in maintenance mode from the private storage
if (file_exists($maintenance = __DIR__.'/../sewaroeng_core/storage/framework/maintenance.php')) {
    require $maintenance;
}

// 2. Register the Composer autoloader from the private core directory
require __DIR__.'/../sewaroeng_core/vendor/autoload.php';

// 3. Bootstrap Laravel application
/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../sewaroeng_core/bootstrap/app.php';

// 4. Bind the actual web public path to this directory
$app->usePublicPath(__DIR__);

// 5. Handle the incoming HTTP request
$app->handleRequest(Request::capture());
```

> [!NOTE]
> Calling `$app->usePublicPath(__DIR__)` ensures that helper functions such as `public_path()`, asset URL generators, and storage symbolic links resolve correctly to `/home/username/sewaroeng_api_public` rather than the private core folder.

---

## 5. Apache Configuration (`.htaccess`) & HTTPS

### 5.1 Backend API (`sewaroeng_api_public/.htaccess`)
Create the following `.htaccess` file inside `/home/username/sewaroeng_api_public/`:

```apache
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # 1. Force HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # 2. Preserve Authorization Header (CRITICAL for Sanctum Bearer Tokens)
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # 3. Preserve X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # 4. Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # 5. Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

# Security Headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

### 5.2 Frontend Flutter Web (`sewaroeng_web/.htaccess`)
Deploy the template from `deploy/apache/web.htaccess` into `/home/username/sewaroeng_web/`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # 1. Force HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # 2. Client-Side SPA Fallback to index.html
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.html [L]
</IfModule>

# Cache-Control Headers for Flutter Web
<IfModule mod_headers.c>
    # Never cache dynamic entry files
    <FilesMatch "^(index\.html|version\.json|flutter_service_worker\.js)$">
        Header set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"
        Header set Pragma "no-cache"
        Header set Expires "0"
    </FilesMatch>

    # Cache immutable fingerprinted assets for 1 year
    <FilesMatch "\.(js|wasm|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
```

---

## 6. Production Configuration Contracts

### 6.1 Backend Production `.env` Contract
Populate `/home/username/sewaroeng_core/.env` with production values:

```env
APP_NAME=SeWaroeng
APP_ENV=production
APP_KEY=base64:GENERATE_VIA_PHP_ARTISAN_KEY_GENERATE
APP_DEBUG=false
APP_URL=https://api.sewaroeng.neuracakrawira.asia

# CORS: Permits the planned production frontend Web origin
CORS_ALLOWED_ORIGINS=https://sewaroeng.neuracakrawira.asia

APP_LOCALE=id
APP_FALLBACK_LOCALE=en

BCRYPT_ROUNDS=12

LOG_CHANNEL=daily
LOG_LEVEL=warning

# Database Configuration (PostgreSQL 16+ Mandatory)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1                     # Or external managed DB host (e.g. ep-xyz.neon.tech)
DB_PORT=5432
DB_DATABASE=sewaroeng_production

# Runtime Application Role (Least-privilege DML: SELECT, INSERT, UPDATE, DELETE only)
# Used for HTTP requests, queue workers, and scheduler. MUST retain NOSUPERUSER NOBYPASSRLS.
DB_USERNAME=sewaroeng_prod_app
DB_PASSWORD=YOUR_HIGH_ENTROPY_PRODUCTION_DB_PASSWORD
DB_SSLMODE=prefer                     # Use 'require' if connecting to external cloud DB

# Schema Migrator Role (DDL identity: executes 'php artisan migrate' and pg_dump)
# Never exposed in runtime cached config or web front controller
DB_MIGRATOR_USERNAME=sewaroeng_prod_migrator
DB_MIGRATOR_PASSWORD=YOUR_HIGH_ENTROPY_MIGRATOR_PASSWORD

# cPanel Production Profile: PostgreSQL Database-Backed Cache, Queue, and Sessions
SESSION_DRIVER=database
SESSION_LIFETIME=120
QUEUE_CONNECTION=database
CACHE_STORE=database
CACHE_PREFIX=sewaroeng_prod_cache_

# Redis Configuration (Optional: only needed if using external Redis cluster)
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=YOUR_SMTP_USER
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@sewaroeng.neuracakrawira.asia"
MAIL_FROM_NAME="${APP_NAME}"

# Third-Party Integrations (Safe Inactive Defaults Until Confirmed Live)
MIDTRANS_SERVER_KEY=
MIDTRANS_CLIENT_KEY=
MIDTRANS_MERCHANT_ID=
MIDTRANS_IS_PRODUCTION=false

DUITKU_MERCHANT_CODE=
DUITKU_API_KEY=
DUITKU_IS_PRODUCTION=false

META_WHATSAPP_GRAPH_VERSION=v21.0
META_WHATSAPP_APP_SECRET=
META_WHATSAPP_VERIFY_TOKEN=
```

### 6.2 Frontend Flutter Web Production Build Command
Compile Flutter Web on the local release workstation (or CI/CD runner):

```bash
flutter build web --release \
  --dart-define=APP_ENV=production \
  --dart-define=API_BASE_URL=https://api.sewaroeng.neuracakrawira.asia/api/v1
```

> [!IMPORTANT]
> The resulting `frontend/build/web` directory contains zero server secrets, database passwords, or provider API keys. Upload the contents of this folder directly to `/home/username/sewaroeng_web/`.

---

## 7. PostgreSQL 16+ Production Database & Role Privilege Architecture

### 7.1 Three-Tier Privilege Architecture
To guarantee tenant Row-Level Security (RLS) enforcement and protect database schema integrity, SeWaroeng enforces a strict three-tier privilege model:

```
[Tier 1: Cloud Admin / DBA] ──► [Tier 2: Schema Migrator] ──► [Tier 3: Runtime Application]
  (Console / Superuser)          (sewaroeng_prod_migrator)       (sewaroeng_prod_app)
  • Creates DB & Roles           • Schema & Table Owner          • DML Only (SELECT, INSERT, UPDATE, DELETE)
  • Installs btree_gist          • DDL (CREATE/ALTER TABLE)      • NOSUPERUSER NOBYPASSRLS
  • Configures Default Grants    • Sets FORCE ROW LEVEL SECURITY • Constrained by app.current_tenant_id
```

#### Tier 1: Cloud Provider / Superuser Provisioning (DBA / Cloud Console)
- **Role**: Cloud account administrator or cloud console (e.g. `postgres`, `doadmin`).
- **Scope**: Executed **outside** application code.
- **Tasks**:
  1. Create database `sewaroeng_production` (via cloud console or SQL).
  2. Install trusted extension: `CREATE EXTENSION IF NOT EXISTS btree_gist;`.
  3. Create roles `sewaroeng_prod_migrator` (DDL) and `sewaroeng_prod_app` (DML).
  4. Grant schema ownership and default privileges to runtime role.

#### Tier 2: Schema Migrator Role (`sewaroeng_prod_migrator`)
- **Credentials**: Stored in release automation or passed via `DB_MIGRATOR_PASSWORD` / `--migrator-password`.
- **Privileges**:
  - Owner of schema `public` and all application tables.
  - Has DDL rights: `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, `CREATE INDEX`, `CREATE POLICY`.
  - Configures `ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY`.
  - Grants default DML permissions to `sewaroeng_prod_app` via PostgreSQL `ALTER DEFAULT PRIVILEGES`.
- **Hard Guard**: Has `NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE`.
- **Pipeline Execution**: The deployment pipeline executes migrations using subshell variable overrides (`DB_USERNAME=sewaroeng_prod_migrator DB_PASSWORD=... php artisan migrate --force`), ensuring DDL executes under this identity, and immediately rebuilds production caches (`config:cache`) under the unprivileged runtime identity.

#### Tier 3: Runtime Application Role (`sewaroeng_prod_app`)
- **Credentials**: Configured in production `.env` (`DB_USERNAME=sewaroeng_prod_app`).
- **Privileges**:
  - DML only: `SELECT`, `INSERT`, `UPDATE`, `DELETE` on all application tables.
  - `USAGE`, `SELECT`, `UPDATE` on auto-increment sequences.
  - **Zero DDL privileges**: Cannot `CREATE`, `ALTER`, `DROP`, or `TRUNCATE` tables.
  - **Zero Bypass**: Retains `NOSUPERUSER NOBYPASSRLS`.
  - When querying, PostgreSQL strictly evaluates RLS policies against `app.current_tenant_id`. If tenant context is unset or mismatched, 0 rows are returned.
  - **Runtime Cache Isolation**: The runtime configuration cache (`bootstrap/cache/config.php`) only ever contains `sewaroeng_prod_app`. The migrator credentials are never cached or exposed to web requests.

### 7.2 Database Provisioning Script Reference
Execute [`scripts/setup-db-production.sql`](file:///d:/A/SeWaroeng/scripts/setup-db-production.sql) during the authorized provisioning window:

```sql
-- 1. Create Dedicated Roles
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'sewaroeng_prod_migrator') THEN
        CREATE ROLE sewaroeng_prod_migrator WITH LOGIN PASSWORD 'STRONG_MIGRATOR_SECRET'
            NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;

    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'sewaroeng_prod_app') THEN
        CREATE ROLE sewaroeng_prod_app WITH LOGIN PASSWORD 'STRONG_RUNTIME_SECRET'
            NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

-- 2. Configure Database & Extensions (inside sewaroeng_production)
\c sewaroeng_production
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- 3. Configure Schema Ownership & Default Grants
ALTER SCHEMA public OWNER TO sewaroeng_prod_migrator;
GRANT ALL ON SCHEMA public TO sewaroeng_prod_migrator;
GRANT USAGE ON SCHEMA public TO sewaroeng_prod_app;

ALTER DEFAULT PRIVILEGES FOR ROLE sewaroeng_prod_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO sewaroeng_prod_app;

ALTER DEFAULT PRIVILEGES FOR ROLE sewaroeng_prod_migrator IN SCHEMA public
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO sewaroeng_prod_app;
```

---

## 8. Queue Worker and Scheduler Implementation on cPanel

Shared cPanel hosts do not permit persistent background daemons like `systemd` or long-running `supervisord`. The worker and scheduler must be adapted to run through **cPanel Cron Jobs** (`cPanel -> Cron Jobs`).

### 8.1 Register Cron Jobs in cPanel

#### Cron 1: Laravel Scheduler (Mandatory)
Runs every minute to evaluate scheduled tasks (WhatsApp reminders, daily summary generation, outbox recovery):
```bash
* * * * * cd /home/username/sewaroeng_core && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

#### Cron 2: Queue Worker (Cron-Driven Worker with Clean Exit)
Runs every minute. The worker processes all available jobs across `default`, `notifications`, and `webhooks` queues and automatically exits when empty or after 50 seconds (before the next cron runs), preventing process accumulation:
```bash
* * * * * cd /home/username/sewaroeng_core && /usr/local/bin/php artisan queue:work database --queue=default,notifications,webhooks --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
```

### 8.2 Database Queue Concurrency, Retry Policies & Dead Letter Retention
1. **Concurrency Protection**:
   Laravel's database queue driver reserves pending jobs using PostgreSQL atomic updates (`reserved_at = ... WHERE reserved_at IS NULL AND available_at <= ...`). Two overlapping cron workers cannot claim or execute the same job payload.
2. **Retry Policies & Backoff**:
   - High-priority jobs (e.g., `SendWhatsAppMessageJob`) define explicit `$tries = 3` and exponential backoff `[5, 15, 45]`.
   - Transient database or network timeouts are re-queued according to their individual backoff schedules.
3. **Dead Letter & Failed Job Retention**:
   - Jobs exceeding their retry budget are permanently recorded in the `failed_jobs` PostgreSQL table with exception stack trace, connection, queue name, and timestamp.
   - Operators can inspect failed jobs via `php artisan queue:failed` or retry them using `php artisan queue:retry <id>`.
4. **Performance Boundaries & Future Scaling**:
   - For the initial launch (zero active users up to several hundred daily orders), PostgreSQL database-backed queues provide sufficient throughput without the operational burden of running a Redis instance.
   - If message volume later exceeds hundreds of jobs per minute, switching to an external managed Redis cluster requires solely updating `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` in `.env` without altering any application business logic.

---

## 9. Supported Production Deployment Pathways

### 9.1 Where Scripts Run & Transfer Mechanism
- **Workstation / CI Runner Role**:
  - Compiles Flutter Web production release bundle (`flutter build web --release`).
  - Prepares Composer production dependencies if cPanel host lacks Composer or has low PHP memory limits.
- **cPanel Server Role**:
  - Executes deployment scripts via **cPanel Terminal** or **SSH unprivileged user**.
  - All commands execute with regular user privileges (`username`). **Zero root or sudo commands are required.**
- **File Transfer Mechanisms**:
  - **Option 1 (cPanel Git Version Control)**: Clone repository directly to `/home/username/sewaroeng_core`.
  - **Option 2 (Archive Upload)**: Upload release tarball/zip via cPanel File Manager or SFTP and extract into `/home/username/sewaroeng_core`.

### 9.2 Pathway A: Automated CLI / SSH Deployment Pipeline
When SSH access or cPanel Terminal is available:

```bash
# 1. Non-mutating preflight validation (checks database, extension, and runtime least-privilege)
bash deploy/preflight-production.sh

# 2. Dry-run simulation (Verifies plan without changing files or database)
bash deploy/deploy-production.sh --dry-run \
  --api-public-dir /home/username/sewaroeng_api_public \
  --web-public-dir /home/username/sewaroeng_web

# 3. Apply deployment (Executes pre-migration backup via migrator, applies migrations as sewaroeng_prod_migrator, syncs public roots, builds caches for sewaroeng_prod_app)
bash deploy/deploy-production.sh --apply \
  --api-public-dir /home/username/sewaroeng_api_public \
  --web-public-dir /home/username/sewaroeng_web \
  --migrator-password "YOUR_STRONG_MIGRATOR_SECRET"
```

*Note on Managed Cloud Backups*: If `pg_dump` is not installed on the cPanel server (e.g. host only has MySQL client), take a database snapshot via your cloud provider console (Neon/Supabase/AWS RDS) and add `--external-backup-confirmed` to the command.

### 9.3 Pathway B: Manual cPanel Deployment Protocol
If automated CLI execution is not preferred:

1. **Upload & Extract Code**:
   - Extract repository to `/home/username/sewaroeng_core`.
   - Set permissions: `chmod -R 775 storage bootstrap/cache`.
   - Protect environment: `chmod 600 /home/username/sewaroeng_core/.env`.
2. **Setup Document Roots**:
   - Copy `index.php` (with `$app->usePublicPath(__DIR__)`) and `.htaccess` into `/home/username/sewaroeng_api_public/`.
   - Upload compiled Flutter Web bundle into `/home/username/sewaroeng_web/`.
3. **Database Pre-Migration Backup**:
   - Take snapshot in cloud database console or run `pg_dump`.
4. **Execute Migrations & Optimize Caches (via cPanel Terminal)**:
   ```bash
   cd /home/username/sewaroeng_core
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
5. **Verify Probes**:
   - `curl -fsS https://api.sewaroeng.neuracakrawira.asia/up` (Expects HTTP 200)
   - `curl -fsS https://api.sewaroeng.neuracakrawira.asia/api/v1/health` (Expects HTTP 200 with `status: ok`)

---

## 10. Pre-Deployment, Health Check, Backup & Rollback Checklist

### 10.1 Pre-Deployment Verification Checklist
- [ ] **1. Domains Allocated**: `sewaroeng.neuracakrawira.asia` and `api.sewaroeng.neuracakrawira.asia` created in cPanel.
- [ ] **2. SSL Active**: AutoSSL has issued valid certificates for both domains.
- [ ] **3. Document Root Split**: Source code is inside `sewaroeng_core`, completely outside public web roots.
- [ ] **4. Database Engine**: Target database is confirmed PostgreSQL 16+ (NOT MySQL).
- [ ] **5. Extension `btree_gist`**: Installed in `sewaroeng_production`.
- [ ] **6. Role Privileges**: `sewaroeng_prod_app` role verified with `NOSUPERUSER NOBYPASSRLS`.
- [ ] **7. Redis Accessible**: Redis host reachable with authentication over TLS.
- [ ] **8. .env Invariants**: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.sewaroeng.neuracakrawira.asia`.
- [ ] **9. CORS Origin**: `CORS_ALLOWED_ORIGINS=https://sewaroeng.neuracakrawira.asia`.
- [ ] **10. Cron Jobs Registered**: Scheduler and queue worker cron jobs active in cPanel.

### 10.2 Database Backup Protocol
Before any deployment or update:
```bash
pg_dump -U sewaroeng_prod_migrator -h <DB_HOST> -p 5432 -Fc sewaroeng_production > /home/username/backups/production_backup_$(date +%Y%m%d_%H%M%S).dump
```

### 10.3 Emergency Rollback Procedure
If a production deployment causes fatal errors:
1. **Activate Maintenance Mode**:
   ```bash
   cd /home/username/sewaroeng_core && php artisan down --secret="emergency-access-key"
   ```
2. **Restore Previous Database Backup**:
   ```bash
   pg_restore -U sewaroeng_prod_migrator -h <DB_HOST> -p 5432 -d sewaroeng_production --clean --if-exists /home/username/backups/production_backup_<TIMESTAMP>.dump
   ```
3. **Revert Application Code**:
   - Git: `git checkout <PREVIOUS_COMMIT_TAG>`
   - Rebuild caches: `php artisan config:cache && php artisan route:cache && php artisan view:cache`
4. **Deactivate Maintenance Mode**:
   ```bash
   php artisan up
   ```

### 10.4 Post-Deployment Validation
- [ ] Liveness probe returns 200: `curl -I https://api.sewaroeng.neuracakrawira.asia/up`
- [ ] Readiness probe returns 200: `curl -I https://api.sewaroeng.neuracakrawira.asia/api/v1/health`
- [ ] Browser access: Open `https://sewaroeng.neuracakrawira.asia` in a clean browser session.
- [ ] Browser developer console: Confirm OPTIONS preflight requests return HTTP 200/204 without CORS errors.
- [ ] Verify authentication: Confirm login issues a valid Sanctum Bearer token and redirects to the dashboard.

---

## 11. Environment Parser Policy & Strict Rejection Classification

During deployment contract verification, the environment configuration parser was systematically compared against Laravel's runtime parser (`vlucas/phpdotenv` v5.6+). Results are classified into three explicit categories:

| Category | Definition | Scenarios in SeWaroeng | Behavioral Rationale |
|---|---|---|---|
| **`MATCH`** | Both Dotenv and deployment parser produce identical values or mutually reject invalid syntax. | • Quoted strings with spaces (`"..."`)<br>• Single-quoted strings (`'...'`)<br>• Inline comments with `#`<br>• Hashes inside quotes (`APP_KEY="..."`)<br>• Empty values (`EMPTY_VAR=`)<br>• Duplicate keys (last definition wins)<br>• Unquoted spaces (`APP_NAME=Cafe Resto` - **Mutual Rejection**) | Zero interpretation variance for valid project configurations. |
| **`STRICT REJECTION`** | The deployment parser deliberately rejects a format that `vlucas/phpdotenv` would accept, because deployment safety policy is intentionally stricter. | • **Multiline Quoted Values** (`APP_NAME="Line1\nLine2"`): Dotenv accepts; deployment parser rejects.<br>• **Unclosed Quotes at EOF**: Dotenv silently drops keys; deployment parser fails closed with syntax error.<br>• **Dynamic Variable Expansion (`${VAR}`) in Guard Keys**: Dotenv expands; deployment preflight strictly requires literal strings for `APP_ENV`, `DB_DATABASE`, `DB_PORT`. | **Preventing Silent Failure**: Unclosed quotes swallow subsequent lines, causing critical guard variables like `APP_ENV` to be lost. The single-line requirement guarantees deterministic evaluation without line-swallowing bugs. |
| **`MISMATCH`** | The two parsers give divergent interpretations for a format intended to be mutually supported. | **0 Occurrences Detected** | No unintended behavioral divergences exist. |

> [!NOTE]
> All automated tests in [`deploy/tests/run_parity_check.php`](file:///d:/A/SeWaroeng/deploy/tests/run_parity_check.php) and [`deploy/tests/test_dotenv_parity.sh`](file:///d:/A/SeWaroeng/deploy/tests/test_dotenv_parity.sh) report test cases using this exact three-category taxonomy.

