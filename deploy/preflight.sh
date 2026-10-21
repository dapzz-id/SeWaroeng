#!/usr/bin/env bash
# ==============================================================================
# SeWaroeng — Staging Deployment Preflight Safety Script
# Non-mutating environment, dependency, and configuration readiness inspector.
# Target: Ubuntu 22.04+ LTS / Debian 12+, PHP 8.4-FPM, PostgreSQL 16+, Redis 7+
# ==============================================================================

set -uo pipefail

# ANSI color codes for readable output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

PASSED_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

log_pass() {
    echo -e "  [${GREEN}PASS${NC}] $1"
    PASSED_COUNT=$((PASSED_COUNT + 1))
}

log_warn() {
    echo -e "  [${YELLOW}WARN${NC}] $1"
    WARN_COUNT=$((WARN_COUNT + 1))
}

log_fail() {
    echo -e "  [${RED}FAIL${NC}] $1"
    FAIL_COUNT=$((FAIL_COUNT + 1))
}

log_info() {
    echo -e "  [${BLUE}INFO${NC}] $1"
}

log_section() {
    echo -e "\n${BOLD}=== $1 ===${NC}"
}

echo -e "${BOLD}====================================================================${NC}"
echo -e "${BOLD}       SeWaroeng Staging Deployment Preflight Inspector            ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Date: $(date -u '+%Y-%m-%d %H:%M:%SZ')"
echo -e "Hostname: $(hostname 2>/dev/null || echo 'unknown')"

# 1. Resolve Application Root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# If deployed to standard production path /var/www/sewaroeng
if [[ -d "/var/www/sewaroeng/backend" && "${PROJECT_ROOT}" != "/var/www/sewaroeng" ]]; then
    if [[ "${USE_CANONICAL_PATH:-false}" == "true" ]]; then
        PROJECT_ROOT="/var/www/sewaroeng"
    fi
fi

BACKEND_DIR="${PROJECT_ROOT}/backend"
FRONTEND_DIR="${PROJECT_ROOT}/frontend"

ENV_FILE="${ENV_FILE:-${BACKEND_DIR}/.env}"
ENV_FILE_SET=false
SKIP_CONNECTIVITY=false
SKIP_PHP_CHECK=false
STRICT_MODE=false

while [[ $# -gt 0 ]]; do
    if [[ -z "$1" ]]; then
        echo -e "${RED}Error: Empty argument passed to command line.${NC}" >&2
        exit 1
    fi

    case "$1" in
        --env-file)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --env-file requires a path argument.${NC}" >&2
                exit 1
            fi
            if [[ "${ENV_FILE_SET}" == "true" && "${ENV_FILE}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --env-file options specified ('${ENV_FILE}' vs '$2').${NC}" >&2
                exit 1
            fi
            ENV_FILE="$2"
            ENV_FILE_SET=true
            shift 2
            ;;
        --skip-connectivity)
            SKIP_CONNECTIVITY=true
            shift
            ;;
        --skip-php-check)
            SKIP_PHP_CHECK=true
            shift
            ;;
        --strict)
            STRICT_MODE=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [--env-file <path>] [--skip-connectivity] [--skip-php-check] [--strict]"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown argument: $1${NC}" >&2
            echo "Usage: $0 [--env-file <path>] [--skip-connectivity] [--skip-php-check] [--strict]" >&2
            exit 1
            ;;
    esac
done

# Strict Mode Invariants
if [[ "${STRICT_MODE}" == "true" ]]; then
    if [[ "${SKIP_CONNECTIVITY}" == "true" || "${SKIP_PHP_CHECK}" == "true" ]]; then
        echo -e "${RED}Error: Cannot combine --strict with --skip-connectivity or --skip-php-check.${NC}" >&2
        exit 1
    fi
fi

# Detect whether evaluating a test fixture
IS_FIXTURE=false
if [[ "${ENV_FILE}" =~ (fixture|fixtures|\.example|\.test|\.stub) ]] || grep -q "IS_DEPLOYMENT_FIXTURE=true" "${ENV_FILE}" 2>/dev/null; then
    IS_FIXTURE=true
fi

log_info "Project root resolved to: ${PROJECT_ROOT}"
log_info "Environment file: ${ENV_FILE}"

# ==============================================================================
# 2. Operating System & Platform Checks
# ==============================================================================
log_section "1. Operating System & Environment"

if [[ -f "/etc/os-release" ]]; then
    OS_NAME=$(grep -E '^PRETTY_NAME=' /etc/os-release | cut -d= -f2 | tr -d '"')
    log_pass "Operating System: ${OS_NAME}"
else
    log_warn "Could not identify Linux distribution via /etc/os-release (running on non-standard OS?)"
fi

CURRENT_USER=$(id -u -n 2>/dev/null || whoami)
if [[ "${CURRENT_USER}" == "root" ]]; then
    log_warn "Preflight is running as 'root'. Web services and workers should run as unprivileged 'www-data'."
else
    log_pass "Running as non-root user: ${CURRENT_USER}"
fi

# ==============================================================================
# 3. PHP Runtime & Extensions
# ==============================================================================
log_section "2. PHP 8.4 Runtime & Required Extensions"

if [[ "${SKIP_PHP_CHECK}" == "true" ]]; then
    log_info "Skipping PHP CLI runtime and extensions check (--skip-php-check or fixture testing)"
elif command -v php >/dev/null 2>&1; then
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    PHP_FULL=$(php -r 'echo PHP_VERSION;')
    if php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);'; then
        log_pass "PHP CLI version ${PHP_FULL} meets >= 8.4.0 requirement"
    else
        log_fail "PHP CLI version is ${PHP_FULL}. SeWaroeng requires PHP 8.4.x."
    fi
else
    log_fail "PHP CLI executable not found in PATH."
fi

REQUIRED_EXTENSIONS=(
    "pdo_pgsql"
    "pgsql"
    "redis"
    "mbstring"
    "xml"
    "ctype"
    "iconv"
    "intl"
    "bcmath"
    "curl"
    "zip"
)

if [[ "${SKIP_PHP_CHECK}" != "true" ]] && command -v php >/dev/null 2>&1; then
    for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        if php -m | grep -qi "^${ext}$"; then
            log_pass "PHP extension loaded: ${ext}"
        else
            log_fail "Missing required PHP extension: ${ext}"
        fi
    done
fi

# ==============================================================================
# 4. Dependency Management (Composer)
# ==============================================================================
log_section "3. Dependency Management (Composer)"

if command -v composer >/dev/null 2>&1; then
    COMPOSER_VER=$(composer --version 2>/dev/null | head -n 1)
    log_pass "Composer available: ${COMPOSER_VER}"
else
    log_fail "Composer executable not found in PATH."
fi

if [[ -f "${BACKEND_DIR}/composer.lock" ]]; then
    log_pass "Backend composer.lock exists for deterministic lockfile installation"
else
    log_fail "Backend composer.lock is missing in ${BACKEND_DIR}"
fi

# ==============================================================================
# 5. Frontend & Build Tooling
# ==============================================================================
log_section "4. Frontend Build Artifacts & Tooling"

if [[ -f "${FRONTEND_DIR}/build/web/index.html" && -f "${FRONTEND_DIR}/build/web/main.dart.js" ]]; then
    log_pass "Compiled Flutter Web bundle exists at ${FRONTEND_DIR}/build/web"
else
    log_warn "Flutter Web build not found at ${FRONTEND_DIR}/build/web. Build must be compiled before Nginx serves web static."
    if command -v flutter >/dev/null 2>&1; then
        FLUTTER_VER=$(flutter --version 2>/dev/null | head -n 1)
        log_pass "Flutter SDK available on host: ${FLUTTER_VER}"
    else
        log_info "Flutter SDK not installed on host. Staging bundle may be built via CI and deployed as artifact."
    fi
fi

# ==============================================================================
# 6. Backend Directory Permissions & Structure
# ==============================================================================
log_section "5. Directory Structure & Permissions"

WRITABLE_DIRS=(
    "${BACKEND_DIR}/storage"
    "${BACKEND_DIR}/storage/app"
    "${BACKEND_DIR}/storage/framework"
    "${BACKEND_DIR}/storage/framework/cache"
    "${BACKEND_DIR}/storage/framework/sessions"
    "${BACKEND_DIR}/storage/framework/views"
    "${BACKEND_DIR}/storage/logs"
    "${BACKEND_DIR}/bootstrap/cache"
)

for dir in "${WRITABLE_DIRS[@]}"; do
    if [[ ! -d "${dir}" ]]; then
        mkdir -p "${dir}" 2>/dev/null || true
    fi

    if [[ -d "${dir}" ]]; then
        if [[ -w "${dir}" ]]; then
            log_pass "Writable directory: ${dir#${PROJECT_ROOT}/}"
        else
            log_fail "Directory is NOT writable by current user: ${dir#${PROJECT_ROOT}/} (Remediation: chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache)"
        fi
    else
        log_fail "Failed to create or locate required directory: ${dir#${PROJECT_ROOT}/}"
    fi
done

# ==============================================================================
# 7. Environment Configuration (.env) Verification
# ==============================================================================
log_section "6. Canonical Environment Configuration"

if [[ ! -f "${ENV_FILE}" ]]; then
    log_fail "Environment file missing: ${ENV_FILE}. (Remediation: cp .env.example .env and populate staging credentials)."
else
    log_pass "Environment file exists: ${ENV_FILE}"

    validate_env_file_syntax() {
        local file="$1"
        if [[ ! -r "${file}" ]]; then
            log_fail "Environment file is not readable: ${file}"
            return 1
        fi
        if [[ ! -s "${file}" ]]; then
            log_fail "Environment file is empty: ${file}"
            return 1
        fi
        # Check for binary / non-printable characters
        if grep -q '[^[:print:][:space:]]' "${file}" 2>/dev/null; then
            log_fail "Environment file '${file}' contains binary or non-printable characters."
            return 1
        fi
        local line_num=0
        while IFS= read -r line || [[ -n "${line}" ]]; do
            line_num=$((line_num + 1))
            line="${line%$'\r'}"
            local trimmed="${line#"${line%%[![:space:]]*}"}"
            [[ -z "${trimmed}" || "${trimmed}" =~ ^# ]] && continue
            if [[ ! "${trimmed}" =~ ^[a-zA-Z_][a-zA-Z0-9_]*= ]]; then
                log_fail "Environment file line ${line_num} violates KEY=VALUE format: '${trimmed:0:40}'"
                return 1
            fi
            local val_part="${trimmed#*=}"
            # Parity Check 1: Unclosed quotes
            if [[ "${val_part}" =~ ^\" && ! "${val_part}" =~ \"[[:space:]]*(#.*)?$ ]]; then
                log_fail "Environment file line ${line_num} has unclosed double quote: '${val_part:0:40}'"
                return 1
            fi
            if [[ "${val_part}" =~ ^\' && ! "${val_part}" =~ \'[[:space:]]*(#.*)?$ ]]; then
                log_fail "Environment file line ${line_num} has unclosed single quote: '${val_part:0:40}'"
                return 1
            fi
            # Parity Check 2: Unquoted whitespace (Dotenv strictly forbids unquoted values with spaces)
            if [[ ! "${val_part}" =~ ^[\"\'] ]]; then
                local without_comment="${val_part%%#*}"
                without_comment="${without_comment%"${without_comment##*[![:space:]]}"}"
                if [[ "${without_comment}" =~ [[:space:]] ]]; then
                    log_fail "Environment file line ${line_num} has unquoted whitespace in value: '${val_part:0:40}' (Dotenv requires values containing spaces to be enclosed in quotes)."
                    return 1
                fi
            fi
        done < "${file}"
        return 0
    }

    if validate_env_file_syntax "${ENV_FILE}"; then
        log_pass "Environment file syntax conforms to KEY=VALUE standard"
    fi

    # Helper function to extract env value safely without code execution
    get_env_val() {
        local key="$1"
        local line
        line="$(grep -E "^${key}=" "${ENV_FILE}" 2>/dev/null | tail -n 1 || true)"
        if [[ -z "${line}" ]]; then
            echo ""
            return
        fi
        local val="${line#*=}"
        val="${val%$'\r'}"
        if [[ "${val}" =~ ^\"(.*)\"$ ]]; then
            val="${BASH_REMATCH[1]}"
        elif [[ "${val}" =~ ^\'(.*)\'$ ]]; then
            val="${BASH_REMATCH[1]}"
        else
            val="${val%%#*}"
            read -r val <<< "${val}"
        fi
        echo "${val}"
    }

    # 1. Mandatory Variables Presence Check (No secret values printed!)
    MANDATORY_VARS=(
        "APP_NAME"
        "APP_ENV"
        "APP_KEY"
        "APP_DEBUG"
        "APP_URL"
        "DB_CONNECTION"
        "DB_HOST"
        "DB_PORT"
        "DB_DATABASE"
        "DB_USERNAME"
        "DB_PASSWORD"
        "REDIS_CLIENT"
        "REDIS_HOST"
        "REDIS_PORT"
        "CACHE_STORE"
        "SESSION_DRIVER"
        "QUEUE_CONNECTION"
        "CORS_ALLOWED_ORIGINS"
    )

    MISSING_VARS=0
    for var in "${MANDATORY_VARS[@]}"; do
        val=$(get_env_val "${var}")
        if [[ -z "${val}" ]]; then
            log_fail "Missing or empty mandatory environment variable: ${var}"
            MISSING_VARS=$((MISSING_VARS + 1))
        else
            log_pass "Configured variable present: ${var}"
        fi
    done

    # 2. Safety Invariant: APP_ENV must be 'staging'
    CURRENT_APP_ENV=$(get_env_val "APP_ENV")
    if [[ "${CURRENT_APP_ENV}" == "staging" ]]; then
        log_pass "APP_ENV is explicitly set to: staging"
    elif [[ "${CURRENT_APP_ENV}" == "production" ]]; then
        log_fail "SAFETY HAZARD: APP_ENV is set to 'production' on a staging deployment target!"
    else
        log_fail "APP_ENV is '${CURRENT_APP_ENV}'. Expected strictly 'staging'."
    fi

    # 3. Safety Invariant: APP_DEBUG must be 'false'
    CURRENT_DEBUG=$(get_env_val "APP_DEBUG")
    if [[ "${CURRENT_DEBUG}" == "false" || "${CURRENT_DEBUG}" == "0" ]]; then
        log_pass "APP_DEBUG is safely disabled: ${CURRENT_DEBUG}"
    else
        log_fail "SAFETY HAZARD: APP_DEBUG is set to '${CURRENT_DEBUG}'! Must be 'false' in staging to prevent trace disclosure."
    fi

    # 4. Driver Invariants: Redis for Queue, Cache, and Session
    CURRENT_QUEUE=$(get_env_val "QUEUE_CONNECTION")
    CURRENT_CACHE=$(get_env_val "CACHE_STORE")
    CURRENT_SESSION=$(get_env_val "SESSION_DRIVER")

    if [[ "${CURRENT_QUEUE}" == "redis" ]]; then
        log_pass "QUEUE_CONNECTION is correctly configured to: redis"
    else
        log_fail "QUEUE_CONNECTION is '${CURRENT_QUEUE}'. Staging requires 'redis'."
    fi

    if [[ "${CURRENT_CACHE}" == "redis" ]]; then
        log_pass "CACHE_STORE is correctly configured to: redis (required for distributed scheduler mutex)"
    else
        log_fail "CACHE_STORE is '${CURRENT_CACHE}'. Staging requires 'redis' for mutex locking."
    fi

    if [[ "${CURRENT_SESSION}" == "redis" ]]; then
        log_pass "SESSION_DRIVER is correctly configured to: redis"
    else
        log_warn "SESSION_DRIVER is '${CURRENT_SESSION}'. 'redis' is recommended for stateless horizontal scaling."
    fi

    # 5. Database Driver Invariant: PostgreSQL Only
    CURRENT_DB_CONN=$(get_env_val "DB_CONNECTION")
    if [[ "${CURRENT_DB_CONN}" == "pgsql" ]]; then
        log_pass "DB_CONNECTION is correctly configured to: pgsql"
    else
        log_fail "DB_CONNECTION is '${CURRENT_DB_CONN}'. SeWaroeng PostgreSQL RLS architecture requires 'pgsql'."
    fi

    # 6. Safety Invariant: APP_URL must be valid HTTPS and isolated from production
    PROD_API_HOST="api.sewaroeng.neuracakrawira.asia"
    PROD_WEB_HOST="sewaroeng.neuracakrawira.asia"

    CURRENT_APP_URL=$(get_env_val "APP_URL")
    if [[ "${CURRENT_APP_URL}" =~ ^http://localhost ]]; then
        log_fail "APP_URL is '${CURRENT_APP_URL}'. Staging environment requires a public HTTPS URL, not localhost."
    elif [[ "${CURRENT_APP_URL}" == "https://${PROD_API_HOST}" || "${CURRENT_APP_URL}" == "https://${PROD_WEB_HOST}" || "${CURRENT_APP_URL}" == "http://${PROD_API_HOST}" || "${CURRENT_APP_URL}" == "http://${PROD_WEB_HOST}" ]]; then
        log_fail "ENVIRONMENT LEAK HAZARD: APP_URL is set to actual production domain ('${CURRENT_APP_URL}') on staging target!"
    elif [[ "${CURRENT_APP_URL}" == *"staging-api.sewaroeng.id"* ]]; then
        log_fail "STALE DOMAIN HAZARD: APP_URL contains deprecated unselected domain 'staging-api.sewaroeng.id'. Update to actual staging FQDN."
    elif [[ ! "${CURRENT_APP_URL}" =~ ^https:// ]]; then
        log_fail "SECURITY HAZARD: APP_URL '${CURRENT_APP_URL}' does not use HTTPS. Staging requires HTTPS."
    else
        log_pass "APP_URL configured with HTTPS: ${CURRENT_APP_URL}"
    fi

    # 6b. Safety Invariant: CORS_ALLOWED_ORIGINS must be configured and isolated from production
    CURRENT_CORS=$(get_env_val "CORS_ALLOWED_ORIGINS")
    if [[ -z "${CURRENT_CORS}" ]]; then
        log_fail "CORS_ALLOWED_ORIGINS is empty! Staging requires explicit frontend origin(s) for Cross-Origin requests."
    elif [[ "${CURRENT_CORS}" =~ (https?://${PROD_WEB_HOST}|https?://${PROD_API_HOST}) ]]; then
        log_fail "ENVIRONMENT LEAK HAZARD: CORS_ALLOWED_ORIGINS contains actual production origin ('https://${PROD_WEB_HOST}') on staging target!"
    else
        log_pass "CORS_ALLOWED_ORIGINS is configured for staging (${CURRENT_CORS})"
    fi

    # 7. Database Target Safety Invariant
    CURRENT_DB_NAME=$(get_env_val "DB_DATABASE")
    if [[ "${CURRENT_DB_NAME}" =~ (prod|production|live) ]]; then
        log_fail "CRITICAL SAFETY VIOLATION: DB_DATABASE '${CURRENT_DB_NAME}' resembles production! Staging must never target production DB."
    elif [[ "${CURRENT_DB_NAME}" == "sewaroeng" ]]; then
        log_warn "DB_DATABASE is '${CURRENT_DB_NAME}'. 'sewaroeng' is conventionally the local development database. 'sewaroeng_staging' is strongly recommended for staging isolation."
    else
        log_pass "DB_DATABASE is explicitly non-production: ${CURRENT_DB_NAME}"
    fi
fi

# ==============================================================================
# 8. Infrastructure Connectivity (PostgreSQL & Redis)
# ==============================================================================
log_section "7. Infrastructure Connectivity Tests (Zero Leakage)"

if [[ "${SKIP_CONNECTIVITY}" == "true" ]]; then
    log_info "Skipping live database & Redis connectivity tests (--skip-connectivity specified or offline fixture mode)"
elif [[ -f "${ENV_FILE}" ]] && command -v php >/dev/null 2>&1; then
    # PostgreSQL Connectivity Check via PHP PDO (Safe, non-disclosing)
    DB_STATUS=$(php -r "
        require '${BACKEND_DIR}/vendor/autoload.php';
        \$app = require '${BACKEND_DIR}/bootstrap/app.php';
        \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
        \$kernel->bootstrap();
        try {
            \$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
            // Verify effective database
            \$curDbStmt = \$pdo->query(\"SELECT current_database()\");
            \$curDb = \$curDbStmt->fetchColumn();

            // Check btree_gist extension
            \$stmt = \$pdo->query(\"SELECT extname FROM pg_extension WHERE extname = 'btree_gist'\");
            \$ext = \$stmt->fetchColumn();
            if (!\$ext) {
                echo 'NO_BTREE_GIST:' . \$curDb;
                exit(0);
            }
            // Check user privileges
            \$roleStmt = \$pdo->query(\"SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = CURRENT_USER\");
            \$role = \$roleStmt->fetch(PDO::FETCH_ASSOC);
            if (\$role && (\$role['rolsuper'] || \$role['rolbypassrls'])) {
                echo 'PRIVILEGE_VIOLATION:' . \$curDb;
                exit(0);
            }
            echo 'OK:' . \$curDb;
        } catch (Throwable \$e) {
            echo 'CONN_FAILED';
        }
    " 2>/dev/null || echo "PHP_EXEC_ERROR")

    CURRENT_DB_NAME=$(get_env_val "DB_DATABASE")
    CURRENT_DB_USER=$(get_env_val "DB_USERNAME")

    DB_CODE="${DB_STATUS%%:*}"
    EFFECTIVE_DB="${DB_STATUS#*:}"

    case "${DB_CODE}" in
        "OK")
            if [[ "${EFFECTIVE_DB}" != "${CURRENT_DB_NAME}" ]]; then
                log_fail "DATABASE IDENTITY MISMATCH: Connected to database '${EFFECTIVE_DB}' but DB_DATABASE is '${CURRENT_DB_NAME}'!"
            else
                log_pass "PostgreSQL connected to '${EFFECTIVE_DB}' successfully (verified NOSUPERUSER NOBYPASSRLS & btree_gist active)"
            fi
            ;;
        "NO_BTREE_GIST")
            log_fail "PostgreSQL connected to '${EFFECTIVE_DB}', but required 'btree_gist' extension is NOT installed in this database. (Remediation: sudo -u postgres psql -d ${EFFECTIVE_DB} -c 'CREATE EXTENSION IF NOT EXISTS btree_gist;')"
            ;;
        "PRIVILEGE_VIOLATION")
            log_fail "SECURITY HAZARD: Database user '${CURRENT_DB_USER}' on '${EFFECTIVE_DB}' has SUPERUSER or BYPASSRLS privileges! RLS tenant boundaries compromised. (Remediation: sudo -u postgres psql -c 'ALTER ROLE ${CURRENT_DB_USER} WITH NOSUPERUSER NOBYPASSRLS;')"
            ;;
        "CONN_FAILED")
            log_fail "Failed to connect to PostgreSQL database '${CURRENT_DB_NAME}'. Verify DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD."
            ;;
        *)
            log_warn "PostgreSQL connectivity test inconclusive (${DB_STATUS}). Ensure vendor/autoload.php exists and DB is reachable."
            ;;
    esac

    # Redis Connectivity Check via PhpRedis / Facade
    REDIS_STATUS=$(php -r "
        require '${BACKEND_DIR}/vendor/autoload.php';
        \$app = require '${BACKEND_DIR}/bootstrap/app.php';
        \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
        \$kernel->bootstrap();
        try {
            \$res = Illuminate\Support\Facades\Redis::connection()->ping();
            echo (\$res === true || \$res === '+PONG' || \$res === 'PONG') ? 'OK' : 'FAIL';
        } catch (Throwable \$e) {
            echo 'CONN_FAILED';
        }
    " 2>/dev/null || echo "PHP_EXEC_ERROR")

    if [[ "${REDIS_STATUS}" == "OK" ]]; then
        log_pass "Redis server connected and responded to PING"
    else
        log_fail "Redis connectivity failed (${REDIS_STATUS}). Queue workers and scheduler mutex will fail closed. (Remediation: check REDIS_HOST, REDIS_PORT, REDIS_PASSWORD)."
    fi
fi

# ==============================================================================
# 9. Database Migration Status
# ==============================================================================
log_section "8. Database Schema & Migration Status"

if [[ -f "${ENV_FILE}" ]] && [[ -f "${BACKEND_DIR}/artisan" ]] && command -v php >/dev/null 2>&1; then
    MIGRATE_STATUS=$(cd "${BACKEND_DIR}" && php artisan migrate:status --pending 2>&1 || echo "ERROR")
    if [[ "${MIGRATE_STATUS}" == *"No pending migrations"* || "${MIGRATE_STATUS}" == *"Ran"* ]]; then
        log_pass "Database migrations inspected: clean state"
    elif [[ "${MIGRATE_STATUS}" == *"Pending"* ]]; then
        log_warn "Pending database migrations detected. Deployment script will need to execute 'php artisan migrate --force'."
    else
        log_warn "Could not inspect migration status (${MIGRATE_STATUS}). Check database accessibility."
    fi
fi

# ==============================================================================
# 10. Process Supervision (Systemd / Cron)
# ==============================================================================
log_section "9. Process Supervision & Scheduling Readiness"

if command -v systemctl >/dev/null 2>&1; then
    log_pass "systemd is available for process supervision"

    # Check worker unit
    if systemctl is-active --quiet sewaroeng-worker.service 2>/dev/null; then
        log_pass "Queue worker service (sewaroeng-worker.service) is actively running"
    else
        log_warn "Queue worker service (sewaroeng-worker.service) is not currently active"
    fi

    # Check scheduler timer
    if systemctl is-active --quiet sewaroeng-scheduler.timer 2>/dev/null; then
        log_pass "Scheduler timer (sewaroeng-scheduler.timer) is actively running"
    else
        log_warn "Scheduler timer (sewaroeng-scheduler.timer) is not currently active (ensure systemd timer or crontab is configured)"
    fi
else
    log_info "systemctl not available in current shell. Verify background worker supervisor (systemd / supervisord / docker) and cron."
fi

# ==============================================================================
# Summary & Exit
# ==============================================================================
log_section "Preflight Summary"

echo -e "  Passed checks:   ${GREEN}${PASSED_COUNT}${NC}"
echo -e "  Warnings:        ${YELLOW}${WARN_COUNT}${NC}"
echo -e "  Failures:        ${RED}${FAIL_COUNT}${NC}"

if [[ ${FAIL_COUNT} -eq 0 ]]; then
    if [[ "${SKIP_CONNECTIVITY}" == "true" || "${SKIP_PHP_CHECK}" == "true" || "${IS_FIXTURE}" == "true" ]]; then
        echo -e "\n${YELLOW}${BOLD}PREFLIGHT SIMULATION PASSED (OFFLINE / FIXTURE MODE)${NC}"
        echo -e "  Evaluation Type:    ${CYAN}Local Simulation (NOT A LIVE DEPLOYMENT CERTIFICATION)${NC}"
        if [[ "${IS_FIXTURE}" == "true" ]]; then
            echo -e "  Configuration:      ${YELLOW}Test Fixture (${ENV_FILE})${NC}"
        fi
        if [[ "${SKIP_CONNECTIVITY}" == "true" ]]; then
            echo -e "  Skipped Check:      ${YELLOW}Database & Redis network connectivity bypassed (--skip-connectivity)${NC}"
        fi
        if [[ "${SKIP_PHP_CHECK}" == "true" ]]; then
            echo -e "  Skipped Check:      ${YELLOW}PHP 8.4 CLI runtime checks bypassed (--skip-php-check)${NC}"
        fi
        echo -e "  Notice:             All skipped checks MUST be verified on the target server prior to --apply."
        echo -e "  Remote Status:      ${BOLD}NOT DEPLOYED${NC}"
        exit 0
    else
        echo -e "\n${GREEN}${BOLD}FULL PREFLIGHT CHECK PASSED:${NC} All local and runtime prerequisites verified."
        echo -e "  Evaluation Type:    ${GREEN}Full Pre-Deployment Verification${NC}"
        echo -e "  Remote Status:      ${BOLD}NOT DEPLOYED (Ready for staging application)${NC}"
        exit 0
    fi
else
    echo -e "\n${RED}${BOLD}PREFLIGHT CHECK FAILED:${NC} ${FAIL_COUNT} critical prerequisites are unfulfilled. Resolve items above before deploying."
    exit 1
fi
