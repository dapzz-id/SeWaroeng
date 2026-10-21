# SeWaroeng — Deployment Guide: cPanel (Apache) & Multi-Environment Web Server Architecture

**Document Status:** Production & Staging Architectural Reference (Sprint 12)  
**Target Environments:**
1. **cPanel / DirectAdmin Shared Hosting & Managed Cloud** (Apache 2.4, mod_rewrite, PHP 8.4)
2. **Dedicated / Virtual Private Server (VPS)** (Ubuntu 22.04+ / Debian 12+, Nginx / Apache 2.4, Systemd)  
**Security Classification:** Strict Defense-in-Depth (RLS Enforced, Zero Credential Exposure)  
**Date:** October 2026  

---

## 1. Executive Architecture Summary

SeWaroeng is built with an **API-first, framework-decoupled architecture**. The core Laravel backend and Flutter Web client are completely agnostic to the underlying HTTP reverse proxy (Nginx or Apache). Web server configuration, TLS termination, process supervision, and background queuing are strictly separated from core business domain logic.

### Core Architecture Invariants (Non-Negotiable)
1. **PostgreSQL 16+ & Row-Level Security (RLS)**: Multi-tenant data isolation is enforced at the database layer via PostgreSQL RLS policies and `btree_gist` exclusion constraints. **Under no circumstances should the database engine be downgraded to MySQL or have RLS disabled.**
2. **Stateless Bearer Token Authentication**: API requests authenticate via Laravel Sanctum Personal Access Tokens (`Authorization: Bearer <token>`). No cookie-based SPA session hijacking risks exist, and `supports_credentials => false` is enforced.
3. **Private Source Isolation**: Application source code, `.env`, `vendor/`, `storage/logs/`, and database migration files must **never be directly accessible from the public HTTP document root**.

---

## 2. Infrastructure & Hosting Compatibility Matrix

| Component | Minimum Requirement | VPS Status | cPanel / Shared Hosting Reality | Required Remediation for cPanel |
|---|---|---|---|---|
| **Web Server** | Nginx 1.18+ or Apache 2.4+ with `mod_rewrite`, `mod_headers`, `mod_proxy_fcgi` | Fully Supported (`deploy/nginx/` & `deploy/apache/`) | Standard on virtually all cPanel hosts | Enable `mod_rewrite` and `mod_headers` via `.htaccess`. |
| **PHP Runtime** | PHP >= 8.4.0 (CLI & FPM) | Installed via standard packages (Ondřej Surý PPA) | Available via cPanel "MultiPHP Manager" / "Select PHP Version" | Select PHP 8.4 and enable all required extensions. |
| **PHP Extensions** | `pdo_pgsql`, `pgsql`, `redis`, `mbstring`, `xml`, `ctype`, `iconv`, `intl`, `bcmath`, `curl`, `zip` | Managed via `apt install` | Supported on most cPanel providers via PHP Selector | Ensure `pdo_pgsql`, `pgsql`, and `redis` are checked/enabled in cPanel PHP extensions list. |
| **Database Engine** | **PostgreSQL 16+ with `btree_gist` and `NOSUPERUSER NOBYPASSRLS` role** | Native local PostgreSQL service or managed cluster | **Rarely provided** on standard shared cPanel (most hosts offer MySQL only) | **MANDATORY**: Connect to an external managed PostgreSQL instance (e.g. Neon, Supabase, AWS RDS, DigitalOcean Managed DB) via `DB_HOST`. |
| **Cache & Distributed Mutex** | Redis 7.x (or remote Redis cluster) | Native local `redis-server` service | Usually absent on shared hosting localhost | **MANDATORY**: Use an external managed Redis service (e.g. Upstash, Redis Cloud, AWS ElastiCache) configured in `.env`. |
| **Queue Worker Process** | Persistent daemon worker (`queue:work`) | Supervised via **systemd** (`sewaroeng-worker.service`) | Daemon background processes typically killed by host process monitors | **Option A**: Run cron-driven workers with `--stop-when-empty`.<br>**Option B**: Use an external worker VPS. |
| **Scheduler** | Cron runner executing every minute (`schedule:run`) | Supervised via **systemd timer** or root crontab | Native cPanel "Cron Jobs" interface | Add standard cPanel cron command. |
| **TLS / SSL** | Automated TLS 1.3 certificate | Certbot webroot / ACME | cPanel "AutoSSL" (Let's Encrypt / cPanel Sectigo) | Free 1-click issuance in cPanel SSL/TLS Status. |

---

## 3. cPanel Apache Deployment Architecture

### 3.1 Document Root Safety Strategy

#### Scenario A: Configurable Document Root (Recommended)
In modern cPanel (cPanel v92+):
1. Navigate to **cPanel -> Domains -> Domains**.
2. For the intended API domain/subdomain (e.g. `api.yourdomain.com`), set the **Document Root** explicitly to:
   ```
   /home/username/sewaroeng/backend/public
   ```
3. For the Flutter Web frontend domain (e.g. `app.yourdomain.com`), set the Document Root to:
   ```
   /home/username/sewaroeng/frontend/build/web
   ```
4. **Security Benefit**: The parent directory `/home/username/sewaroeng/` (containing `.env`, source code, tests, and storage) sits entirely outside the web-accessible directory.

---

#### Scenario B: Primary Domain with Locked `public_html` (Secure Split-Root Alternative)
If your cPanel plan strictly locks the main domain to `/home/username/public_html` and cannot be modified:

> [!CAUTION]
> **CRITICAL SECURITY WARNING**: NEVER upload the entire repository into `public_html/` and rely solely on an `.htaccess` rewrite to `/public`. If `.htaccess` fails or Apache override is disabled during maintenance, your `.env` database passwords and application logs will be directly downloadable over HTTP!

Follow the **Secure Split-Root Directory Structure**:
```
/home/username/
├── sewaroeng_core/                  <-- PRIVATE (Outside public_html)
│   ├── backend/
│   │   ├── app/
│   │   ├── bootstrap/
│   │   ├── config/
│   │   ├── storage/
│   │   ├── vendor/
│   │   └── .env                    <-- PROTECTED: Absolutely unreachable via web
│   └── frontend/
│       └── ...
└── public_html/                     <-- PUBLIC (Web Root)
    ├── .htaccess                    <-- Copied from backend/public/.htaccess
    ├── index.php                    <-- Modified bootstrap loader
    ├── favicon.ico
    └── robots.txt
```

**Step-by-step implementation for Scenario B:**
1. Place the backend project into `/home/username/sewaroeng_core/backend/`.
2. Move the contents of `/home/username/sewaroeng_core/backend/public/*` (including `.htaccess`) into `/home/username/public_html/`.
3. Edit `/home/username/public_html/index.php` to load autoload and bootstrap from the private directory:
   ```php
   <?php

   use Illuminate\Http\Request;

   define('LARAVEL_START', microtime(true));

   // Determine if the application is in maintenance mode...
   if (file_exists($maintenance = __DIR__.'/../sewaroeng_core/backend/storage/framework/maintenance.php')) {
       require $maintenance;
   }

   // Register the Composer autoloader...
   require __DIR__.'/../sewaroeng_core/backend/vendor/autoload.php';

   // Bootstrap Laravel and handle the request...
   (require_once __DIR__.'/../sewaroeng_core/backend/bootstrap/app.php')
       ->handleRequest(Request::capture());
   ```
4. Ensure public path resolution in `bootstrap/app.php` or `AppServiceProvider` points to `/home/username/public_html`.

---

### 3.2 Apache `.htaccess` Verification

#### Backend API (`backend/public/.htaccess`)
Verify that the `Authorization` header is preserved by Apache and forwarded to PHP-FPM:
```apache
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header (Crucial for Bearer Tokens)
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

#### Frontend Flutter Web (`frontend/build/web/.htaccess`)
Deploy the template from [`deploy/apache/web.htaccess`](file:///d:/A/SeWaroeng/deploy/apache/web.htaccess) into the Flutter Web root to support client-side SPA routing and asset caching:
- Unmatched paths fall back to `index.html`.
- `index.html`, `flutter_service_worker.js`, and `version.json` are marked `no-cache`.
- Static `.js`, `.wasm`, `.css`, and font assets are cached immutably for 1 year.

---

### 3.3 cPanel Cron Job Configuration

Navigate to **cPanel -> Cron Jobs** and register the required tasks:

#### 1. Laravel Scheduler (Mandatory)
Runs every minute to trigger WhatsApp booking reminders, outbox message retries, and subscription checks:
```bash
* * * * * cd /home/username/sewaroeng/backend && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

#### 2. Queue Worker Execution (Cron-Driven Worker Alternative)
Because shared cPanel does not allow persistent systemd background daemons, queue processing must be scheduled via cron using the `--stop-when-empty` and `--max-time` flags:
```bash
* * * * * cd /home/username/sewaroeng/backend && /usr/local/bin/php artisan queue:work redis --queue=default,notifications,webhooks --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
```
*Note*: This executes the queue worker every minute. The worker processes all pending jobs and exits cleanly before the next cron trigger, avoiding process accumulation.

---

## 4. VPS Deployment Architecture (Nginx & Systemd)

For high-throughput production environments, VPS deployment remains the primary reference architecture:
- **Reverse Proxy**: Nginx with HTTP-01 ACME challenge pass-through and TLS 1.3 termination ([`deploy/nginx/templates/api.conf.template`](file:///d:/A/SeWaroeng/deploy/nginx/templates/api.conf.template)).
- **Configuration Renderer**: [`deploy/render-nginx-config.sh`](file:///d:/A/SeWaroeng/deploy/render-nginx-config.sh) for distinct destination file rendering.
- **Process Supervision**:
  - Worker: [`deploy/systemd/sewaroeng-worker.service`](file:///d:/A/SeWaroeng/deploy/systemd/sewaroeng-worker.service) running `php artisan queue:work redis`.
  - Scheduler: [`deploy/systemd/sewaroeng-scheduler.timer`](file:///d:/A/SeWaroeng/deploy/systemd/sewaroeng-scheduler.timer) triggering `sewaroeng-scheduler.service` every minute.
- **Automated Pipeline**: [`deploy/deploy-staging.sh`](file:///d:/A/SeWaroeng/deploy/deploy-staging.sh) with pre-migration binary backups and forward-only schema migrations.

---

## 5. Feature Availability by Hosting Environment

| Feature | Shared cPanel (Default) | Shared cPanel + Managed DB & Redis | Dedicated VPS (Ubuntu 22.04+) |
|---|---|---|---|
| POS Sales & Offline Sync | ❌ (No RLS) | ✅ Fully Functional | ✅ Fully Functional |
| Multi-Tenant Isolation (RLS) | ❌ (PostgreSQL missing) | ✅ Fully Functional | ✅ Fully Functional |
| Shift Management & Audits | ❌ (No `btree_gist`) | ✅ Fully Functional | ✅ Fully Functional |
| Payment Gateways (Midtrans/Duitku) | ⚠️ Sync Webhooks only | ✅ Asynchronous Webhooks | ✅ Asynchronous Webhooks |
| WhatsApp Outbox & Notifications | ⚠️ Cron-delayed (up to 1m) | ⚠️ Cron-delayed (up to 1m) | ⚡ Real-Time Instant Workers |
| Financial Reporting & Exports | ⚠️ Limited by PHP timeout | ⚠️ Limited by PHP timeout | ✅ Handled via background jobs |
| Live Webhooks Response | ⚠️ Worker latency | ⚡ Immediate 200 OK + Queue | ⚡ Immediate 200 OK + Queue |

---

## 6. Pre-Deployment Verification Checklist

Before deploying SeWaroeng to either cPanel or VPS:

- [ ] **1. Database Engine**: Verified PostgreSQL 16+ is running and accessible (NOT MySQL).
- [ ] **2. Extension `btree_gist`**: Installed in the target database (`CREATE EXTENSION IF NOT EXISTS btree_gist;`).
- [ ] **3. Application Role Privileges**: Verified `DB_USERNAME` has `NOSUPERUSER NOBYPASSRLS`.
- [ ] **4. Cache & Queue Connection**: Verified Redis connectivity (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`).
- [ ] **5. Environment Settings**:
  - `APP_ENV=production` (or `staging`)
  - `APP_DEBUG=false`
  - `APP_URL=https://<api-domain>`
  - `CORS_ALLOWED_ORIGINS=https://<web-domain>`
- [ ] **6. Document Root Isolation**: Verified web server document root points to `backend/public` (or secure split-root), with `.env` unreachable from the browser.
- [ ] **7. Authorization Header**: Verified Apache forwards `Authorization` header (`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).
- [ ] **8. Scheduler Cron**: Verified crontab / systemd timer triggers `php artisan schedule:run` every minute.
- [ ] **9. Queue Worker**: Verified worker runs via systemd service (VPS) or cron with `--stop-when-empty` (cPanel).
- [ ] **10. Flutter Web Build**: Compiled with explicit `--dart-define=APP_ENV=...` and `--dart-define=API_BASE_URL=https://<api-domain>/api/v1`.

---

## 7. Explicit Deployment Profiles & Architecture Comparison

### Profile A: cPanel Apache (Self-Contained Shared Hosting)
- **Use Case**: Low-overhead deployment where cPanel server includes PostgreSQL (or add-on PG) and mod_rewrite.
- **Web Server**: Apache 2.4 with `.htaccess` (no root VirtualHost needed; uses `.htaccess` rewrite rules and `SetEnvIf Authorization`).
- **Web Static**: Flutter Web bundle served directly via [`deploy/apache/web.htaccess`](file:///d:/A/SeWaroeng/deploy/apache/web.htaccess).
- **Process Management**: Cron-driven queue processing (`queue:work --stop-when-empty`) and Laravel scheduler (`schedule:run`) via cPanel Cron Jobs.
- **Security Boundary**: Enforced via *Secure Split-Root Directory Structure* if document root is locked to `public_html`.

### Profile B: Dedicated VPS Nginx (High-Performance Multi-Core)
- **Use Case**: Primary production and dedicated staging deployment.
- **Web Server**: Nginx with PHP 8.4-FPM socket, HTTP-01 Let's Encrypt challenge pass-through, and TLS 1.3 termination.
- **Web Static**: Flutter Web served directly with client-side SPA routing (`try_files $uri $uri/ /index.html`) and immutable asset caching.
- **Process Management**: Persistent **systemd** services (`sewaroeng-worker.service` and `sewaroeng-scheduler.timer`).
- **Security Boundary**: Strict file permissions (`www-data:www-data`), discrete virtual host files rendered via [`deploy/render-nginx-config.sh`](file:///d:/A/SeWaroeng/deploy/render-nginx-config.sh).

### Profile C: Shared cPanel + External Managed Services (Hybrid Cloud)
- **Use Case**: cPanel shared hosting that lacks local PostgreSQL 16+ or Redis.
- **Architecture**:
  - Web Server & PHP Application: Hosted on cPanel Apache.
  - Database: External Managed PostgreSQL (Neon, Supabase, AWS RDS, DigitalOcean) with `btree_gist` and `NOSUPERUSER NOBYPASSRLS` role.
  - Cache & Mutex: External Managed Redis (Upstash, Redis Cloud, ElastiCache) via TLS connection (`rediss://`).
  - Workers: Cron-driven workers on cPanel or dedicated micro-VPS worker worker process.
- **Guarantees**: Maintains 100% of SeWaroeng's security invariants (PostgreSQL RLS, Bearer Token auth, idempotency) without modifying application core code.

---

## 8. Safe Local Deployment Simulation Protocol

To verify deployment scripts without accessing remote servers or changing DNS:

```bash
# 1. Negative Preflight Test (Ensures unconfigured local .env is rejected):
bash deploy/deploy-staging.sh --dry-run \
  --api-host staging-api.test-fixture.internal \
  --web-host staging.test-fixture.internal
# Expected: Exits code 1 with specific failure messages for APP_ENV=local, APP_DEBUG=true, CORS_ALLOWED_ORIGINS empty.

# 2. Positive Staging Fixture Test (Ensures valid staging config passes all 42 checks):
bash deploy/deploy-staging.sh --dry-run \
  --env-file deploy/fixtures/staging.env.fixture \
  --skip-php-check \
  --skip-connectivity \
  --api-host staging-api.test-fixture.internal \
  --web-host staging.test-fixture.internal
# Expected: Exits code 0 with "PREFLIGHT CHECK PASSED" and "DRY-RUN DEPLOYMENT PLAN".

# 3. Domain Isolation Test (Ensures staging subdomains on same parent domain pass, but exact prod hosts fail):
# Subdomain under parent domain (PASS):
bash deploy/validate-domains.sh --mode staging \
  --api-host staging-api.neuracakrawira.asia \
  --web-host staging.neuracakrawira.asia
# Actual production host (FAIL):
bash deploy/validate-domains.sh --mode staging \
  --api-host api.sewaroeng.neuracakrawira.asia \
  --web-host staging.example.com
```
