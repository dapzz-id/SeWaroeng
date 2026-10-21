# SeWaroeng — Reproducible Staging Deployment Toolkit

**Directory Location:** `deploy/`  
**Target Environment:** Linux (Ubuntu 22.04+ LTS / Debian 12+)  
**Runtime:** PHP 8.4 CLI & FPM, PostgreSQL 16+ (with `btree_gist`), Redis 7.x, Nginx 1.18+  
**Application Root:** `/var/www/sewaroeng`  

---

## Deployment & Verification Status Matrix

| Subsystem | Environment | Status | Verification Note |
|---|---|---|---|
| **Remote Staging Infrastructure** | Staging | `NOT DEPLOYED` | Awaiting VPS provisioning and DNS registration |
| **Production Infrastructure** | Production | `NOT DEPLOYED` | Planned targets documented; production inactive |
| **Domain: Planned Production API** | Production | `PLANNED` | `https://api.sewaroeng.neuracakrawira.asia` |
| **Domain: Planned Production Web** | Production | `PLANNED` | `https://sewaroeng.neuracakrawira.asia` |
| **Domain: Staging Hosts** | Staging | `UNSPECIFIED` | Required explicit configuration (`STAGING_API_HOST`, `STAGING_WEB_HOST`) |
| **Meta WhatsApp Business API** | Live Cloud | `NOT VERIFIED` | Mock tested locally; awaiting live Business credentials |
| **Midtrans Payment Gateway** | Live Provider | `NOT VERIFIED` | Mock & contract tested locally; awaiting live merchant keys |
| **Duitku Payment Gateway** | Live Provider | `NOT VERIFIED` | Signature verification tested; awaiting live merchant code |

---

## 1. Directory Layout

```
deploy/
├── preflight.sh                            # Non-mutating staging environment & dependency inspector
├── deploy-staging.sh                       # Guarded staging deployment pipeline (dry-run by default)
├── preflight-production.sh                 # Non-mutating production environment & dependency inspector
├── deploy-production.sh                    # Guarded production deployment pipeline for cPanel / VPS
├── validate-domains.sh                     # FQDN syntax, URI scheme, and isolation validator
├── render-nginx-config.sh                  # Safe Nginx vhost renderer and atomic installer
├── apache/
│   ├── api-vhost.conf.template             # Apache virtualhost template for API
│   └── web.htaccess                        # Flutter Web client-side routing & cache control
├── systemd/
│   ├── sewaroeng-worker.service            # Queue worker service (bounded backoff 5,15,45)
│   ├── sewaroeng-scheduler.service         # Laravel console scheduler oneshot service
│   └── sewaroeng-scheduler.timer           # 1-minute persistent systemd timer
├── nginx/
│   ├── templates/
│   │   ├── api.conf.template               # API & probe reverse proxy template (port 80 bootstrap + TLS)
│   │   └── web.conf.template               # Flutter Web SPA host template (port 80 bootstrap + TLS)
│   └── production/
│       ├── api.sewaroeng.neuracakrawira.asia.conf # Inactive production API template
│       └── sewaroeng.neuracakrawira.asia.conf     # Inactive production Flutter Web template
├── tests/
│   ├── test_cli_safety_regression.sh       # Comprehensive 36+ CLI guard regression test suite
│   ├── test_dotenv_parity.sh               # Environment parser parity suite
│   └── run_parity_check.php                # PHP Dotenv vs Bash parser parity probe
└── README.md                               # Operational toolkit documentation
```

---

## 2. Domain & Environment Isolation Architecture

### 2.1 Production Hostnames (Planned Targets)
The owner has defined the following exact planned production hosts:
- **Production Backend API:** `https://api.sewaroeng.neuracakrawira.asia`
- **Production Frontend:** `https://sewaroeng.neuracakrawira.asia`

These hostnames are **planned targets only**. No public DNS records, TLS certificates, or active cloud servers have been provisioned. Production configurations in `deploy/nginx/production/` and `scripts/setup-db-production.sql` are strictly **INACTIVE**.

### 2.2 Staging Hostnames (Explicit Configuration Required)
Staging hostnames have **not been selected yet**. The previous placeholders (`staging-api.sewaroeng.id` and `staging.sewaroeng.id`) are decommissioned.

Staging deployment scripts (`deploy/preflight.sh`, `deploy/deploy-staging.sh`, and `deploy/render-nginx-config.sh`) enforce fail-closed domain verification:
- Staging hostnames MUST be explicitly passed via `--api-host` and `--web-host` or environment variables `STAGING_API_HOST` and `STAGING_WEB_HOST`.
- If absent, deployment stops immediately with a clear error.
- Guessing or defaulting domains is strictly prohibited.
- Staging hostnames are validated against production domains to prevent cross-environment resource leakage.

```bash
# Validate domain configuration prior to deployment:
bash deploy/validate-domains.sh \
    --mode staging \
    --api-host <your-staging-api-host> \
    --web-host <your-staging-web-host> \
    --app-url https://<your-staging-api-host> \
    --flutter-api-url https://<your-staging-api-host>/api/v1
```

---

## 3. Database Isolation by Environment

To prevent cross-environment resource creation and accidental modifications, PostgreSQL provisioning is strictly split into dedicated, environment-specific scripts:

| Script | Target Database | Role | Permitted Environments |
|---|---|---|---|
| [`scripts/setup-db-staging.sql`](file:///d:/A/SeWaroeng/scripts/setup-db-staging.sql) | `sewaroeng_staging` | `sewaroeng_app` (`NOSUPERUSER NOBYPASSRLS`) | Staging VPS Only |
| [`scripts/setup-db-dev.sql`](file:///d:/A/SeWaroeng/scripts/setup-db-dev.sql) | `sewaroeng`, `sewaroeng_test` | `sewaroeng_app` | Local Dev & CI Workflows |
| [`scripts/setup-db-production.sql`](file:///d:/A/SeWaroeng/scripts/setup-db-production.sql) | `sewaroeng_production` | `sewaroeng_prod_app` | Production (INACTIVE TEMPLATE) |
| `scripts/setup-db.sql` | *None* | *None* | **DEPRECATED** (Raises SQL Exception) |

### 3.1 Staging Database Setup
```bash
# Execute staging database provisioning as PostgreSQL superuser on staging VPS:
sudo -u postgres psql -f /var/www/sewaroeng/scripts/setup-db-staging.sql

# Verify extension and least-privilege boundary:
sudo -u postgres psql -d sewaroeng_staging -c "SELECT extname FROM pg_extension WHERE extname = 'btree_gist';"
sudo -u postgres psql -c "SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'sewaroeng_app';"
# Expected: rolsuper = f, rolbypassrls = f
```

> **Security Invariants:**
> 1. Staging provisioning creates ONLY `sewaroeng_staging`. It never touches or accesses `sewaroeng` (dev) or `sewaroeng_production`.
> 2. The application role `sewaroeng_app` is granted `NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE` to uphold tenant Row-Level Security (RLS).
> 3. `btree_gist` is installed per-database to support overlapping shift and reservation exclusion constraints.

---

## 4. Preflight Safety Validation

The preflight script is **strictly read-only and non-mutating**. It inspects system requirements, PHP extensions, directory permissions, environment variables, PostgreSQL RLS role privileges, and Redis connectivity.

```bash
# 1. Full Preflight Check (Live Target Server):
bash deploy/preflight.sh

# 2. Strict Preflight (Forbids any skips - enforced during --apply):
bash deploy/preflight.sh --strict

# 3. Offline / Fixture Simulation (Skips network & PHP CLI checks):
bash deploy/preflight.sh \
    --env-file deploy/fixtures/staging.env.fixture \
    --skip-connectivity \
    --skip-php-check
```

### Preflight Evaluation Status Types:
- `FULL_PREFLIGHT_PASSED`: All local, database, Redis, and PHP runtime prerequisites verified without bypass. Environment is certified ready for `--apply`. Remote status remains `NOT DEPLOYED`.
- `SIMULATION_PASSED_WITH_SKIPS`: Local simulation or test fixture evaluation passed with bypassed checks. Output explicitly notes that skipped checks MUST be verified on the target server prior to live deployment.

---

## 5. Staging Deployment Pipeline & Safety Gates

The staging deployment script (`deploy/deploy-staging.sh`) enforces strict forward-only safety guards:
1. **Dry-Run by Default**: Invoking without `--apply` runs in non-mutating plan mode.
2. **Bypass Flag Prohibition on `--apply`**: `--skip-connectivity` and `--skip-php-check` are strictly rejected in `--apply` / `--execute` mode.
3. **Test Fixture Lockout**: `deploy/fixtures/staging.env.fixture` and any file containing fixture markers or dummy secrets are prohibited from authorizing live deployment.
4. **CLI Option Hardening**: Conflicting execution modes (`--dry-run` with `--apply`), conflicting duplicate options, missing argument values, and empty arguments are rejected immediately.
5. **Explicit Staging Domains Required**: Rejects execution if `--api-host` / `STAGING_API_HOST` or `--web-host` / `STAGING_WEB_HOST` are omitted.
6. **Domain Isolation Guard**: Rejects staging if hostnames match planned production hostnames (`api.sewaroeng.neuracakrawira.asia` or `sewaroeng.neuracakrawira.asia`).
7. **Environment Guard**: Rejects execution unless `APP_ENV=staging` and `APP_DEBUG=false`.
8. **Database Identity Guard**: Connects via PDO to confirm `current_database()` matches `sewaroeng_staging` and is strictly non-production.
9. **Fail-Closed Pre-Migration Dump**: Automatically creates `pg_dump -Fc` prior to schema migration.
10. **Forward-Only Migrations**: Only executes `php artisan migrate --force`. Destructive commands (`migrate:fresh`, `db:wipe`) are strictly forbidden.
11. **Graceful Worker Reload**: Dispatches `php artisan queue:restart` to signal background workers.

### Usage:

```bash
# 1. Preview actions and verify configuration (DRY-RUN):
bash deploy/deploy-staging.sh \
    --api-host <staging-api-fqdn> \
    --web-host <staging-web-fqdn>

# 2. Local Dry-Run Simulation with Fixture & Allowed Skips:
bash deploy/deploy-staging.sh \
    --dry-run \
    --env-file deploy/fixtures/staging.env.fixture \
    --skip-connectivity \
    --skip-php-check \
    --api-host <staging-api-fqdn> \
    --web-host <staging-web-fqdn>

# 3. Execute full deployment on staging host:
bash deploy/deploy-staging.sh --apply \
    --api-host <staging-api-fqdn> \
    --web-host <staging-web-fqdn>

# Optional Flags:
# --skip-frontend-build : Use pre-existing bundle in frontend/build/web (e.g. compiled via CI)
# --skip-backup         : Skip pre-migration pg_dump (CAUTION: Not recommended)
```

### Automated Safety Gate & CLI Regression Suite

A dedicated regression test script verifies that all safety guards, fixture lockouts, and isolation policies function correctly without requiring network access or server mutations:

```bash
bash deploy/tests/test_cli_safety_regression.sh
```

The regression suite tests 34 distinct scenarios across Category A (safe dry-runs and simulations) and Category B (mandatory rejections). It employs comprehensive spy stubs across network (`ssh`, `scp`, `rsync`, `curl`, `wget`), database (`pg_dump`, `psql`, `pg_restore`, `redis-cli`), application build (`composer`, `flutter`), runtime (`php`), and system management tools (`sudo`, `nginx`, `install`, `chown`, `chmod`, `systemctl`), asserting that no mutating tool is ever invoked during simulations or rejected tests.

---

## 6. Web Server Configuration & 5-Phase TLS Bootstrap (Nginx)

To prevent virtual host collisions (where API and Web configurations target the same destination), virtual host generation is managed via `deploy/render-nginx-config.sh`.

### 6.1 Distinct File Destinations
- **API Destination:** `/etc/nginx/sites-available/<api-host>.conf`
- **Web Destination:** `/etc/nginx/sites-available/<web-host>.conf`
- Destination collisions are prevented by automated assertions before any file is copied.

### 6.2 5-Phase Safe Bootstrap Sequence

#### Phase 1: Render & Install HTTP Bootstrap Configurations
Generates host-specific configuration files with Port 80 HTTP server blocks, ACME webroot verification passthroughs (`/.well-known/acme-challenge/`), and liveness probes. HTTPS blocks remain inactive to ensure Nginx can start before certificates exist on disk:
```bash
sudo bash deploy/render-nginx-config.sh \
    --api-host <staging-api-fqdn> \
    --web-host <staging-web-fqdn> \
    --install \
    --reload
```

#### Phase 2: Verify DNS & Reachability
Ensure public DNS records point to the server IP and port 80 is reachable from the public internet:
```bash
# Check DNS resolution:
dig +short <staging-api-fqdn>
dig +short <staging-web-fqdn>

# Verify HTTP reachability:
curl -I http://<staging-api-fqdn>/.well-known/acme-challenge/test
curl -I http://<staging-web-fqdn>/.well-known/acme-challenge/test
```

#### Phase 3: Issue Certificates via Certbot Webroot
Issue valid Let's Encrypt certificates using the webroot plugin without modifying existing Nginx virtual hosts:
```bash
# 1. Issue certificate for API:
sudo certbot certonly --webroot \
    -w /var/www/sewaroeng/backend/public \
    -d <staging-api-fqdn>

# 2. Issue certificate for Flutter Web:
sudo certbot certonly --webroot \
    -w /var/www/sewaroeng/frontend/build/web \
    -d <staging-web-fqdn>
```

#### Phase 4: Activate HTTPS Server Block
Uncomment the HTTPS server block in `/etc/nginx/sites-available/<api-host>.conf` and `/etc/nginx/sites-available/<web-host>.conf`, and test configuration:
```bash
# Test syntax:
sudo nginx -t

# Reload Nginx:
sudo systemctl reload nginx
```

#### Phase 5: Verify HTTPS Health Endpoints
```bash
curl -I https://<staging-api-fqdn>/up
curl -s https://<staging-api-fqdn>/api/v1/health | jq .
curl -I https://<staging-web-fqdn>/
```

---

## 7. Background Process Supervision (Systemd)

### 7.1 Queue Worker Unit (`sewaroeng-worker.service`)
```bash
sudo cp deploy/systemd/sewaroeng-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sewaroeng-worker.service
sudo systemctl status sewaroeng-worker.service
```

### 7.2 Scheduler Timer Units (`sewaroeng-scheduler.service` & `sewaroeng-scheduler.timer`)
```bash
sudo cp deploy/systemd/sewaroeng-scheduler.service /etc/systemd/system/
sudo cp deploy/systemd/sewaroeng-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sewaroeng-scheduler.timer
systemctl list-timers --all | grep sewaroeng
```

---

## 8. Incident Recovery & Rollback Protocol

1. **Redeploy Previous Git Commit**:
   ```bash
   cd /var/www/sewaroeng
   git checkout <previous-stable-tag-or-commit>
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
2. **Restore Database from Pre-Migration Binary Dump**:
   ```bash
   pg_restore -U sewaroeng_app -h 127.0.0.1 -d sewaroeng_staging --clean --if-exists /var/backups/sewaroeng/pre_migrate_<dump_file>.dump
   ```
3. **Restart Workers**:
   ```bash
   php artisan queue:restart
   sudo systemctl restart sewaroeng-worker.service
   ```
4. **Failed Job Recovery Rule**:
   Never use `php artisan queue:flush` in incident response. Use `php artisan queue:failed` to inspect exceptions and payload, verify third-party provider logs (Meta/Midtrans/Duitku) to avoid duplicate charges or messages, and purge resolved individual records with `php artisan queue:forget <uuid>`.

---

## 9. Protected CI/CD Manual Deployment

The workflow [`.github/workflows/deploy-staging.yml`](file:///d:/A/SeWaroeng/.github/workflows/deploy-staging.yml) enforces:
- **Manual Trigger**: Exclusively invoked via `workflow_dispatch`.
- **Explicit Hostname Inputs**: Requires operator to input `staging_api_host` and `staging_web_host`.
- **Domain Guard**: Automatically rejects any host targeting `neuracakrawira.asia` or production.
- **Protected Environment**: Requires `staging` environment review.
- **Strict Host Key Checking**: Enforces `STAGING_SSH_KNOWN_HOSTS` (`StrictHostKeyChecking=yes`).
- **Zero Secret Disclosure**: Sensitive secrets are never logged.
